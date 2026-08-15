<template>
  <InvoicingPageShell
    :title="t('invoicing.title')"
    :subtitle="t('invoicing.subtitle')"
    :embedded="embedded"
  >
    <template #actions>
      <InvoicingRelaySyncStatusButton
        v-if="showRelaySyncStatusIcon && relaySyncStatusLevel"
        :level="relaySyncStatusLevel"
        :spinning="isRelaySyncing"
        :title-override="relaySyncStatusTitle"
        @click="openRelaySyncModal"
      />
      <button
        v-if="canUse && canCreateCompany"
        type="button"
        class="invoicing-btn-primary"
        :disabled="isRelaySyncing"
        :title="isRelaySyncing ? t('invoicing.relay_sync_wait_hint') : undefined"
        @click="onAddCompany"
      >
        {{ t('invoicing.add_company') }}
      </button>
      <p v-if="canUse && isRelaySyncing" class="text-xs text-amber-800 max-w-xs text-right">
        {{ t('invoicing.relay_sync_wait_hint') }}
      </p>
      <p v-else-if="canUse && !canCreateCompany && companyLimitMax" class="text-xs text-amber-800 max-w-xs text-right">
        {{ t('invoicing.company_limit_reached', { max: companyLimitMax }) }}
      </p>
    </template>

    <OfflineNoticeBanner v-if="localFirst && canUse" class="mb-4" />
    <LocalStorageWarningBanner v-if="localFirst && canUse" class="mb-4" />
    <BackupReminderBanner v-if="localFirst && canUse" class="mb-4" />

    <div v-if="localFirst && duplicateCompanyGroups.length" class="invoicing-alert-warn mb-4">
      <p class="text-sm font-medium">{{ t('invoicing.duplicate_companies_title') }}</p>
      <p class="text-sm mt-2 opacity-90">{{ t('invoicing.duplicate_companies_detail') }}</p>
      <ul class="text-sm mt-3 space-y-2 opacity-90">
        <li v-for="group in duplicateCompanyGroups" :key="group.key">
          <span class="font-medium">{{ group.label }}</span>
          <ul class="list-disc list-inside mt-1">
            <li v-for="company in group.companies" :key="company.id">
              {{ company.trade_name || company.legal_name }}
              · {{ company.documents_count ?? 0 }} {{ t('invoicing.invoices_short') }}
              · <code class="text-xs opacity-75">{{ company.id }}</code>
            </li>
          </ul>
        </li>
      </ul>
      <button
        type="button"
        class="invoicing-btn-primary mt-4"
        :disabled="mergeRunning || isRelaySyncing"
        @click="runMergeDuplicates"
      >
        {{
          mergeRunning
            ? t('invoicing.duplicate_companies_merging')
            : t('invoicing.duplicate_companies_merge')
        }}
      </button>
    </div>

    <div v-if="localFirst && showAttachmentMigration" class="rounded-lg border border-violet-200 bg-violet-50 px-4 py-4 mb-4">
      <p class="text-sm font-medium text-violet-950">{{ t('invoicing.server_migration_attachments_title') }}</p>
      <p class="text-sm text-violet-900 mt-2">{{ t('invoicing.server_migration_attachments_detail') }}</p>
      <p v-if="migrationStatus" class="text-xs text-violet-800 mt-2">
        {{ t('invoicing.server_migration_attachments_counts', {
          count: migrationStatus.attachments_on_server_count,
        }) }}
      </p>
      <button
        type="button"
        class="invoicing-btn-primary mt-3"
        :disabled="attachmentImporting || isRelaySyncing"
        @click="runAttachmentMigration"
      >
        {{ attachmentImporting ? t('invoicing.server_migration_attachments_importing') : t('invoicing.server_migration_attachments_import') }}
      </button>
    </div>

    <div v-if="localFirst && showServerMigration" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-4 mb-4">
      <p class="text-sm font-medium text-amber-950">{{ t('invoicing.server_migration_title') }}</p>
      <p class="text-sm text-amber-900 mt-2">{{ t('invoicing.server_migration_detail') }}</p>
      <p v-if="migrationStatus" class="text-xs text-amber-800 mt-2">
        {{ t('invoicing.server_migration_counts', {
          companies: migrationStatus.companies_count,
          documents: migrationStatus.documents_count,
          contacts: migrationStatus.contacts_count,
          expenses: migrationStatus.expenses_count,
        }) }}
      </p>
      <button
        type="button"
        class="invoicing-btn-primary mt-3"
        :disabled="migrationImporting || isRelaySyncing"
        @click="runServerMigration"
      >
        {{ migrationImporting ? t('invoicing.server_migration_importing') : t('invoicing.server_migration_import') }}
      </button>
    </div>

    <div v-if="!canUse" class="invoicing-alert-warn">
      <p class="font-medium">{{ t('invoicing.pro_required') }}</p>
      <p class="text-sm mt-2 opacity-90">{{ t('invoicing.pro_required_detail') }}</p>
      <button type="button" class="invoicing-link mt-3" @click="showUpgrade = true">
        {{ t('invoicing.upgrade_cta') }}
      </button>
    </div>

    <div v-else-if="loadError" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-8 text-center mb-4">
      <p class="text-sm font-medium text-amber-950">
        {{ multiTabBlocked ? t('invoicing.local_db_multitab_blocked_title') : t('invoicing.local_db_load_failed_title') }}
      </p>
      <p class="text-sm text-amber-900 mt-2 max-w-md mx-auto">
        {{ multiTabBlocked ? t('invoicing.local_db_multitab_blocked_detail') : t('invoicing.local_db_load_failed_detail') }}
      </p>
      <div class="flex flex-wrap items-center justify-center gap-3 mt-4">
        <button type="button" class="invoicing-btn-primary" @click="retryCompaniesLoad">
          {{ t('invoicing.local_db_retry') }}
        </button>
        <button type="button" class="invoicing-link text-sm font-medium" @click="reloadPage">
          {{ t('invoicing.evolu_reload_page') }}
        </button>
      </div>
    </div>

    <InvoicingLoadingState
      v-else-if="loading"
      :message="relaySyncLoading ? t('invoicing.relay_sync_loading') : t('common.loading')"
    />

    <div v-else-if="companyList.length === 0" class="invoicing-card-pad text-center">
      <p class="text-gray-700">{{ t('invoicing.no_companies') }}</p>
      <p v-if="localFirst && !showServerMigration" class="text-sm text-gray-500 mt-3 max-w-md mx-auto">
        {{ isRelaySyncing ? t('invoicing.relay_sync_empty_hint') : t('invoicing.no_companies_restore_hint') }}
      </p>
      <button
        v-if="canCreateCompany && !showServerMigration"
        type="button"
        class="invoicing-btn-primary mt-4"
        :disabled="isRelaySyncing"
        @click="onAddCompany"
      >
        {{ t('invoicing.create_first_company') }}
      </button>
    </div>

    <ul v-else class="space-y-3">
      <li v-for="c in companyList" :key="c.id">
        <button
          type="button"
          class="invoicing-list-item w-full text-left"
          @click="openCompany(c)"
        >
          <div>
            <p class="font-medium text-gray-900">{{ c.trade_name || c.legal_name }}</p>
            <p class="text-xs text-gray-500">{{ c.legal_name }}</p>
          </div>
          <span class="text-xs text-gray-500">{{ c.documents_count ?? 0 }} {{ t('invoicing.invoices_short') }}</span>
        </button>
      </li>
    </ul>

    <UpgradeModal :show="showUpgrade" @close="showUpgrade = false" />

    <InvoicingRelaySyncModal
      :open="showRelaySyncModal"
      :relay-enabled="relayRuntimeInfo.enabled"
      :relay-url="relayRuntimeInfo.url"
      :relay-from-profile="relayRuntimeInfo.source === 'profile'"
      :relay-owner-hint="relayOwnerHint"
      :is-relay-syncing="isRelaySyncing"
      :show-server-legacy-cleanup="showServerLegacyCleanup"
      :relay-push-busy="relayPushBusy"
      :relay-pull-busy="relayPullBusy"
      :relay-force-push-busy="relayForcePushBusy"
      @close="showRelaySyncModal = false"
      @push="runPushToRelay"
      @pull="runPullFromRelay"
      @force-push="runForcePushToRelay"
    />
  </InvoicingPageShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, unref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import InvoicingPageShell from '../../components/invoicing/InvoicingPageShell.vue';
