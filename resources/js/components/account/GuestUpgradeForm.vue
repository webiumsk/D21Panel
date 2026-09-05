<template>
  <div v-if="challenge" class="space-y-3">
    <p v-if="introFeatureLabel" class="text-sm text-gray-300">
      {{ t('account.guest_upgrade_modal_intro', { feature: introFeatureLabel }) }}
    </p>
    <EmailCodeVerification
      :challenge="challenge"
      :confirm="onConfirm"
      :resend="resend"
      @change-email="discardChallenge"
    />
  </div>
  <form v-else class="space-y-3" @submit.prevent="onSubmit">
    <p v-if="introFeatureLabel" class="text-sm text-gray-300">
      {{ t('account.guest_upgrade_modal_intro', { feature: introFeatureLabel }) }}
    </p>
    <p v-else-if="showDefaultDesc" class="text-xs text-gray-300">
      {{ t('account.guest_upgrade_desc_email_only') }}
    </p>
    <input
      v-model="form.email"
      type="email"
      required
      autocomplete="email"
      class="w-full px-3 py-2 border border-gray-600 rounded-lg text-white bg-gray-900/70 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
      :placeholder="t('account.guest_upgrade_email_placeholder')"
    />
    <template v-if="!emailOnly">
      <input
        v-model="form.password"
        type="password"
        required
        autocomplete="new-password"
        class="w-full px-3 py-2 border border-gray-600 rounded-lg text-white bg-gray-900/70 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
        :placeholder="t('account.new_password')"
      />
      <input
        v-model="form.password_confirmation"
        type="password"
        required
        autocomplete="new-password"
        class="w-full px-3 py-2 border border-gray-600 rounded-lg text-white bg-gray-900/70 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
        :placeholder="t('account.confirm_new_password')"
      />
    </template>
    <p
      class="text-xs text-amber-200/90 leading-relaxed rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2"
      role="note"
    >
      {{ t('account.guest_upgrade_email_verify_notice') }}
    </p>

    <label class="flex items-start gap-3 cursor-pointer text-sm text-gray-300">
      <input
        :id="`${idPrefix}-terms`"
        v-model="termsAccepted"
        type="checkbox"
        class="mt-1 h-4 w-4 rounded border-gray-600 bg-gray-700 text-indigo-600 focus:ring-indigo-500"
        required
      />
      <span>
        <i18n-t keypath="legal.consent.terms" tag="span">
          <template #termsLink>
            <a
              href="/legal/terms"
              target="_blank"
              rel="noopener noreferrer"
              class="text-indigo-400 hover:text-indigo-300 underline"
            >{{ t('legal.nav.terms') }}</a>
          </template>
        </i18n-t>
      </span>
    </label>

    <label class="flex items-start gap-3 cursor-pointer text-sm text-gray-300">
      <input
        :id="`${idPrefix}-privacy`"
        v-model="privacyConsent"
        type="checkbox"
        class="mt-1 h-4 w-4 rounded border-gray-600 bg-gray-700 text-indigo-600 focus:ring-indigo-500"
        required
      />
      <span>
        <i18n-t keypath="legal.consent.privacy" tag="span">
          <template #privacyLink>
            <a
              href="/legal/privacy"
              target="_blank"
              rel="noopener noreferrer"
              class="text-indigo-400 hover:text-indigo-300 underline"
            >{{ t('legal.nav.privacy') }}</a>
          </template>
        </i18n-t>
      </span>
    </label>

    <button
      type="submit"
      :disabled="loading || !canSubmit"
      class="w-full py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold disabled:opacity-50"
    >
      {{ loading ? t('auth.saving') : t('account.guest_upgrade_email_submit') }}
    </button>
  </form>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useGuestUpgradeSubmit } from '../../composables/useGuestUpgradeSubmit';
import EmailCodeVerification from './EmailCodeVerification.vue';

const props = withDefaults(
  defineProps<{
    featureLabelKey?: string | null;
    showDefaultDesc?: boolean;
    idPrefix?: string;
  }>(),
  {
    featureLabelKey: null,
    showDefaultDesc: true,
    idPrefix: 'guest-upgrade',
  },
);

const emit = defineEmits<{
  /** Code requested - the code step is now showing. */
  'code-sent': [];
  /** Code confirmed - the account is upgraded. */
  success: [];
}>();

const { t, te } = useI18n();
const {
  emailOnly,
  loading,
  privacyConsent,
  termsAccepted,
  form,
  challenge,
  canSubmit,
  submit,
  confirm,
  resend,
  discardChallenge,
} = useGuestUpgradeSubmit();

const introFeatureLabel = computed(() => {
  if (!props.featureLabelKey) return null;
  return te(props.featureLabelKey) ? t(props.featureLabelKey) : props.featureLabelKey;
});

async function onSubmit() {
  const ok = await submit();
  if (ok) {
    emit('code-sent');
  }
}

async function onConfirm(code: string) {
  await confirm(code);
  emit('success');
}
</script>
