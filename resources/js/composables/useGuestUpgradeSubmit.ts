import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "../store/auth";
import { useFlashStore } from "../store/flash";
import api from "../services/api";
import { isGuestUpgradeEmailOnly } from "../config/auth";

function redirectToEmailVerification(): void {
  window.location.assign("/account/check-email");
}

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

      const response = await api.put("/user/guest/upgrade", payload);
      if (response?.data?.user) {
        authStore.user = response.data.user;
      } else {
        await authStore.fetchUser();
      }
      resetSensitiveFields();
      redirectToEmailVerification();
      return true;
    } catch (e: unknown) {
      const err = e as { response?: { data?: { message?: string } } };
      flashStore.error(
        err?.response?.data?.message || t("account.guest_upgrade_failed"),
      );
      return false;
    } finally {
      loading.value = false;
    }
  }

  return {
    emailOnly,
    loading,
    privacyConsent,
    termsAccepted,
    form,
    canSubmit,
    resetForm,
    resetSensitiveFields,
    submit,
  };
}