import BackupReminderBanner from '../../components/invoicing/BackupReminderBanner.vue';
import LocalStorageWarningBanner from '../../components/invoicing/LocalStorageWarningBanner.vue';
import OfflineNoticeBanner from '../../components/invoicing/OfflineNoticeBanner.vue';
import InvoicingLoadingState from '../../components/invoicing/ui/InvoicingLoadingState.vue';
import InvoicingRelaySyncModal from '../../components/invoicing/InvoicingRelaySyncModal.vue';
import InvoicingRelaySyncStatusButton, {
  type RelaySyncStatusLevel,
} from '../../components/invoicing/InvoicingRelaySyncStatusButton.vue';
import { useBusinessInvoicing } from '../../composables/useBusinessInvoicing';
import { useInvoicingCompanies } from '../../composables/useInvoicingCompanies';
import type { InvoicingCompanyListItem } from '../../composables/useInvoicingCompanies';
import { useInvoicingRelaySync } from '@/composables/useInvoicingRelaySync';
import { useLocalStoreSanitizer } from '@/composables/useLocalStoreSanitizer';
import { useInvoicingEvolu } from '@/evolu/client';
import { getEvoluRelayRuntimeInfo } from '@/services/evoluRelayPreference';
import { readEvoluOwnerHint } from '@/evolu/relayPushMutations';
import { isEvoluRelaySyncPending } from '@/evolu/relaySyncWait';
import {
  fetchServerMigrationStatus,
  importServerAttachmentsToEvolu,
  importServerInvoicingToEvolu,
  clearServerMigrationCompleted,
  isServerMigrationCompleted,
  migrationWarningsNeedUserAttention,
  serverMigrationErrorMessage,
  shouldOfferAttachmentMigration,
  shouldOfferServerLegacyCleanup,
  shouldOfferServerMigration,
  type ServerMigrationStatus,
} from '@/evolu/serverMigration';
import { findDuplicateCompanyGroups, normalizeCompanyIdentityKey } from '@/evolu/duplicateCompanies';
import { mergeDuplicateCompaniesLocal } from '@/evolu/companyMergeLocal';
import { useAuthStore } from '../../store/auth';
import { useFlashStore } from '@/store/flash';
import UpgradeModal from '../../components/stores/UpgradeModal.vue';

