<template>
  <div class="mx-auto max-w-lg py-8" data-testid="invite-accept">
    <section class="invoicing-card invoicing-card-pad">
      <h1 class="text-lg font-semibold text-gray-900">{{ t('invoicing.invite_accept_title') }}</h1>

      <p v-if="loading" class="mt-4 text-sm text-gray-500">{{ t('common.loading') }}</p>

      <template v-else-if="preview">
        <p class="mt-3 text-sm text-gray-700">
          {{ t('invoicing.invite_accept_intro', { company: preview.company_name || t('invoicing.invite_accept_company_fallback'), inviter: preview.invited_by || '' }) }}
        </p>
        <p class="mt-1 text-sm text-gray-600">{{ t('invoicing.invite_accept_role', { role: t(`invoicing.company_invite_role_${preview.role}`) }) }}</p>

        <p v-if="secretError" class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ secretError }}</p>

        <div v-else class="mt-5 flex flex-wrap gap-2">
          <button type="button" class="invoicing-btn-primary text-sm" :disabled="working" @click="accept">
            {{ working ? t('invoicing.invite_accept_working') : t('invoicing.invite_accept_button') }}
          </button>
          <router-link to="/invoicing" class="invoicing-btn-secondary text-sm">{{ t('common.cancel') }}</router-link>
        </div>
      </template>

      <p v-else class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ errorMsg || t('invoicing.invite_accept_invalid') }}</p>
    </section>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { invoicingApi, type CompanyInvitePreview } from '@/services/api';
import { useInvoicingEvolu } from '@/evolu/client';
import { decryptSealedInvite, materializeAcceptedShare, type ShareRole } from '@/evolu/companyInviteAccept';

/**
 * Invitee-facing accept page (docs/COMPANY_SHARING.md, C4). Resolves the share
 * secret locally (decrypting the sealed blob with this account's recovery key,
 * or reading it from the link fragment), records membership on the server, and
 * materializes the local companyShare so the shared data starts syncing.
 */
const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const evolu = useInvoicingEvolu();

const token = String(route.params.token ?? '');
const loading = ref(true);
const working = ref(false);
const preview = ref<CompanyInvitePreview | null>(null);
const errorMsg = ref('');
const secretError = ref('');
const secretEncoded = ref('');

function fragmentSecret(): string | null {
  try {
    return new URLSearchParams(window.location.hash.slice(1)).get('s');
  } catch {
    return null;
  }
}

onMounted(async () => {
  try {
    preview.value = await invoicingApi.invites.preview(token);
    // Resolve the secret up front - never record membership we cannot back
    // with a usable local share.
    if (preview.value.mode === 'sealed' && preview.value.sealed_secret) {
      const opened = await decryptSealedInvite(preview.value.sealed_secret);
      if (!opened) secretError.value = t('invoicing.invite_accept_wrong_account');
      else secretEncoded.value = opened;
    } else if (preview.value.mode === 'link') {
      const frag = fragmentSecret();
      if (!frag) secretError.value = t('invoicing.invite_accept_link_missing_secret');
      else secretEncoded.value = frag;
    }
  } catch (error) {
    const status = (error as { response?: { status?: number } })?.response?.status;
    errorMsg.value = status === 403 ? t('invoicing.invite_accept_wrong_account') : t('invoicing.invite_accept_invalid');
  } finally {
    loading.value = false;
  }
});

async function accept(): Promise<void> {
  if (!preview.value || !secretEncoded.value) return;
  working.value = true;
  try {
    const { company_id, role } = await invoicingApi.invites.accept(token);
    const result = await materializeAcceptedShare(evolu, {
      secretEncoded: secretEncoded.value,
      role: role as ShareRole,
      bridgeCompanyId: company_id,
    });
    if (result.ok) {
      await router.push(`/invoicing/companies/${result.companyId}`);
    } else {
      secretError.value = t(`invoicing.invite_accept_error_${result.error}`);
    }
  } catch (error) {
    const status = (error as { response?: { status?: number } })?.response?.status;
    secretError.value = status === 404 ? t('invoicing.invite_accept_invalid') : t('common.error');
  } finally {
    working.value = false;
  }
}
</script>
