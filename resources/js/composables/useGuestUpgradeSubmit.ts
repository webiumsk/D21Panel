import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useAuthStore, type EmailChallengeSummary } from "../store/auth";
import { useFlashStore } from "../store/flash";
import api from "../services/api";
import { isGuestUpgradeEmailOnly } from "../config/auth";

/**
 * Guest -> Free upgrade in two steps against the 6-digit email code API:
 * submit() stages the address (server emails the code, guest row untouched),
 * confirm() applies the upgrade, resend() rotates the code. The staged
 * challenge also arrives in /api/user as `pending_email_challenge`, so a
 * reload lands straight on the code step.
 */
export function useGuestUpgradeSubmit() {
  const { t } = useI18n();
  const authStore = useAuthStore();
  const flashStore = useFlashStore();
  // The server is authoritative (its validation decides whether password is
  // required); the build-time env vars are only a fallback for older payloads.
  const emailOnly = computed(() => {
    const server = authStore.user?.guest_upgrade_email_only;
    if (typeof server === "boolean") return server;
    return isGuestUpgradeEmailOnly();
  });

  const loading = ref(false);
  const privacyConsent = ref(false);
  const termsAccepted = ref(false);
  const form = ref({
    email: "",
    password: "",
    password_confirmation: "",
  });
  const challenge = ref<EmailChallengeSummary | null>(
    authStore.user?.pending_email_challenge ?? null,
  );

  const canSubmit = computed(
    () => privacyConsent.value && termsAccepted.value,
  );

  function resetSensitiveFields() {
    form.value.password = "";
    form.value.password_confirmation = "";
    privacyConsent.value = false;
    termsAccepted.value = false;
  }

  function resetForm() {
    form.value = { email: "", password: "", password_confirmation: "" };
    resetSensitiveFields();
  }

  /** Step 1: request the code. Resolves true when the code step should open. */
  async function submit(): Promise<boolean> {
    if (!canSubmit.value) return false;
    loading.value = true;
    try {
      const payload: Record<string, unknown> = {
        method: "email",
        email: form.value.email,
        privacy_consent: privacyConsent.value,
        terms_accepted: termsAccepted.value,
      };
      if (!emailOnly.value) {
        payload.password = form.value.password;
        payload.password_confirmation = form.value.password_confirmation;
      }

      const response = await api.post("/user/guest/upgrade/request", payload);
      challenge.value = (response?.data?.challenge as EmailChallengeSummary) ?? null;
      if (authStore.user) {
        authStore.user = { ...authStore.user, pending_email_challenge: challenge.value };
      }
      resetSensitiveFields();
      return challenge.value !== null;
    } catch (e: unknown) {
      const err = e as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } };
      };
      const firstError = Object.values(err?.response?.data?.errors ?? {})[0]?.[0];
      flashStore.error(
        firstError || err?.response?.data?.message || t("account.guest_upgrade_failed"),
      );
      return false;
    } finally {
      loading.value = false;
    }
  }

  /** Step 2: confirm the code; throws so the code component can map the error. */
  async function confirm(code: string): Promise<void> {
    let response;
    try {
      response = await api.post("/user/guest/upgrade/confirm", { code });
    } catch (e: unknown) {
      const err = e as { response?: { data?: { errors?: { email?: string[] } } } };
      const emailError = err?.response?.data?.errors?.email?.[0];
      if (emailError) {
        // Address got taken between request and confirm: the server burnt the
        // challenge, so drop the code step and ask for another address. Still
        // a failure - the caller must not treat it as a finished upgrade.
        discardChallenge();
        flashStore.error(emailError);
      }
      throw e;
    }
    challenge.value = null;
    if (response?.data?.user) {
      authStore.user = { ...response.data.user, pending_email_challenge: null };
    } else {
      await authStore.fetchUser();
    }
    flashStore.success(t("account.pending_email_verified"));
  }

  async function resend(): Promise<EmailChallengeSummary | null> {
    const response = await api.post("/user/guest/upgrade/resend");
    challenge.value = (response?.data?.challenge as EmailChallengeSummary) ?? challenge.value;
    if (authStore.user) {
      authStore.user = { ...authStore.user, pending_email_challenge: challenge.value };
    }
    return challenge.value;
  }

  /** "Use a different address": drop the local step; the next request supersedes the server row. */
  function discardChallenge() {
    challenge.value = null;
    if (authStore.user) {
      authStore.user = { ...authStore.user, pending_email_challenge: null };
    }
  }

  return {
    emailOnly,
    loading,
    privacyConsent,
    termsAccepted,
    form,
    challenge,
    canSubmit,
    resetForm,
    resetSensitiveFields,
    submit,
    confirm,
    resend,
    discardChallenge,
  };
}