withDefaults(
  defineProps<{
    embedded?: boolean;
  }>(),
  { embedded: false },
);

const { t, locale } = useI18n();
const router = useRouter();
const authStore = useAuthStore();
const flashStore = useFlashStore();
const { canUse } = useBusinessInvoicing();
const { isRelaySyncing, localFirst, pushToRelay, refreshFromRelay, syncState, lastExchangeAt, probeNow } = useInvoicingRelaySync();
const relayRuntimeInfo = computed(() => getEvoluRelayRuntimeInfo());
const relayPushBusy = ref(false);
const relayPullBusy = ref(false);
const relayForcePushBusy = ref(false);
const relayOwnerHint = ref('');
const evolu = useInvoicingEvolu();
const { ensureStoresLoaded } = useLocalStoreSanitizer();

const {
  companies,
  loading,
  forbidden,
  loadError,
  multiTabBlocked,
  refresh,
} = useInvoicingCompanies();

const showUpgrade = ref(false);
const showRelaySyncModal = ref(false);
const migrationStatus = ref<ServerMigrationStatus | null>(null);
const migrationImporting = ref(false);
const attachmentImporting = ref(false);
const mergeRunning = ref(false);

const relaySyncLoading = computed(() => localFirst && isEvoluRelaySyncPending() && loading.value);

watch(forbidden, (isForbidden) => {
  if (isForbidden) showUpgrade.value = true;
});

const companyList = computed(() => unref(companies));

const showRelaySyncStatusIcon = computed(
  () => localFirst && canUse && !loading.value && !loadError.value,
);

/**
 * Honest sync indicator (P1 phase 4): green ONLY with reachability + exchange
 * evidence (syncState "ok"); gray when nothing is known yet or sync is
 * local-only; red for unreachable/error; orange while syncing or when
 * migrations/duplicates need attention.
 */
const relaySyncStatusLevel = computed((): RelaySyncStatusLevel | null => {
  if (!localFirst) return null;
  if (!relayRuntimeInfo.value.enabled) return 'red';
  if (syncState.value === 'syncing') return 'orange';
  if (
    showServerMigration.value ||
    showAttachmentMigration.value ||
    duplicateCompanyGroups.value.length > 0 ||
    showServerLegacyCleanup.value
  ) {
    return 'orange';
  }
  switch (syncState.value) {
    case 'ok':
      return 'green';
    case 'unreachable':
    case 'error':
      return 'red';
    case 'local_only':
    case 'unknown':
    default:
      return 'gray';
  }
});

