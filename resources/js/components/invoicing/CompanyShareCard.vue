<template>
  <section class="invoicing-card invoicing-card-pad mb-4" data-testid="company-share-card">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold text-gray-900">{{ t('invoicing.company_share_title') }}</h2>
        <p class="mt-1 text-sm text-gray-600 max-w-2xl">
          <template v-if="status === 'active'">
            {{ t(role === 'owner' ? 'invoicing.company_share_status_owner' : 'invoicing.company_share_status_member') }}
          </template>
          <template v-else-if="status === 'migrating'">{{ t('invoicing.company_share_status_migrating') }}</template>
          <template v-else>{{ t('invoicing.company_share_status_private') }}</template>
        </p>
      </div>
      <span
        class="rounded-full px-2.5 py-1 text-xs font-medium"
        :class="status === 'active' ? 'bg-emerald-100 text-emerald-800' : status === 'migrating' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700'"
      >
        {{ t(`invoicing.company_share_badge_${status}`) }}
      </span>
    </div>

    <template v-if="status === 'private'">
      <div v-if="!confirming" class="mt-4">
        <button type="button" class="invoicing-btn-secondary" @click="confirming = true">
          {{ t('invoicing.company_share_convert') }}
        </button>
      </div>
      <div v-else class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 space-y-3 text-sm text-amber-950">
        <p class="font-medium">{{ t('invoicing.company_share_confirm_title') }}</p>
        <ul class="list-disc pl-5 space-y-1 text-amber-900">
          <li>{{ t('invoicing.company_share_confirm_point_copy') }}</li>
          <li>{{ t('invoicing.company_share_confirm_point_secret') }}</li>
          <li>{{ t('invoicing.company_share_confirm_point_revoke') }}</li>
        </ul>
        <p>{{ t('invoicing.company_share_confirm_backup') }}</p>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="invoicing-btn-secondary text-sm" :disabled="backingUp" @click="downloadBackup">
            {{ backingUp ? t('common.loading') : t('invoicing.company_share_backup_button') }}
          </button>
          <button type="button" class="invoicing-btn-primary text-sm" :disabled="running" @click="convert">
            {{ running ? progressLabel : t('invoicing.company_share_confirm_button') }}
          </button>
          <button type="button" class="invoicing-btn-secondary text-sm" :disabled="running" @click="confirming = false">
            {{ t('common.cancel') }}
          </button>
        </div>
      </div>
    </template>

    <p v-if="running && status !== 'private'" class="mt-3 text-xs text-gray-500">{{ progressLabel }}</p>
    <p v-if="resultMessage" class="mt-3 text-sm" :class="resultError ? 'text-red-600' : 'text-emerald-700'">{{ resultMessage }}</p>
    <CompanyInvitesPanel v-if="status === 'active' && role === 'owner'" :company-id="companyId" />
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useQuery } from '@evolu/vue';
import { allCompanySharesQuery, useInvoicingEvolu } from '@/evolu/client';
import { convertCompanyToShared, type ShareMigrationProgress } from '@/evolu/companyShareMigration';
import { exportInvoicingBackup } from '@/evolu/invoicingBackup';
import { toAppRows } from '@/evolu/queryLoad';
import CompanyInvitesPanel from './CompanyInvitesPanel.vue';

/**
 * "Zdieľanie firmy" (docs/COMPANY_SHARING.md, C3): shows whether the company
 * is private / being converted / shared and lets the owner convert it. The
 * share state is read reactively from the `companyShare` table (own
 * partition only), so a second tab or device reflects it too. Invites (C4)
 * and member management (C5) come later.
 */
const props = defineProps<{ companyId: string }>();

const { t } = useI18n();
const evolu = useInvoicingEvolu();

type ShareRow = { companyId: string; ownerId: string | null; role: string; status: string };
const shareRows = useQuery(allCompanySharesQuery);
const appOwnerId = ref<string | null>(null);
void evolu.appOwner.then((owner) => {
  appOwnerId.value = owner.id;
});

const share = computed(() =>
  toAppRows<ShareRow>(shareRows.value).find(
    (row) => row.companyId === props.companyId && row.status !== 'revoked' && (appOwnerId.value === null || row.ownerId === appOwnerId.value),
  ) ?? null,
);
const status = computed<'private' | 'migrating' | 'active'>(() =>
  share.value?.status === 'active' ? 'active' : share.value?.status === 'migrating' ? 'migrating' : 'private',
);
const role = computed(() => share.value?.role ?? 'owner');

const confirming = ref(false);
const running = ref(false);
const backingUp = ref(false);
const progress = ref<ShareMigrationProgress | null>(null);
const resultMessage = ref('');
const resultError = ref(false);

const progressLabel = computed(() => {
  const p = progress.value;
  if (!p) return t('invoicing.company_share_progress_prepare');
  if (p.phase === 'copy') return t('invoicing.company_share_progress_copy', { done: p.done, total: p.total });
  return t(`invoicing.company_share_progress_${p.phase}`);
});

async function downloadBackup(): Promise<void> {
  backingUp.value = true;
  try {
    await exportInvoicingBackup(evolu, appOwnerId.value);
  } catch {
    resultError.value = true;
    resultMessage.value = t('common.error_generic');
  } finally {
    backingUp.value = false;
  }
}

async function convert(): Promise<void> {
  running.value = true;
  resultMessage.value = '';
  resultError.value = false;
  try {
    const result = await convertCompanyToShared(evolu, props.companyId, {
      onProgress: (p) => {
        progress.value = p;
      },
    });
    if (result.ok) {
      resultMessage.value = t('invoicing.company_share_done', { copied: result.copied });
      confirming.value = false;
    } else {
      resultError.value = true;
      resultMessage.value = t(`invoicing.company_share_error_${result.error}`);
    }
  } catch (error) {
    resultError.value = true;
    resultMessage.value = error instanceof Error ? error.message : t('common.error_generic');
  } finally {
    running.value = false;
  }
}
</script>
