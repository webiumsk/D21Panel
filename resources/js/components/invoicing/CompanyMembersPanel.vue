<template>
  <div class="mt-4 border-t border-gray-100 pt-4" data-testid="company-members-panel">
    <h3 class="text-sm font-semibold text-gray-900">{{ t('invoicing.company_members_title') }}</h3>
    <p class="mt-1 text-xs text-gray-500 max-w-2xl">{{ t('invoicing.company_members_intro') }}</p>

    <p v-if="loading" class="mt-3 text-sm text-gray-500">{{ t('common.loading') }}</p>

    <ul v-else-if="members.length" class="mt-3 divide-y divide-gray-100 rounded-lg border border-gray-100">
      <li v-for="m in members" :key="m.id" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
        <span class="min-w-0">
          <span class="font-medium text-gray-900">{{ m.name || m.email || t('invoicing.company_members_unknown') }}</span>
          <span class="ml-2 text-xs text-gray-500">{{ t(`invoicing.company_invite_role_${m.role}`) }}<template v-if="m.email && m.name"> · {{ m.email }}</template></span>
        </span>
        <button type="button" class="text-xs text-red-600 hover:underline disabled:opacity-50" :disabled="busy" @click="confirmRevoke(m)">
          {{ t('invoicing.company_members_revoke') }}
        </button>
      </li>
    </ul>

    <p v-else class="mt-3 text-sm text-gray-500">{{ t('invoicing.company_members_empty') }}</p>

    <div v-if="pendingRevoke" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm space-y-2 text-amber-950">
      <p class="font-medium">{{ t('invoicing.company_members_revoke_confirm', { name: pendingRevoke.name || pendingRevoke.email || t('invoicing.company_members_unknown') }) }}</p>
      <p class="text-xs text-amber-900">{{ t('invoicing.company_members_revoke_warning') }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="invoicing-btn-primary text-sm" :disabled="busy" @click="revoke">{{ t('invoicing.company_members_revoke') }}</button>
        <button type="button" class="invoicing-btn-secondary text-sm" :disabled="busy" @click="pendingRevoke = null">{{ t('common.cancel') }}</button>
      </div>
    </div>

    <p v-if="errorMsg" class="mt-2 text-sm text-red-600">{{ errorMsg }}</p>

    <div class="mt-4 border-t border-gray-100 pt-4">
      <h4 class="text-sm font-semibold text-gray-900">{{ t('invoicing.company_rekey_title') }}</h4>
      <p class="mt-1 text-xs text-gray-500 max-w-2xl">{{ t('invoicing.company_rekey_intro') }}</p>

      <div v-if="!confirmingRekey" class="mt-3">
        <button type="button" class="invoicing-btn-secondary text-sm" :disabled="rekeying" @click="confirmingRekey = true">
          {{ t('invoicing.company_rekey_button') }}
        </button>
      </div>
      <div v-else class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm space-y-2 text-amber-950">
        <p class="font-medium">{{ t('invoicing.company_rekey_confirm_title') }}</p>
        <ul class="list-disc pl-5 space-y-1 text-amber-900">
          <li>{{ t('invoicing.company_rekey_point_forward') }}</li>
          <li>{{ t('invoicing.company_rekey_point_reinvite', { count: members.length }) }}</li>
          <li>{{ t('invoicing.company_rekey_point_wait') }}</li>
        </ul>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="invoicing-btn-primary text-sm" :disabled="rekeying" @click="rekey">
            {{ rekeying ? t('invoicing.company_rekey_working') : t('invoicing.company_rekey_confirm_button') }}
          </button>
          <button type="button" class="invoicing-btn-secondary text-sm" :disabled="rekeying" @click="confirmingRekey = false">{{ t('common.cancel') }}</button>
        </div>
      </div>
      <p v-if="rekeyMessage" class="mt-2 text-sm" :class="rekeyError ? 'text-red-600' : 'text-emerald-700'">{{ rekeyMessage }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { invoicingApi, type CompanyMemberSummary } from '@/services/api';
import { useInvoicingEvolu } from '@/evolu/client';
import { rekeyCompanyShare } from '@/evolu/companyShareRekey';

/**
 * Owner-only member list + revoke for a shared company (docs/COMPANY_SHARING.md,
 * C5). Revoking is an immediate server-side lockout; the confirm step spells out
 * that already-synced data stays on the former member's device until re-key.
 */
const props = defineProps<{ companyId: string }>();
const { t } = useI18n();

const members = ref<CompanyMemberSummary[]>([]);
const loading = ref(true);
const busy = ref(false);
const errorMsg = ref('');
const pendingRevoke = ref<CompanyMemberSummary | null>(null);
const evolu = useInvoicingEvolu();
const confirmingRekey = ref(false);
const rekeying = ref(false);
const rekeyMessage = ref('');
const rekeyError = ref(false);

async function refresh(): Promise<void> {
  try {
    members.value = await invoicingApi.companies.listMembers(props.companyId);
  } catch {
    errorMsg.value = t('common.error');
  } finally {
    loading.value = false;
  }
}

function confirmRevoke(member: CompanyMemberSummary): void {
  errorMsg.value = '';
  pendingRevoke.value = member;
}

async function revoke(): Promise<void> {
  if (!pendingRevoke.value) return;
  busy.value = true;
  errorMsg.value = '';
  try {
    await invoicingApi.companies.revokeMember(props.companyId, pendingRevoke.value.id);
    pendingRevoke.value = null;
    await refresh();
  } catch {
    errorMsg.value = t('common.error');
  } finally {
    busy.value = false;
  }
}

async function rekey(): Promise<void> {
  rekeying.value = true;
  rekeyMessage.value = '';
  rekeyError.value = false;
  try {
    const result = await rekeyCompanyShare(evolu, props.companyId);
    if (result.ok) {
      confirmingRekey.value = false;
      rekeyMessage.value = members.value.length
        ? t('invoicing.company_rekey_done_reinvite', { count: members.value.length })
        : t('invoicing.company_rekey_done');
    } else {
      rekeyError.value = true;
      rekeyMessage.value = t(`invoicing.company_rekey_error_${result.error}`);
    }
  } catch (error) {
    rekeyError.value = true;
    rekeyMessage.value = error instanceof Error ? error.message : t('common.error');
  } finally {
    rekeying.value = false;
  }
}

onMounted(refresh);
</script>