/** Opening the sync modal re-probes relay reachability on demand. */
function openRelaySyncModal(): void {
  showRelaySyncModal.value = true;
  void probeNow();
}

const relaySyncStatusTitle = computed((): string | null => {
  if (syncState.value === 'ok' && lastExchangeAt.value) {
    return t('invoicing.relay_sync_last_exchange_at', {
      time: new Date(lastExchangeAt.value).toLocaleString(locale.value),
    });
  }
  if (syncState.value === 'unreachable') return t('invoicing.relay_sync_state_unreachable');
  if (syncState.value === 'error') return t('invoicing.relay_sync_state_error');
  if (syncState.value === 'local_only') return t('invoicing.relay_sync_state_local_only');
  return null;
});

const showServerMigration = computed(() => {
  if (!localFirst || loading.value || loadError.value || isRelaySyncing.value) return false;
  return shouldOfferServerMigration(companyList.value.length, migrationStatus.value);
});

const showServerLegacyCleanup = computed(() => {
  if (!localFirst || loading.value || loadError.value || isRelaySyncing.value || showServerMigration.value) return false;
  return shouldOfferServerLegacyCleanup(companyList.value.length, migrationStatus.value);
});

const showAttachmentMigration = computed(() => {
  if (!localFirst || loading.value || loadError.value || isRelaySyncing.value || showServerMigration.value) return false;
  return shouldOfferAttachmentMigration(companyList.value.length, migrationStatus.value);
});

async function loadMigrationStatus(): Promise<void> {
  if (!localFirst) return;
  try {
    const status = await fetchServerMigrationStatus();
    migrationStatus.value = status;
    if (shouldOfferServerMigration(companyList.value.length, status) && isServerMigrationCompleted()) {
      clearServerMigrationCompleted();
    }
  } catch {
    migrationStatus.value = null;
  }
}

async function runPushToRelay(): Promise<void> {
  if (relayPushBusy.value) return;
  relayPushBusy.value = true;
  try {
    await finishPush(await pushToRelay());
  } finally {
    relayPushBusy.value = false;
  }
}

async function runForcePushToRelay(): Promise<void> {
  if (relayForcePushBusy.value) return;
  relayForcePushBusy.value = true;
  try {
    await finishPush(await pushToRelay({ force: true }), true);
  } finally {
    relayForcePushBusy.value = false;
  }
}

async function finishPush(
  result: Awaited<ReturnType<typeof pushToRelay>>,
  force = false,
): Promise<void> {
    if (result.relayDisabled) {
      flashStore.error(t('invoicing.relay_sync_relay_disabled'));
      return;
    }
    if (result.ownerStatus === 'no_phrase') {
      flashStore.error(t('invoicing.relay_sync_no_phrase'));
      return;
    }
    if (result.ownerStatus === 'owner_mismatch') {
      flashStore.error(t('invoicing.relay_sync_owner_mismatch'));
      return;
    }
    if (result.ok) {
      flashStore.success(
        force
          ? t('invoicing.relay_sync_force_push_ok', {
              rows: result.rowsForceTouched ?? 0,
              owner: result.ownerHint,
            })
          : t('invoicing.relay_sync_push_ok', {
              companies: result.companiesUpdated,
              events: result.syncEvents,
              owner: result.ownerHint,
            }),
      );
    } else if (result.evoluError) {
      flashStore.error(t('invoicing.relay_sync_push_evolu_error', { error: result.evoluError }));
    } else {
      flashStore.warning(
        force ? t('invoicing.relay_sync_force_push_failed') : t('invoicing.relay_sync_push_failed'),
      );
    }
    if (localFirst) {
      relayOwnerHint.value = await readEvoluOwnerHint(evolu);
    }
}

async function runPullFromRelay(): Promise<void> {
  if (relayPullBusy.value) return;
  relayPullBusy.value = true;
  try {
    const result = await refreshFromRelay();
    if (result.changed) {
      flashStore.success(t('invoicing.relay_sync_pull_ok'));
    } else if (result.timedOut) {
      flashStore.warning(t('invoicing.relay_sync_pull_no_changes'));
    } else {
      flashStore.success(t('invoicing.relay_sync_refresh_up_to_date'));
    }
    await refresh();
    if (localFirst) {
      relayOwnerHint.value = await readEvoluOwnerHint(evolu);
    }
  } finally {
    relayPullBusy.value = false;
  }
}

async function runServerMigration(): Promise<void> {
  if (migrationImporting.value) return;
  migrationImporting.value = true;
  try {
    const validStoreIds = await ensureStoresLoaded();
    const result = await importServerInvoicingToEvolu(evolu, validStoreIds);
    const companiesCount = result.counts.company ?? migrationStatus.value?.companies_count ?? 0;
    flashStore.success(t('invoicing.server_migration_success', { companies: companiesCount }));
    if (migrationWarningsNeedUserAttention(result.warnings)) {
      flashStore.warning(t('invoicing.server_migration_warnings'));
    }
    migrationStatus.value = null;
    await refresh();
  } catch (error) {
    flashStore.error(serverMigrationErrorMessage(error, t));
  } finally {
    migrationImporting.value = false;
  }
}

async function runAttachmentMigration(): Promise<void> {
  if (attachmentImporting.value) return;
  attachmentImporting.value = true;
  try {
    const result = await importServerAttachmentsToEvolu(evolu);
    flashStore.success(t('invoicing.server_migration_attachments_success', {
      count: result.counts.expenseAttachment ?? result.upserted,
    }));
    if (migrationWarningsNeedUserAttention(result.warnings)) {
      flashStore.warning(t('invoicing.server_migration_warnings'));
    }
    await loadMigrationStatus();
  } catch (error) {
    flashStore.error(serverMigrationErrorMessage(error, t));
  } finally {
    attachmentImporting.value = false;
  }
}

onMounted(() => {
  void loadMigrationStatus();
  if (localFirst) {
    void readEvoluOwnerHint(evolu).then((hint) => {
      relayOwnerHint.value = hint;
    });
  }
});

watch(companyList, () => {
  if (localFirst) {
    void loadMigrationStatus();
  }
});

const duplicateCompanyGroups = computed(() => {
  if (!localFirst) return [];
  const groups = findDuplicateCompanyGroups(companyList.value);
  if (groups.length > 0) {
    // Diagnostic for duplicate investigations: ids + counts, no document
    // content. Helps distinguish phantom same-id repeats from real rows.
    console.info(
      '[invoicing] duplicate company groups:',
      groups.map((group) => group.map((c) => `${c.id} (${c.documents_count ?? 0} docs)`)),
    );
  }
  return groups.map((group) => {
    const row = group[0] as InvoicingCompanyListItem | undefined;
    const label = row?.trade_name || row?.legal_name || '';
    const key = row
      ? normalizeCompanyIdentityKey(row.legal_name, row.registration_number ?? null)
      : label;
    return {
      key,
      label,
      companies: group as InvoicingCompanyListItem[],
    };
  });
});

async function runMergeDuplicates(): Promise<void> {
  if (mergeRunning.value || !localFirst) return;
  mergeRunning.value = true;
  try {
    const result = await mergeDuplicateCompaniesLocal(evolu, companyList.value);
    if (result.failedRemovals > 0) {
      flashStore.error(
        t('invoicing.duplicate_companies_merge_partial', {
          failed: result.failedRemovals,
          removed: result.removedCompanies,
        }),
      );
    } else if (result.removedCompanies === 0) {
      flashStore.warning(t('invoicing.duplicate_companies_merge_none'));
    } else {
      flashStore.success(
        t('invoicing.duplicate_companies_merge_success', {
          groups: result.mergedGroups,
          removed: result.removedCompanies,
        }),
      );
    }
    await refresh();
  } catch {
    flashStore.error(t('invoicing.duplicate_companies_merge_failed'));
  } finally {
    mergeRunning.value = false;
  }
}

const companyLimitMax = computed(() => {
  const plan = authStore.user?.plan;
  if (!plan || plan.companies_unlimited) return null;
  return typeof plan.max_companies === 'number' ? plan.max_companies : null;
});

const canCreateCompany = computed(() => {
  if (!canUse.value) return false;
  if (isRelaySyncing.value) return false;
  const max = companyLimitMax.value;
  if (max === null) return true;
  return companyList.value.length < max;
});

function openCompany(c: { id: string }): void {
  router.push({ name: 'invoicing-invoices', params: { companyId: c.id } });
}

function onAddCompany(): void {
  if (isRelaySyncing.value) return;
  router.push({ name: 'invoicing-company-new' });
}

function retryCompaniesLoad(): void {
  void refresh();
}

function reloadPage(): void {
  window.location.reload();
}
</script>
