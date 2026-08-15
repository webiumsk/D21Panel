<template>
  <div
    class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar"
  >
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="space-y-6">
        <!-- Header -->
        <div>
          <h3 class="text-2xl font-bold text-white">
            {{ t("account.title") }}
          </h3>
          <p class="mt-1 text-sm text-gray-400">
            {{ t("account.manage_profile") }}
          </p>
        </div>

        <div
          v-if="authStore.user?.is_guest"
          class="rounded-2xl border border-indigo-500/30 bg-indigo-500/10 p-5 space-y-4"
        >
          <div class="space-y-3">
            <h4 class="text-sm font-semibold text-white">
              {{ t("account.guest_upgrade_title") }}
            </h4>
            <GuestUpgradeForm id-prefix="guest-upgrade-email" />
          </div>
        </div>

        <nav
          class="flex border-b border-gray-700 -mx-1 overflow-x-auto"
          :aria-label="t('account.tabs_aria')"
        >
          <button
            v-for="tab in visibleProfileTabs"
            :key="tab.id"
            type="button"
            :class="[
              'px-4 sm:px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors flex items-center gap-2',
              activeTab === tab.id
                ? 'border-indigo-500 text-indigo-400'
                : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-600',
            ]"
            @click="setProfileTab(tab.id)"
          >
            {{ t(tab.labelKey) }}
            <span
              v-if="tab.badge"
              class="inline-flex h-2 w-2 rounded-full bg-amber-400"
              :title="t(tab.badgeHintKey ?? 'account.tab_sync_badge_hint')"
            />
          </button>
        </nav>

        <div v-show="activeTab === 'sync'" class="space-y-6">
        <div
          v-if="localFirst"
          class="rounded-2xl border border-gray-700 bg-gray-800/80 p-5 space-y-3"
        >
          <h4 class="text-sm font-semibold text-white">
            {{ t("account.evolu_relay_title") }}
          </h4>
          <p class="text-sm text-gray-300">
            {{ t("account.evolu_relay_desc") }}
          </p>
          <p class="text-xs text-gray-400">
            {{ t("account.evolu_relay_active", { url: activeRelayUrl }) }}
          </p>
          <p v-if="relayBuildDefault" class="text-xs text-gray-500">
            {{ t("account.evolu_relay_build_default", { url: relayBuildDefault }) }}
          </p>
          <label class="block text-sm text-gray-300">
            <span class="mb-1 block">{{ t("account.evolu_relay_url_label") }}</span>
            <input
              v-model="evoluRelayForm.url"
              type="url"
              class="w-full rounded-lg border border-gray-600 bg-gray-900 px-3 py-2 text-sm text-white"
              :placeholder="t('account.evolu_relay_url_placeholder')"
              autocomplete="off"
              spellcheck="false"
            />
          </label>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="inline-flex items-center px-4 py-2 border border-indigo-400 rounded-lg text-sm font-medium text-indigo-200 hover:bg-indigo-500/20 disabled:opacity-50"
              :disabled="evoluRelaySaving"
              @click="saveEvoluRelayUrl"
            >
              {{
                evoluRelaySaving
                  ? t("account.evolu_relay_saving")
                  : t("account.evolu_relay_save")
              }}
            </button>
            <button
              type="button"
              class="inline-flex items-center px-4 py-2 border border-gray-500 rounded-lg text-sm font-medium text-gray-200 hover:bg-gray-700/50 disabled:opacity-50"
              :disabled="evoluRelaySaving"
              @click="clearEvoluRelayUrl"
            >
              {{ t("account.evolu_relay_clear") }}
            </button>
            <button
              type="button"
              class="inline-flex items-center px-4 py-2 border border-gray-500 rounded-lg text-sm font-medium text-gray-200 hover:bg-gray-700/50 disabled:opacity-50"
              :disabled="evoluRelayTesting"
              @click="testEvoluRelayUrl"
            >
              {{ evoluRelayTesting ? t("account.evolu_relay_testing") : t("account.evolu_relay_test") }}
            </button>
          </div>
          <p
            v-if="evoluRelayTestResult"
            class="text-xs"
            :class="evoluRelayTestResult === 'ok' ? 'text-emerald-300' : 'text-red-400'"
            role="status"
            aria-live="polite"
          >
            {{
              evoluRelayTestResult === "ok"
                ? t("account.evolu_relay_test_ok")
                : t("account.evolu_relay_test_failed")
            }}
          </p>
          <label class="flex items-start gap-2 text-xs text-gray-400 cursor-pointer select-none">
            <input
              v-model="relaySyncDisabledOnDevice"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-gray-500 bg-gray-800 text-indigo-600 focus:ring-indigo-500"
              @change="toggleRelaySyncOnDevice"
            />
            <span>{{ t("account.evolu_relay_device_off_label") }}</span>
          </label>

          <div class="mt-4 border-t border-gray-600 pt-4 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <h5 class="text-sm font-semibold text-white">
                {{ t("account.evolu_stats_title") }}
              </h5>
              <button
                type="button"
                class="text-xs font-medium text-indigo-300 hover:text-indigo-200 disabled:opacity-50"
                :disabled="evoluStatsLoading"
                @click="refreshEvoluStats"
              >
                {{
                  evoluStatsLoading
                    ? t("account.evolu_stats_loading")
                    : t("account.evolu_stats_refresh")
                }}
              </button>
            </div>
            <p class="text-xs text-gray-400">
              {{ t("account.evolu_stats_desc") }}
            </p>

            <template v-if="evoluLocalStats">
              <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <div>
                  <dt class="text-gray-500">{{ t("account.evolu_stats_owner_id") }}</dt>
                  <dd class="font-mono text-gray-200">{{ evoluLocalStats.ownerIdShort }}</dd>
                </div>
                <div v-if="evoluLocalStats.localDbBytes != null">
                  <dt class="text-gray-500">{{ t("account.evolu_stats_local_db") }}</dt>
                  <dd class="text-gray-200">{{ formatByteSize(evoluLocalStats.localDbBytes) }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t("account.evolu_stats_companies") }}</dt>
                  <dd class="text-gray-200">{{ evoluLocalStats.counts.company }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t("account.evolu_stats_contacts") }}</dt>
                  <dd class="text-gray-200">{{ evoluLocalStats.counts.contact }}</dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t("account.evolu_stats_documents") }}</dt>
                  <dd class="text-gray-200">
                    {{ evoluLocalStats.counts.document }}
                    <span v-if="documentTypeSummary" class="text-gray-500 text-xs">
                      ({{ documentTypeSummary }})
                    </span>
                  </dd>
                </div>
                <div>
                  <dt class="text-gray-500">{{ t("account.evolu_stats_expenses") }}</dt>
                  <dd class="text-gray-200">{{ evoluLocalStats.counts.expense }}</dd>
                </div>
                <div v-if="evoluLocalStats.lastRelayPushAt">
                  <dt class="text-gray-500">{{ t("account.evolu_stats_last_push") }}</dt>
                  <dd class="text-gray-200">{{ formatStatsTime(evoluLocalStats.lastRelayPushAt) }}</dd>
                </div>
                <div v-if="evoluLocalStats.lastForceRelayPushAt">
                  <dt class="text-gray-500">{{ t("account.evolu_stats_last_force_push") }}</dt>
                  <dd class="text-gray-200">{{ formatStatsTime(evoluLocalStats.lastForceRelayPushAt) }}</dd>
                </div>
              </dl>

              <ul
                v-if="evoluLocalStats.companies.length"
                class="rounded-lg border border-gray-600 bg-gray-900/40 divide-y divide-gray-700 text-sm"
              >
                <li
                  v-for="row in evoluLocalStats.companies"
                  :key="row.id"
                  class="flex flex-wrap justify-between gap-2 px-3 py-2"
                >
                  <span class="text-gray-200 truncate">{{ row.name }}</span>
                  <span class="text-gray-500 text-xs shrink-0">
                    {{
                      t("account.evolu_stats_company_row", {
                        invoices: row.invoices,
                        documents: row.documents,
                        contacts: row.contacts,
                      })
                    }}
                  </span>
                </li>
              </ul>

              <p v-if="evoluLocalStats.relayError" class="text-xs text-amber-300">
                {{ t("account.evolu_stats_relay_error", { error: evoluLocalStats.relayError }) }}
              </p>

              <div
                v-if="evoluRelayUsage"
                class="rounded-lg border border-emerald-700/40 bg-emerald-950/30 px-3 py-2 text-sm space-y-1"
              >
                <p class="text-emerald-200 font-medium">{{ t("account.evolu_relay_usage_title") }}</p>
                <p class="text-emerald-100/90 text-xs">
                  {{
                    t("account.evolu_relay_usage_size", {
                      used: formatByteSize(evoluRelayUsage.storedBytes),
                      quota: formatByteSize(evoluRelayUsage.quotaBytes),
                      percent: relayUsagePercent(
                        evoluRelayUsage.storedBytes,
                        evoluRelayUsage.quotaBytes,
                      ),
                    })
                  }}
                </p>
                <p
                  v-if="evoluRelayUsage.lastActivityAt"
                  class="text-emerald-100/80 text-xs"
                >
                  {{
                    t("account.evolu_relay_usage_last_activity", {
                      time: formatStatsIso(evoluRelayUsage.lastActivityAt),
                    })
                  }}
                </p>
              </div>
              <p v-else-if="evoluRelayUsageChecked" class="text-xs text-gray-500">
                {{ t("account.evolu_relay_usage_unavailable") }}
              </p>
            </template>
            <p v-else-if="evoluStatsLoading" class="text-xs text-gray-500">
              {{ t("account.evolu_stats_loading") }}
            </p>
            <p v-else-if="evoluStatsNeedPhrase" class="text-xs text-amber-300">
              {{ t("account.evolu_stats_need_phrase") }}
            </p>

            <InvoicingBackupCard
              v-if="evoluLocalStats?.ownerId"
              :owner-id="evoluLocalStats.ownerId"
            />

            <LocalStorageCard v-if="localFirst" />
          </div>

          <p class="text-xs text-gray-500">
            {{ t("account.evolu_sync_invoicing_hint") }}
            <router-link
              :to="dashboardInvoicingTabPath"
              class="text-indigo-400 hover:text-indigo-300 underline"
            >
              {{ t("account.evolu_sync_invoicing_link") }}
            </router-link>
          </p>
        </div>
        </div>

        <div v-show="activeTab === 'data'" class="space-y-6">
        <div
          class="rounded-2xl border border-gray-700 bg-gray-800/80 p-5 space-y-3"
        >
          <h4 class="text-sm font-semibold text-white">
            {{ t("account.data_privacy_title") }}
          </h4>
          <p class="text-sm text-gray-300">
            {{ t("account.data_privacy_desc") }}
          </p>
          <router-link
            to="/legal/privacy"
            class="inline-flex text-sm font-medium text-indigo-400 hover:text-indigo-300 underline"
          >
            {{ t("account.data_privacy_link") }}
          </router-link>

          <div
            v-if="localFirst && serverLegacyCompanies.length"
            class="mt-4 pt-4 border-t border-gray-600 space-y-3"
          >
            <h5 class="text-sm font-semibold text-white">
              {{ t("account.server_legacy_companies_title") }}
            </h5>
            <p class="text-xs text-gray-400">
              {{ t("account.server_legacy_companies_desc") }}
            </p>
            <ul class="space-y-2">
              <li
                v-for="company in serverLegacyCompanies"
                :key="company.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-600 bg-gray-900/50 px-3 py-2"
              >
                <div class="min-w-0">
                  <p class="text-sm font-medium text-white truncate">
                    {{ company.legal_name }}
                  </p>
                  <p class="text-xs text-gray-500">
                    {{
                      t("account.server_legacy_companies_meta", {
                        contacts: company.contacts_count ?? 0,
                        documents: company.documents_count ?? 0,
                      })
                    }}
                  </p>
                </div>
                <button
                  type="button"
                  class="shrink-0 rounded-lg border border-red-500/40 px-3 py-1.5 text-xs font-medium text-red-300 hover:bg-red-500/10 disabled:opacity-50"
                  :disabled="serverLegacyDeleting === company.id"
                  @click="deleteServerLegacyCompany(company.id)"
                >
                  {{
                    serverLegacyDeleting === company.id
                      ? t("auth.saving")
                      : t("account.server_legacy_companies_delete")
                  }}
                </button>
              </li>
            </ul>
          </div>
          <p
            v-else-if="localFirst && serverLegacyLoading"
            class="text-xs text-gray-500"
          >
            {{ t("common.loading") }}
          </p>
        </div>
        </div>

        <div v-show="activeTab === 'account'" class="space-y-6">
        <!-- Profile Information -->
        <div
          class="bg-gray-800 shadow-xl rounded-2xl border border-gray-700 overflow-hidden"
        >
          <div class="px-6 py-8 sm:p-10">
            <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
              <svg
                class="w-5 h-5 mr-2 text-indigo-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                />
              </svg>
              {{ t("account.profile_information") }}
            </h4>
            <form @submit.prevent="handleUpdateProfile">
              <div class="grid grid-cols-1 gap-y-6 gap-x-6 sm:grid-cols-2">
                <div>
                  <label
                    for="name"
                    class="block text-sm font-medium text-gray-300"
                    >{{ t("account.name") }}</label
                  >
                  <div class="mt-1">
                    <input
                      id="name"
                      v-model="profileForm.name"
                      type="text"
                      class="appearance-none block w-full px-4 py-2 border border-gray-600 rounded-lg shadow-sm placeholder-gray-500 text-white bg-gray-700/50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm transition-colors"
                      :placeholder="t('account.name_optional')"
                    />
                  </div>
                </div>
                <div>
                  <label
                    for="email"
                    class="block text-sm font-medium text-gray-300"
                    >{{ t("account.email") }}</label
                  >
                  <div class="mt-1">
                    <input
                      id="email"
                      :value="authStore.user?.email"
                      type="email"
                      disabled
                      class="appearance-none block w-full px-4 py-2 border border-gray-600 rounded-lg shadow-sm text-gray-400 bg-gray-800 cursor-not-allowed sm:text-sm"
                    />
                  </div>
                </div>
              </div>
              <div class="mt-8 flex justify-end">
                <button
                  type="submit"
                  :disabled="profileLoading"
                  class="inline-flex justify-center py-2.5 px-6 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-900 disabled:opacity-50 transition-all shadow-lg shadow-indigo-600/20"
                >
                  {{
                    profileLoading
                      ? t("auth.saving")
                      : t("account.update_profile")
                  }}
                </button>
              </div>
            </form>
          </div>
        </div>

        <div
          class="rounded-2xl border border-gray-700 bg-gray-800/80 p-5 space-y-3"
        >
          <h4 class="text-sm font-semibold text-white">
            {{ t("account.recovery_phrase_title") }}
          </h4>
          <p class="text-sm text-gray-300">
            {{ t("account.recovery_phrase_desc") }}
          </p>
          <p
            v-if="authStore.user?.guest_recovery_enrolled"
            class="text-sm text-emerald-400"
          >
            {{ t("account.recovery_phrase_enrolled") }}
          </p>
          <template v-if="authStore.user?.guest_recovery_enrolled">
            <div
              v-if="storedGuestMnemonic"
              class="flex flex-wrap items-center gap-2"
            >
              <button
                type="button"
                class="inline-flex items-center px-4 py-2 border border-indigo-400 rounded-lg text-sm font-medium text-indigo-200 hover:bg-indigo-500/20"
                @click="showGuestSeedModal = true"
              >
                {{ t("account.recovery_phrase_reveal") }}
              </button>
            </div>
            <template v-else>
              <p class="text-sm text-amber-300">
                {{ t("account.recovery_phrase_unavailable_here") }}
              </p>
              <button
                type="button"
                class="inline-flex items-center px-4 py-2 border border-amber-400/60 rounded-lg text-sm font-medium text-amber-100 hover:bg-amber-500/10"
                @click="showRestoreOnDeviceModal = true"
              >
                {{ t("account.recovery_phrase_restore_on_device") }}
              </button>
            </template>
          </template>
          <button
            v-else
            type="button"
            class="inline-flex items-center px-4 py-2 border border-indigo-400 rounded-lg text-sm font-medium text-indigo-200 hover:bg-indigo-500/20"
            @click="showRecoveryBackupWizard = true"
          >
            {{ t("account.recovery_phrase_setup") }}
          </button>

          <!-- Passkeys: sign-in + restore on ANY device (account envelopes) merged
               with this device's offline unlock slots. The recovery phrase stays
               the root; passkeys are removable conveniences. -->
          <div
            v-if="localFirst && authStore.user?.guest_recovery_enrolled"
            class="mt-4 rounded-xl border border-indigo-500/25 bg-indigo-500/5 p-4 space-y-2"
          >
            <p class="text-sm font-medium text-gray-100">{{ t("account.passkey_main_title") }}</p>
            <p class="text-xs text-gray-400">{{ t("account.passkey_main_hint") }}</p>
            <p class="text-xs text-gray-500">{{ t("account.passkey_loss_warning") }}</p>
            <ul v-if="mergedPasskeys.length" class="space-y-2">
              <li
                v-for="entry in mergedPasskeys"
                :key="entry.key"
                class="flex items-center justify-between gap-3 rounded-lg border border-gray-700 bg-gray-900/60 px-3 py-2"
              >
                <span class="min-w-0 text-xs text-gray-300">
                  <span class="block font-medium text-gray-200 truncate">{{ entry.label }}</span>
                  <span class="block text-gray-500">
                    {{
                      entry.lastUsedAt
                        ? t("account.passkey_last_used", { date: formatDate(entry.lastUsedAt) })
                        : t("account.passkey_never_used")
                    }}
                  </span>
                  <span v-if="entry.cloud" class="block text-emerald-400/80 mt-0.5">
                    {{ t("account.passkey_everywhere_badge") }}
                  </span>
                  <span v-else class="block text-amber-400/90 mt-0.5">
                    {{ t("account.passkey_local_only_badge") }}
                  </span>
                </span>
                <span class="flex shrink-0 items-center gap-1.5">
                  <button
                    v-if="!entry.cloud && entry.slot"
                    type="button"
                    class="px-2 py-1 border border-indigo-500/40 rounded-lg text-xs text-indigo-300 hover:bg-indigo-500/10 disabled:opacity-50"
                    :disabled="passkeyUpgradeBusyId === entry.slot.id"
                    @click="enablePasskeyEverywhere(entry)"
                  >
                    {{ passkeyUpgradeBusyId === entry.slot.id ? t("common.loading") : t("account.passkey_enable_everywhere") }}
                  </button>
                  <button
                    type="button"
                    class="px-2 py-1 border border-red-500/40 rounded-lg text-xs text-red-300 hover:bg-red-500/10"
                    @click="removeMergedPasskey(entry)"
                  >
                    {{ t("account.passkey_remove") }}
                  </button>
                </span>
              </li>
            </ul>
            <button
              v-if="passkeySupported"
              type="button"
              class="px-3 py-1.5 border border-gray-600 rounded-lg text-xs text-gray-300 hover:bg-gray-700"
              @click="showAddPasskey = !showAddPasskey"
            >
              {{ t("account.passkey_add_button") }}
            </button>
            <p v-else class="text-xs text-gray-500">{{ t("account.passkey_unsupported") }}</p>
            <form v-if="showAddPasskey" class="space-y-2 pt-1" @submit.prevent="submitAddPasskey">
              <input
                v-model="passkeyLabelInput"
                type="text"
                maxlength="64"
                class="w-full rounded-lg border border-gray-600 bg-gray-900/80 px-3 py-2 text-sm text-gray-200"
                :placeholder="t('account.passkey_label_placeholder')"
              />
              <template v-if="deviceRemembered">
                <input
                  v-model="passkeyPassphraseInput"
                  type="password"
                  autocomplete="current-password"
                  class="w-full rounded-lg border border-gray-600 bg-gray-900/80 px-3 py-2 text-sm text-gray-200"
                  :placeholder="t('account.device_passphrase_placeholder')"
                />
                <p class="text-xs text-gray-500">{{ t("account.passkey_offline_note") }}</p>
              </template>
              <button
                type="submit"
                class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-xs text-white disabled:opacity-50"
                :disabled="passkeyBusy"
              >
                {{ passkeyBusy ? t("common.loading") : t("account.passkey_add_submit") }}
              </button>
            </form>
            <p v-if="passkeyError" class="text-xs text-red-400">{{ passkeyError }}</p>
          </div>

          <!-- Offline unlock of THIS device (advanced: passphrase envelope) -->
          <div
            v-if="localFirst && deviceRemembered"
            class="mt-4 rounded-xl border border-gray-700 bg-gray-900/50 p-4 space-y-3"
          >
            <p class="text-sm text-gray-200">{{ t("account.device_offline_title") }}</p>
            <p class="text-xs text-gray-500">{{ t("account.device_remembered_hint") }}</p>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="px-3 py-1.5 border border-gray-600 rounded-lg text-xs text-gray-300 hover:bg-gray-700"
                @click="showChangeDevicePassphrase = !showChangeDevicePassphrase"
              >
                {{ t("account.device_change_passphrase") }}
              </button>
              <button
                type="button"
                class="px-3 py-1.5 border border-amber-500/50 rounded-lg text-xs text-amber-200 hover:bg-amber-500/10"
                @click="forgetThisDevice"
              >
                {{ t("account.device_forget") }}
              </button>
            </div>
            <form
              v-if="showChangeDevicePassphrase"
              class="space-y-2 pt-1"
              autocomplete="on"
              @submit.prevent="submitChangeDevicePassphrase"
            >
              <input
                v-model="oldDevicePassphraseInput"
                type="password"
                autocomplete="current-password"
                class="w-full rounded-lg border border-gray-600 bg-gray-900/80 px-3 py-2 text-sm text-gray-200"
                :placeholder="t('account.device_passphrase_current_placeholder')"
              />
              <input
                v-model="newDevicePassphraseInput"
                type="password"
                autocomplete="new-password"
                class="w-full rounded-lg border border-gray-600 bg-gray-900/80 px-3 py-2 text-sm text-gray-200"
                :placeholder="t('account.device_passphrase_new_placeholder')"
              />
              <p v-if="changeDevicePassphraseError" class="text-xs text-red-400">
                {{ changeDevicePassphraseError }}
              </p>
              <button
                type="submit"
                class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-xs text-white"
              >
                {{ t("account.device_change_passphrase_submit") }}
              </button>
            </form>

          </div>
        </div>

        <!-- Change Password -->
        <div
          v-if="authStore.user?.can_use_password_login !== false"
          class="bg-gray-800 shadow-xl rounded-2xl border border-gray-700 overflow-hidden"
        >
          <div class="px-6 py-8 sm:p-10">
            <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
              <svg
                class="w-5 h-5 mr-2 text-indigo-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                />
              </svg>
              {{ t("account.password_settings") }}
            </h4>
            <form @submit.prevent="handleUpdatePassword">
              <div class="space-y-6">
                <div>
                  <label
                    for="current_password"
                    class="block text-sm font-medium text-gray-300"
                    >{{ t("account.current_password") }}</label
                  >
                  <div class="mt-1">
                    <input
                      id="current_password"
                      v-model="passwordForm.current_password"
                      type="password"
                      required
                      class="appearance-none block w-full px-4 py-2 border border-gray-600 rounded-lg shadow-sm placeholder-gray-500 text-white bg-gray-700/50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm transition-colors"
                    />
                  </div>
                </div>
                <div>
                  <label
                    for="password"
                    class="block text-sm font-medium text-gray-300"
                    >{{ t("account.new_password") }}</label
                  >
                  <div class="mt-1">
                    <input
                      id="password"
                      v-model="passwordForm.password"
                      type="password"
                      required
                      class="appearance-none block w-full px-4 py-2 border border-gray-600 rounded-lg shadow-sm placeholder-gray-500 text-white bg-gray-700/50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm transition-colors"
                    />
                  </div>
                </div>
                <div>
                  <label
                    for="password_confirmation"
                    class="block text-sm font-medium text-gray-300"
                    >{{ t("account.confirm_new_password") }}</label
                  >
                  <div class="mt-1">
                    <input
                      id="password_confirmation"
                      v-model="passwordForm.password_confirmation"
                      type="password"
                      required
                      class="appearance-none block w-full px-4 py-2 border border-gray-600 rounded-lg shadow-sm placeholder-gray-500 text-white bg-gray-700/50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm transition-colors"
                    />
                  </div>
                </div>
              </div>
              <div class="mt-8 flex justify-end">
                <button
                  type="submit"
                  :disabled="passwordLoading"
                  class="inline-flex justify-center py-2.5 px-6 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-gray-900 disabled:opacity-50 transition-all shadow-lg shadow-indigo-600/20"
                >
                  {{
                    passwordLoading
                      ? t("auth.saving")
                      : t("account.update_password")
                  }}
                </button>
              </div>
            </form>
          </div>
        </div>
        </div>

        <div v-show="activeTab === 'billing'" class="space-y-6">
        <!-- Subscription Plan -->
        <div
          class="bg-gray-800 shadow-xl rounded-2xl border border-gray-700 overflow-hidden"
        >
          <div class="px-6 py-8 sm:p-10">
            <h4 class="text-lg font-semibold text-white mb-6 flex items-center">
              <svg
                class="w-5 h-5 mr-2 text-indigo-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"
                />
              </svg>
              {{ t("account.subscription_plan") }}
            </h4>

            <div
              class="bg-gradient-to-br from-gray-700/50 to-gray-800 rounded-xl p-8 border border-gray-600 relative overflow-hidden"
            >
              <!-- Decorative blob -->
              <div
                class="absolute top-0 right-0 -tr-10 w-32 h-32 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20"
              ></div>

              <div
                class="flex flex-col md:flex-row md:items-center justify-between mb-8 relative z-10"
              >
                <div>
                  <h5
                    class="text-2xl font-bold text-white flex items-center gap-2"
                  >
                    {{ currentPlanName }}
                    <span
                      v-if="!isPaidPlan"
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-600 text-gray-100"
                    >
                      {{ t("account.plan_badge_standard") }}
                    </span>
                    <span
                      v-if="subscriptionBilling?.isTrial"
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-600/80 text-white"
                    >
                      {{ t("account.plan_badge_trial") }}
                    </span>
                    <span
                      v-else-if="subscriber?.isActive"
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gradient-to-r from-green-500 to-emerald-600 text-white"
                    >
                      {{ t("account.plan_badge_active") }}
                    </span>
                    <span
                      v-else
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-600 text-white"
                    >
                      {{ t("account.plan_badge_inactive") }}
                    </span>
                  </h5>
                  <p class="text-gray-400 mt-2">{{ currentPlanDescription }}</p>
                  <div
                    v-if="
                      subscriptionBilling?.isTrial &&
                      subscriptionBilling?.trialEndsAt
                    "
                    class="text-sm text-gray-400 mt-2"
                  >
                    {{
                      t("account.trial_expires", {
                        date: formatDate(subscriptionBilling.trialEndsAt),
                      })
                    }}
                  </div>
                  <div
                    v-else-if="subscriber && subscriber.periodEnd"
                    class="text-sm text-gray-400 mt-2"
                  >
                    {{
                      t("account.next_billing", {
                        date: formatDate(subscriber.periodEnd),
                      })
                    }}
                  </div>
                </div>
                <div class="mt-4 md:mt-0 md:text-right">
                  <div
                    class="text-3xl font-bold text-white flex items-baseline justify-end flex-wrap gap-x-2"
                  >
                    <span
                      v-if="isProPlan && proHasMonthlyDiscount(pricing.pro)"
                      class="text-lg font-normal text-gray-500 line-through"
                    >
                      {{ formatSats(pricing.pro.sats_per_month_display) }}
                    </span>
                    <span>{{ currentPlanPrice }}</span>
                  </div>
                  <div class="text-sm text-gray-400">
                    {{
                      isProPlan
                        ? t("account.per_month_paid_yearly")
                        : t("account.per_month")
                    }}
                  </div>
                  <p v-if="isProPlan" class="text-xs text-gray-500 mt-1">
                    {{
                      t("account.pro_yearly_price", {
                        amount: formatSats(pricing.pro.sats_per_year),
                      })
                    }}
                  </p>
                </div>
              </div>

              <div
                v-if="subscriptionBilling?.isTrial"
                class="mb-6 rounded-xl border border-cyan-500/30 bg-cyan-500/10 p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
              >
                <div class="flex items-start gap-3">
                  <svg
                    class="w-5 h-5 text-cyan-300 mt-0.5 flex-shrink-0"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M12 9v2m0 4h.01M10.29 3.86l-8.5 14.74A1 1 0 002.62 20h18.76a1 1 0 00.86-1.5l-8.5-14.74a1 1 0 00-1.72 0z"
                    />
                  </svg>
                  <p class="text-sm text-cyan-100">
                    {{
                      t("account.trial_warning", {
                        days: subscriptionBilling.trialDaysRemaining ?? 0,
                      })
                    }}
                  </p>
                </div>
                <button
                  v-if="subscriptionBilling.nextChargeSats > 0"
                  type="button"
                  class="inline-flex items-center justify-center rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-400 transition-colors disabled:opacity-50"
                  :disabled="payingNow"
                  @click="handlePayNow"
                >
                  {{
                    payingNow ? t("account.processing") : t("account.pay_now")
                  }}
                </button>
              </div>

              <!-- Plan Features -->
              <div class="border-t border-gray-600 pt-6 mb-6">
                <h6
                  class="text-sm font-medium text-gray-300 mb-4 uppercase tracking-wider"
                >
                  {{ t("account.current_plan_includes") }}
                </h6>
                <ul class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <li
                    v-for="feature in currentPlanFeatures"
                    :key="feature"
                    class="flex items-center text-sm text-gray-300"
                  >
                    <svg
                      class="w-5 h-5 text-green-400 mr-3 flex-shrink-0"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M5 13l4 4L19 7"
                      />
                    </svg>
                    {{ feature }}
                  </li>
                </ul>
              </div>

              <!-- Billing Information -->
              <div
                v-if="isPaidPlan || creditBalance > 0"
                class="border-t border-gray-600 pt-6 mb-6"
              >
                <h6
                  class="text-sm font-medium text-gray-300 mb-4 uppercase tracking-wider"
                >
                  {{ t("account.billing_information") }}
                </h6>
                <div class="space-y-4">
                  <div
                    v-if="isPaidPlan"
                    class="flex items-center justify-between"
                  >
                    <span class="text-sm text-gray-400">{{
                      t("account.payment_method")
                    }}</span>
                    <div class="flex items-center gap-2">
                      <span
                        class="px-3 py-1 rounded-full bg-gray-700 text-sm text-gray-300"
                        >{{
                          t("account.credit_balance", {
                            amount: formatSats(creditBalance),
                          })
                        }}</span
                      >
                      <button
                        @click="showAddCreditModal = true"
                        class="text-sm text-indigo-400 hover:text-indigo-300 flex items-center gap-1"
                      >
                        <svg
                          class="w-4 h-4"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                          />
                        </svg>
                        {{ t("account.add_credit") }}
                      </button>
                    </div>
                  </div>
                  <div
                    v-if="
                      subscriptionBilling &&
                      subscriptionBilling.planPriceSats > 0
                    "
                    class="rounded-lg border border-gray-600/80 bg-gray-900/40 p-4 space-y-2"
                  >
                    <div class="flex items-center justify-between text-sm">
                      <span class="text-gray-400">{{
                        t("account.plan_price")
                      }}</span>
                      <span class="text-gray-200">{{
                        formatSats(subscriptionBilling.planPriceSats)
                      }}</span>
                    </div>
                    <div
                      v-if="subscriptionBilling.creditAppliedSats > 0"
                      class="flex items-center justify-between text-sm"
                    >
                      <span class="text-gray-400">{{
                        t("account.credit_applied")
                      }}</span>
                      <span class="text-emerald-400"
                        >-
                        {{
                          formatSats(subscriptionBilling.creditAppliedSats)
                        }}</span
                      >
                    </div>
                    <div
                      v-if="subscriptionBilling.renewalDate"
                      class="flex items-center justify-between text-sm font-medium"
                    >
                      <span class="text-gray-300">{{
                        t("account.next_charge_total", {
                          date: formatDate(subscriptionBilling.renewalDate),
                        })
                      }}</span>
                      <span class="text-white">{{
                        formatSats(subscriptionBilling.nextChargeSats)
                      }}</span>
                    </div>
                  </div>
                  <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-400">{{
                      t("account.notification_email")
                    }}</span>
                    <span class="text-sm text-gray-300 flex items-center gap-1">
                      <svg
                        class="w-4 h-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                        />
                      </svg>
                      {{ authStore.user?.email }}
                    </span>
                  </div>
                  <p
                    v-if="subscriptionBilling?.paymentReminderDays"
                    class="text-xs text-gray-500"
                  >
                    {{
                      t("account.payment_reminder_notice", {
                        days: subscriptionBilling.paymentReminderDays,
                      })
                    }}
                  </p>
                  <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-400">{{
                      t("account.auto_renewal")
                    }}</span>
                    <div class="flex items-center gap-2">
                      <span class="text-sm text-gray-300">{{
                        subscriber?.autoRenew
                          ? t("account.on")
                          : t("account.off")
                      }}</span>
                      <div
                        class="relative inline-block w-10 h-6 transition-colors duration-200 ease-in-out rounded-full"
                        :class="
                          subscriber?.autoRenew
                            ? 'bg-indigo-600'
                            : 'bg-gray-600'
                        "
                      >
                        <span
                          class="absolute left-1 top-1 inline-block w-4 h-4 transform transition-transform duration-200 ease-in-out rounded-full bg-white"
                          :class="
                            subscriber?.autoRenew
                              ? 'translate-x-4'
                              : 'translate-x-0'
                          "
                        ></span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div
                v-if="creditHistory.length > 0"
                class="border-t border-gray-600 pt-6 mb-6"
              >
                <h6
                  class="text-sm font-medium text-gray-300 mb-4 uppercase tracking-wider"
                >
                  {{ t("account.credit_history") }}
                </h6>
                <div
                  class="overflow-x-auto rounded-lg border border-gray-600/80"
                >
                  <table class="min-w-full text-sm">
                    <thead class="bg-gray-900/60 text-gray-400">
                      <tr>
                        <th class="px-4 py-3 text-left font-medium">
                          {{ t("account.credit_history_date") }}
                        </th>
                        <th class="px-4 py-3 text-left font-medium">
                          {{ t("account.credit_history_description") }}
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                          {{ t("account.credit_history_amount") }}
                        </th>
                        <th class="px-4 py-3 text-right font-medium">
                          {{ t("account.credit_history_balance") }}
                        </th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700/80">
                      <tr
                        v-for="(entry, index) in creditHistory"
                        :key="`${entry.date}-${index}`"
                        class="text-gray-300"
                      >
                        <td class="px-4 py-3 whitespace-nowrap">
                          {{ formatCreditHistoryDate(entry.date) }}
                        </td>
                        <td class="px-4 py-3">
                          {{ entry.description }}
                        </td>
                        <td
                          class="px-4 py-3 text-right font-medium"
                          :class="
                            entry.amount >= 0
                              ? 'text-emerald-400'
                              : 'text-red-400'
                          "
                        >
                          {{ formatSignedSats(entry.amount) }}
                        </td>
                        <td class="px-4 py-3 text-right">
                          {{
                            entry.balance != null
                              ? formatSats(entry.balance)
                              : "-"
                          }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Upgrade Options -->
              <div
                v-if="showUpgradeOptions"
                class="border-t border-gray-600 pt-8 mt-8"
              >
                <h6 class="text-lg font-medium text-white mb-6">
                  {{ t("account.upgrade_to_unlock") }}
                </h6>
                <div
                  :class="
                    showProUpgrade
                      ? 'grid grid-cols-1 md:grid-cols-2 gap-6'
                      : 'grid grid-cols-1 gap-6'
                  "
                >
                  <!-- PRO Plan Card -->
                  <div
                    v-if="showProUpgrade"
                    class="border border-gray-600 rounded-xl p-6 bg-gray-800/80 hover:border-indigo-500 transition-colors relative group"
                  >
                    <div
                      class="absolute inset-0 bg-gradient-to-br from-indigo-600/5 to-purple-600/5 rounded-xl opacity-0 group-hover:opacity-100 transition-opacity"
                    ></div>
                    <div class="relative z-10">
                      <h6 class="text-lg font-bold text-white mb-2">
                        {{ t("account.pro_plan") }}
                      </h6>
                      <p
                        class="text-xs font-semibold uppercase tracking-wide text-emerald-400 mb-2"
                      >
                        {{
                          t("account.pro_trial_badge", {
                            days: pricing.trial_days,
                          })
                        }}
                      </p>
                      <div class="text-2xl font-bold text-indigo-400 mb-1">
                        <span
                          v-if="proHasMonthlyDiscount(pricing.pro)"
                          class="text-base font-normal text-gray-500 line-through mr-2"
                          >{{
                            formatSats(pricing.pro.sats_per_month_display)
                          }}</span
                        >
                        {{ formatSats(proEffectiveMonthlySats(pricing.pro))
                        }}<span class="text-base font-normal text-gray-500">{{
                          t("account.pro_price_period")
                        }}</span>
                      </div>
                      <p class="text-sm text-gray-400 mb-4">
                        {{
                          t("account.pro_yearly_price", {
                            amount: formatSats(pricing.pro.sats_per_year),
                          })
                        }}
                      </p>
                      <ul class="text-sm text-gray-400 space-y-2 mb-6">
                        <li
                          v-for="key in planFeatures.pro.feature_keys"
                          :key="key"
                          class="flex items-center"
                          :class="
                            isInvoicingFeature(key)
                              ? 'text-indigo-200 font-medium'
                              : ''
                          "
                        >
                          <span
                            class="w-1.5 h-1.5 rounded-full mr-2"
                            :class="
                              isInvoicingFeature(key)
                                ? 'bg-indigo-400'
                                : 'bg-indigo-500'
                            "
                          ></span
                          >{{ t("plans.features." + key) }}
                        </li>
                      </ul>
                      <button
                        @click="upgradePlan('pro')"
                        :disabled="upgrading"
                        class="w-full px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold rounded-lg transition-all shadow-lg shadow-indigo-600/20 disabled:opacity-50 disabled:cursor-not-allowed"
                      >
                        {{
                          upgrading
                            ? t("stores.processing")
                            : t("account.upgrade_to_pro")
                        }}
                      </button>
                    </div>
                  </div>

                  <!-- Enterprise / Need more? Card (same as Landing) -->
                  <div
                    v-if="showEnterpriseUpgrade"
                    class="border border-gray-600 rounded-xl p-6 bg-gray-800/80 hover:border-purple-500 transition-colors relative group"
                    :class="!showProUpgrade ? 'md:max-w-md mx-auto' : ''"
                  >
                    <div
                      class="absolute inset-0 bg-gradient-to-br from-purple-600/5 to-pink-600/5 rounded-xl opacity-0 group-hover:opacity-100 transition-opacity"
                    ></div>
                    <div class="relative z-10">
                      <h6 class="text-lg font-bold text-white mb-2">
                        {{ t("landing.pricing_need_more_headline") }}
                      </h6>
                      <p class="text-sm text-gray-400 mb-4">
                        {{ t("landing.pricing_need_more_text") }}
                      </p>
                      <ul class="text-sm text-gray-400 space-y-2 mb-6">
                        <li
                          v-for="key in planFeatures.enterprise.feature_keys"
                          :key="key"
                          class="flex items-center"
                        >
                          <span
                            class="w-1.5 h-1.5 rounded-full bg-purple-500 mr-2"
                          ></span
                          >{{ t("plans.features." + key) }}
                        </li>
                      </ul>
                      <a
                        href="mailto:hello@satflux.io"
                        class="block w-full text-center px-4 py-2 border border-purple-500 text-purple-400 hover:bg-purple-500/10 text-sm font-bold rounded-lg transition-all"
                      >
                        {{ t("landing.pricing_need_more_cta") }}
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        </div>
      </div>

      <div
        v-if="showGuestSeedModal"
        class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4"
        @click.self="showGuestSeedModal = false"
      >
        <div
          class="bg-gray-800 rounded-2xl border border-gray-700 max-w-2xl w-full p-6"
        >
          <div class="flex items-center justify-between mb-4">
            <h5 class="text-lg font-bold text-white">
              {{ t("account.recovery_phrase_title") }}
            </h5>
            <button
              type="button"
              class="text-gray-400 hover:text-white"
              @click="showGuestSeedModal = false"
            >
              {{ t("common.close") }}
            </button>
          </div>
          <p class="text-sm text-amber-300 mb-4">
            {{ t("account.guest_seed_modal_warning") }}
          </p>
          <textarea
            readonly
            rows="4"
            class="w-full rounded-xl border border-gray-600 bg-gray-900/80 px-4 py-3 text-sm text-gray-200"
            :value="storedGuestMnemonic || ''"
          />
          <div class="mt-4 flex justify-end gap-2">
            <button
              type="button"
              class="px-4 py-2 border border-gray-600 rounded-lg text-sm text-gray-300 hover:bg-gray-700"
              @click="copyStoredSeed"
            >
              {{
                copiedSeed
                  ? t("auth.guest_backup_copied")
                  : t("auth.guest_backup_copy")
              }}
            </button>
            <button
              type="button"
              class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm text-white"
              @click="showGuestSeedModal = false"
            >
              {{ t("common.close") }}
            </button>
          </div>
        </div>
      </div>

      <div
        v-if="showRestoreOnDeviceModal"
        class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4"
        @click.self="showRestoreOnDeviceModal = false"
      >
        <div
          class="bg-gray-800 rounded-2xl border border-gray-700 max-w-lg w-full p-6 space-y-4"
        >
          <!-- Unlock mode: device is remembered, unlock with the device passphrase -->
          <template v-if="deviceRemembered && !useRecoveryPhraseInstead">
            <h5 class="text-lg font-bold text-white">
              {{ t("account.device_unlock_title") }}
            </h5>
            <p class="text-sm text-gray-300">
              {{ t("account.device_unlock_detail") }}
            </p>
            <template v-if="passkeySupported && passkeySlots.length > 0">
              <button
                type="button"
                class="w-full px-4 py-3 bg-indigo-600 hover:bg-indigo-500 rounded-xl text-sm font-medium text-white disabled:opacity-50"
                :disabled="deviceUnlockLoading"
                @click="submitDeviceUnlockWithPasskey"
              >
                {{
                  restoreOwnerSwitchImpact
                    ? t("auth.guest_restore_owner_switch_confirm")
                    : t("account.passkey_unlock_button")
                }}
              </button>
              <p class="text-center text-xs text-gray-500">
                {{ t("account.passkey_or_passphrase") }}
              </p>
            </template>
            <form autocomplete="on" @submit.prevent="submitDeviceUnlock">
              <input
                v-model="devicePassphraseInput"
                type="password"
                autocomplete="current-password"
                :aria-label="t('account.device_passphrase_placeholder')"
                class="w-full rounded-xl border border-gray-600 bg-gray-900/80 px-4 py-3 text-sm text-gray-200"
                :placeholder="t('account.device_passphrase_placeholder')"
              />
              <p v-if="deviceUnlockError" class="mt-2 text-sm text-red-400">
                {{ deviceUnlockError }}
              </p>
              <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                <div class="flex gap-3 text-xs">
                  <button
                    type="button"
                    class="text-indigo-300 hover:text-indigo-200"
                    @click="useRecoveryPhraseInstead = true"
                  >
                    {{ t("account.device_use_recovery_phrase") }}
                  </button>
                  <button
                    type="button"
                    class="text-gray-400 hover:text-gray-200"
                    @click="forgetThisDevice"
                  >
                    {{ t("account.device_forget") }}
                  </button>
                </div>
                <button
                  type="submit"
                  class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm text-white disabled:opacity-50"
                  :disabled="deviceUnlockLoading"
                >
                  {{
                    deviceUnlockLoading
                      ? t("common.loading")
                      : restoreOwnerSwitchImpact
                        ? t("auth.guest_restore_owner_switch_confirm")
                        : t("account.device_unlock_submit")
                  }}
                </button>
              </div>
            </form>
            <div
              v-if="restoreOwnerSwitchImpact"
              class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 space-y-2"
            >
              <p class="text-sm font-medium text-amber-300">
                {{ t("auth.guest_restore_owner_switch_title") }}
              </p>
              <p class="text-sm text-gray-300">
                {{
                  t("auth.guest_restore_owner_switch_body", {
                    documents: restoreOwnerSwitchImpact.documents,
                    contacts: restoreOwnerSwitchImpact.contacts,
                    companies: restoreOwnerSwitchImpact.companies,
                  })
                }}
              </p>
            </div>
          </template>

          <!-- Recovery-phrase mode: enter the phrase, optionally remember the device -->
          <template v-else>
            <h5 class="text-lg font-bold text-white">
              {{ t("account.recovery_phrase_restore_on_device") }}
            </h5>
            <p class="text-sm text-gray-300">
              {{ t("account.recovery_phrase_restore_on_device_detail") }}
            </p>
            <template v-if="passkeySupported && accountEnvelopeCount > 0">
              <button
                type="button"
                class="w-full flex items-center justify-center gap-2 py-3 px-4 border border-indigo-500/40 text-sm font-bold rounded-xl text-indigo-200 bg-indigo-500/10 hover:bg-indigo-500/20 disabled:opacity-50 transition-all"
                :disabled="accountPasskeyRestoreLoading"
                @click="submitRestoreWithAccountPasskey"
              >
                {{
                  accountPasskeyRestoreLoading
                    ? t("common.loading")
                    : restoreOwnerSwitchImpact
                      ? t("auth.guest_restore_owner_switch_confirm")
                      : t("account.passkey_restore_button")
                }}
              </button>
              <div class="flex items-center gap-3 text-xs text-gray-500">
                <span class="h-px flex-1 bg-gray-700"></span>
                {{ t("account.passkey_or_phrase_divider") }}
                <span class="h-px flex-1 bg-gray-700"></span>
              </div>
            </template>
            <textarea
              v-model="restoreOnDeviceInput"
              rows="4"
              class="w-full rounded-xl border border-gray-600 bg-gray-900/80 px-4 py-3 text-sm text-gray-200"
              :placeholder="t('auth.guest_restore_placeholder')"
              autocomplete="off"
            />

            <div class="rounded-xl border border-gray-700 bg-gray-900/50 p-4 space-y-3">
              <label class="flex items-start gap-3 cursor-pointer select-none">
                <input
                  v-model="rememberDeviceOptIn"
                  type="checkbox"
                  class="mt-1 h-4 w-4 rounded border-gray-500 bg-gray-800 text-indigo-600 focus:ring-indigo-500"
                />
                <span class="text-sm text-gray-200">
                  {{ t("account.device_remember_label") }}
                  <span class="block text-xs text-gray-500 mt-1">
                    {{ t("account.device_remember_hint") }}
                  </span>
                </span>
              </label>
              <input
                v-if="rememberDeviceOptIn"
                v-model="devicePassphraseInput"
                type="password"
                autocomplete="new-password"
                :aria-label="t('account.device_passphrase_new_placeholder')"
                class="w-full rounded-xl border border-gray-600 bg-gray-900/80 px-4 py-3 text-sm text-gray-200"
                :placeholder="t('account.device_passphrase_new_placeholder')"
              />
            </div>

            <div
              v-if="restoreOwnerSwitchImpact"
              class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 space-y-2"
            >
              <p class="text-sm font-medium text-amber-300">
                {{ t("auth.guest_restore_owner_switch_title") }}
              </p>
              <p class="text-sm text-gray-300">
                {{
                  t("auth.guest_restore_owner_switch_body", {
                    documents: restoreOwnerSwitchImpact.documents,
                    contacts: restoreOwnerSwitchImpact.contacts,
                    companies: restoreOwnerSwitchImpact.companies,
                  })
                }}
              </p>
            </div>

            <p v-if="restoreOnDeviceError" class="text-sm text-red-400">
              {{ restoreOnDeviceError }}
            </p>
            <div class="flex justify-end gap-2">
              <button
                type="button"
                class="px-4 py-2 border border-gray-600 rounded-lg text-sm text-gray-300 hover:bg-gray-700"
                @click="showRestoreOnDeviceModal = false"
              >
                {{ t("common.cancel") }}
              </button>
              <button
                type="button"
                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-sm text-white disabled:opacity-50"
                :disabled="restoreOnDeviceLoading"
                @click="submitRestoreOnDevice"
              >
                {{
                  restoreOnDeviceLoading
                    ? t("common.loading")
                    : restoreOwnerSwitchImpact
                      ? t("auth.guest_restore_owner_switch_confirm")
                      : t("account.recovery_phrase_restore_on_device_submit")
                }}
              </button>
            </div>
          </template>
        </div>
      </div>

      <!-- Add Credit Modal -->
      <div
        v-if="showAddCreditModal"
        class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
        @click.self="showAddCreditModal = false"
      >
        <div
          class="bg-gray-800 rounded-xl border border-gray-700 max-w-md w-full p-6"
        >
          <div class="flex items-center justify-between mb-4">
            <h5 class="text-lg font-bold text-white">
              {{ t("account.add_credit_modal_title") }}
            </h5>
            <button
              @click="showAddCreditModal = false"
              class="text-gray-400 hover:text-white"
            >
              <svg
                class="w-6 h-6"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
          </div>
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">{{
                t("account.amount_sats")
              }}</label>
              <input
                v-model.number="creditAmount"
                type="number"
                min="1"
                step="1"
                class="w-full px-4 py-2 border border-gray-600 rounded-lg text-white bg-gray-700/50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                :placeholder="t('account.amount_placeholder')"
              />
            </div>
            <div class="flex gap-3">
              <button
                @click="showAddCreditModal = false"
                class="flex-1 px-4 py-2 border border-gray-600 text-gray-300 rounded-lg hover:bg-gray-700 transition-colors"
              >
                {{ t("common.cancel") }}
              </button>
              <button
                @click="handleAddCredit"
                :disabled="!creditAmount || creditAmount < 1 || addingCredit"
                class="flex-1 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              >
                {{
                  addingCredit
                    ? t("stores.processing")
                    : t("account.add_credit_btn")
                }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <GuestBackupWizardModal
        :open="showRecoveryBackupWizard"
        @close="showRecoveryBackupWizard = false"
        @done="handleRecoveryEnrolled"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { asApiError } from "../../utils/apiError";
import { ref, onMounted, computed, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useI18n } from "vue-i18n";
import { localeTagFor } from "@/i18n";
import { useAuthStore } from "../../store/auth";
import { useFlashStore } from "../../store/flash";
import {
  usePricing,
  proEffectiveMonthlySats,
  proHasMonthlyDiscount,
} from "../../composables/usePricing";
import { usePlanFeatures } from "../../composables/usePlanFeatures";
import { useCurrentPlan } from "../../composables/useCurrentPlan";
import api, { invoicingApi } from "../../services/api";
import GuestBackupWizardModal from "../../components/auth/GuestBackupWizardModal.vue";
import GuestUpgradeForm from "../../components/account/GuestUpgradeForm.vue";
import {
  getStoredGuestMnemonic,
  storeGuestMnemonic,
} from "../../services/guestRecovery";
import {
  initEvoluFromAccountSeedIfNeeded,
  bindRecoveryPhraseOnThisDevice,
  clearSessionAccountMnemonic,
  deriveRecoveryPublicKeyHex,
  getStoredAccountMnemonic,
  isEvoluUnavailableError,
  previewOwnerSwitchImpact,
  storeAccountMnemonic,
  type OwnerSwitchImpact,
} from "../../services/accountSeed";
import {
  addAccountPasskeyFromSession,
  addPasskeyToRememberedDevice,
  changeDevicePassphrase,
  forgetDevice,
  isDeviceRemembered,
  listDevicePasskeySlots,
  passkeyPrfUnlockProvider,
  removeDevicePasskeySlot,
  rememberDeviceWithPassphrase,
  restoreWithAccountPasskey,
  unlockDeviceWithPasskey,
  unlockDeviceWithPassphrase,
  upgradeAccountPasskey,
  type PasskeySlotMetadata,
} from "../../services/deviceUnlock/provider";
import {
  credentialIdToB64Url,
  deleteAccountEnvelope,
  listAccountEnvelopes,
  type AccountEnvelopeSummary,
} from "../../services/deviceUnlock/accountPasskeyEnvelope";
import {
  PasskeyCancelledError,
  PasskeyPrfUnsupportedError,
  PasskeyUnsupportedError,
} from "../../services/deviceUnlock/passkeyPrf";
import { isAcceptableDevicePassphrase } from "../../services/deviceUnlock/envelope";
import { isInvoicingLocalFirst } from "../../evolu/flags";
import { getEvoluRelayBuildInfo, normalizeEvoluRelayBaseUrl } from "../../evolu/config";
import { getEvoluRelayRuntimeInfo } from "../../services/evoluRelayPreference";
import { refreshEvoluRelaySubscription } from "../../evolu/evoluRelaySubscription";
import { ensureEvoluBoundToAccountSeed, resetEvoluBootstrapForRetry } from "../../evolu/bootstrap";
import { allowEvoluPageReload } from "../../evolu/reloadGuard";
import {
  formatByteSize,
  loadInvoicingLocalStats,
  type InvoicingLocalStats,
} from "../../evolu/invoicingLocalStats";
import InvoicingBackupCard from "../../components/account/InvoicingBackupCard.vue";
import LocalStorageCard from "../../components/account/LocalStorageCard.vue";
import {
  fetchEvoluRelayUsage,
  probeRelayReachability,
  relayUsagePercent,
  type EvoluRelayUsageResponse,
} from "../../services/evoluRelayUsageApi";
import {
  clearRelayOverride,
  readRelayOverride,
  writeRelayOverrideDisabled,
  writeRelayOverrideUrl,
} from "../../evolu/relayOverrideStorage";
import { dashboardInvoicingTabPath } from "../../utils/dashboardInvoicingTab";
const { t, locale } = useI18n();
const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const flashStore = useFlashStore();
const { pricing, formatSats, load: loadPricing } = usePricing();
const {
  planFeatures,
  isInvoicingFeature,
  load: loadPlanFeatures,
} = usePlanFeatures();

const profileForm = ref({
  name: "",
});

const passwordForm = ref({
  current_password: "",
  password: "",
  password_confirmation: "",
});

const profileLoading = ref(false);
const passwordLoading = ref(false);
const upgrading = ref(false);
type SubscriptionBilling = {
  phase?: string | null;
  isTrial?: boolean;
  trialEndsAt?: number | null;
  trialDaysRemaining?: number | null;
  planPriceSats?: number;
  creditAppliedSats?: number;
  nextChargeSats?: number;
  renewalDate?: number | null;
  paymentReminderDays?: number;
};

type CreditHistoryEntry = {
  date: string;
  description: string;
  amount: number;
  balance: number | null;
};

const subscriber = ref<any>(null);
const subscriptionBilling = ref<SubscriptionBilling | null>(null);
const creditBalance = ref(0);
const creditHistory = ref<CreditHistoryEntry[]>([]);
const showAddCreditModal = ref(false);
const creditAmount = ref<number | null>(null);
const addingCredit = ref(false);
const payingNow = ref(false);
const loadingSubscription = ref(false);

const showGuestSeedModal = ref(false);
const showRecoveryBackupWizard = ref(false);
const showRestoreOnDeviceModal = ref(false);
watch(showRestoreOnDeviceModal, (open) => {
  if (open) {
    void refreshAccountEnvelopeCount();
  } else {
    resetRestoreOwnerSwitchGuard();
  }
});
const restoreOnDeviceInput = ref("");
const restoreOnDeviceLoading = ref(false);
const restoreOnDeviceError = ref("");
const restoreOwnerSwitchImpact = ref<Extract<OwnerSwitchImpact, { switches: true }> | null>(null);
const restoreOwnerSwitchConfirmedFor = ref("");

// "Remember this device" - opt-in device passphrase unlock (see deviceUnlock).
const deviceRemembered = ref(false);
const rememberDeviceOptIn = ref(false);
const devicePassphraseInput = ref("");
const useRecoveryPhraseInstead = ref(false);
const deviceUnlockLoading = ref(false);
const deviceUnlockError = ref("");
const showChangeDevicePassphrase = ref(false);
const oldDevicePassphraseInput = ref("");
const newDevicePassphraseInput = ref("");
const changeDevicePassphraseError = ref("");

async function refreshDeviceRemembered(): Promise<void> {
  try {
    deviceRemembered.value = await isDeviceRemembered();
  } catch {
    deviceRemembered.value = false;
  }
  await refreshPasskeyState();
}

/** Unlock this device with the device passphrase (no need to retype the phrase). */
async function submitDeviceUnlock(): Promise<void> {
  deviceUnlockError.value = "";
  deviceUnlockLoading.value = true;
  const previousMnemonic = getStoredAccountMnemonic();
  try {
    const { recoveryPhrase } = await unlockDeviceWithPassphrase(devicePassphraseInput.value);
    if (!(await confirmRestoreOwnerSwitchIfNeeded(recoveryPhrase))) {
      restorePreviousSessionMnemonic(previousMnemonic);
      devicePassphraseInput.value = "";
      return;
    }
    resetEvoluBootstrapForRetry();
    storedGuestMnemonic.value = getStoredGuestMnemonic();
    devicePassphraseInput.value = "";
    showRestoreOnDeviceModal.value = false;
    const { evolu } = await import("@/evolu/client");
    allowEvoluPageReload();
    evolu.reloadApp();
  } catch {
    // Non-distinguishing: wrong passphrase, tampered or missing envelope.
    deviceUnlockError.value = t("account.device_unlock_failed");
  } finally {
    deviceUnlockLoading.value = false;
  }
}

async function forgetThisDevice(): Promise<void> {
  await forgetDevice();
  await refreshDeviceRemembered();
  flashStore.success(t("account.device_forgotten"));
}

const passkeySupported = ref(false);
const passkeySlots = ref<PasskeySlotMetadata[]>([]);
const showAddPasskey = ref(false);
const passkeyLabelInput = ref("");
const passkeyPassphraseInput = ref("");
const passkeyError = ref("");
const passkeyBusy = ref(false);

async function refreshPasskeyState(): Promise<void> {
  try {
    passkeySupported.value = await passkeyPrfUnlockProvider.isSupported();
    passkeySlots.value = await listDevicePasskeySlots();
  } catch {
    passkeySlots.value = [];
  }
  // The slot list marks passkeys that lack a server envelope.
  await refreshAccountEnvelopeCount();
}

/** Silent on user cancel; typed messages for unsupported authenticators. */
function passkeyErrorMessage(error: unknown): string {
  if (error instanceof PasskeyCancelledError) return "";
  if (error instanceof PasskeyPrfUnsupportedError) return t("account.passkey_prf_unsupported");
  if (error instanceof PasskeyUnsupportedError) return t("account.passkey_unsupported");
  return t("account.device_unlock_failed");
}

/** Unlock this device with a passkey - one platform prompt, no typing. */
async function submitDeviceUnlockWithPasskey(): Promise<void> {
  deviceUnlockError.value = "";
  deviceUnlockLoading.value = true;
  const previousMnemonic = getStoredAccountMnemonic();
  try {
    const { recoveryPhrase } = await unlockDeviceWithPasskey();
    if (!(await confirmRestoreOwnerSwitchIfNeeded(recoveryPhrase))) {
      restorePreviousSessionMnemonic(previousMnemonic);
      return;
    }
    resetEvoluBootstrapForRetry();
    storedGuestMnemonic.value = getStoredGuestMnemonic();
    showRestoreOnDeviceModal.value = false;
    const { evolu } = await import("@/evolu/client");
    allowEvoluPageReload();
    evolu.reloadApp();
  } catch (error) {
    deviceUnlockError.value = passkeyErrorMessage(error);
  } finally {
    deviceUnlockLoading.value = false;
  }
}

/**
 * One add-passkey form, two paths: with the device passphrase filled in it
 * creates the local unlock slot AND the account envelope (one gesture);
 * left empty it needs the unlocked session and creates the account
 * envelope only (still signs in and restores everywhere).
 */
async function submitAddPasskey(): Promise<void> {
  passkeyError.value = "";
  passkeyBusy.value = true;
  try {
    const label = passkeyLabelInput.value.trim() || t("account.passkey_default_label");

    if (deviceRemembered.value && passkeyPassphraseInput.value) {
      const result = await addPasskeyToRememberedDevice(passkeyPassphraseInput.value, label);
      passkeySlots.value = result.slots;
      if (result.cloudSynced) {
        flashStore.success(t("account.passkey_added"));
      } else {
        // Local slot works; the account envelope upload failed - the passkey
        // unlocks THIS device only until it is promoted again.
        flashStore.warning(t("account.passkey_added_local_only"));
      }
    } else if (getStoredAccountMnemonic()) {
      await addAccountPasskeyFromSession(label);
      flashStore.success(t("account.passkey_added"));
    } else {
      passkeyError.value = deviceRemembered.value
        ? t("account.passkey_passphrase_hint")
        : t("account.passkey_enable_needs_unlock");
      return;
    }

    passkeyPassphraseInput.value = "";
    passkeyLabelInput.value = "";
    showAddPasskey.value = false;
    await refreshAccountEnvelopeCount();
  } catch (error) {
    passkeyError.value = passkeyErrorMessage(error);
  } finally {
    passkeyBusy.value = false;
  }
}

const accountEnvelopes = ref<AccountEnvelopeSummary[]>([]);
const accountEnvelopeCount = computed(() => accountEnvelopes.value.length);
/**
 * True while the server list could not be fetched. The merged list keeps the
 * LAST KNOWN state (an empty list would falsely mark every passkey "this
 * device only"), and destructive/promoting actions refuse to run - removing
 * a passkey against stale state would silently leave its server envelope
 * behind, still able to sign in.
 */
const accountEnvelopesStale = ref(false);
const accountPasskeyRestoreLoading = ref(false);
const passkeyUpgradeBusyId = ref<string | null>(null);

/** Best-effort: drives the restore-modal passkey path and the merged passkey list. */
async function refreshAccountEnvelopeCount(): Promise<void> {
  try {
    accountEnvelopes.value = await listAccountEnvelopes();
    accountEnvelopesStale.value = false;
  } catch {
    accountEnvelopesStale.value = true;
  }
}

/** Gate for actions that must see current cloud state; retries once. */
async function ensureAccountEnvelopesFresh(): Promise<boolean> {
  if (accountEnvelopesStale.value) {
    await refreshAccountEnvelopeCount();
  }
  if (accountEnvelopesStale.value) {
    passkeyError.value = t("account.passkey_cloud_state_unavailable");
    return false;
  }
  return true;
}

/**
 * One list for the user, two storage places for us: server envelopes (work
 * on every device) merged with this device's local unlock slots by
 * credential id. Cloud-only entries come from other devices; local-only
 * entries predate the cloud envelope or failed to upload.
 */
type MergedPasskey = {
  key: string;
  label: string;
  lastUsedAt: string | null;
  local: boolean;
  cloud: boolean;
  slot: PasskeySlotMetadata | null;
};

const mergedPasskeys = computed<MergedPasskey[]>(() => {
  const byId = new Map<string, MergedPasskey>();
  for (const envelope of accountEnvelopes.value) {
    byId.set(envelope.credential_id, {
      key: envelope.credential_id,
      label: envelope.label || t("account.passkey_default_label"),
      lastUsedAt: envelope.last_used_at,
      local: false,
      cloud: true,
      slot: null,
    });
  }
  for (const slot of passkeySlots.value) {
    const id = credentialIdToB64Url(slot.credentialIdB64);
    const existing = byId.get(id);
    if (existing) {
      existing.local = true;
      existing.slot = slot;
      existing.label = slot.label;
      existing.lastUsedAt = slot.lastUsedAt ?? existing.lastUsedAt;
    } else {
      byId.set(id, {
        key: id,
        label: slot.label,
        lastUsedAt: slot.lastUsedAt,
        local: true,
        cloud: false,
        slot,
      });
    }
  }
  return [...byId.values()];
});

/** Retry path for a failed (or pre-cloud) envelope upload - needs the session phrase. */
async function enablePasskeyEverywhere(entry: MergedPasskey): Promise<void> {
  if (!entry.slot) return;
  passkeyError.value = "";
  if (!(await ensureAccountEnvelopesFresh())) {
    return;
  }
  if (!getStoredAccountMnemonic()) {
    passkeyError.value = t("account.passkey_enable_needs_unlock");
    return;
  }
  passkeyUpgradeBusyId.value = entry.slot.id;
  try {
    await upgradeAccountPasskey(entry.slot.credentialIdB64, entry.slot.label);
    await refreshAccountEnvelopeCount();
    flashStore.success(t("account.passkey_added"));
  } catch (error) {
    passkeyError.value = passkeyErrorMessage(error);
  } finally {
    passkeyUpgradeBusyId.value = null;
  }
}

/** Remove wherever the passkey lives - the local slot, the server envelope, or both. */
async function removeMergedPasskey(entry: MergedPasskey): Promise<void> {
  passkeyError.value = "";
  if (!(await ensureAccountEnvelopesFresh())) {
    return;
  }
  if (!window.confirm(t("account.passkey_remove_confirm", { label: entry.label }))) {
    return;
  }
  try {
    if (entry.slot) {
      passkeySlots.value = await removeDevicePasskeySlot(entry.slot.id);
    }
    if (entry.cloud) {
      await deleteAccountEnvelope(entry.key);
    }
    await refreshAccountEnvelopeCount();
    flashStore.success(t("account.passkey_removed"));
  } catch {
    passkeyError.value = t("account.device_unlock_failed");
  }
}

/** One passkey gesture instead of retyping 24 words on a new device. */
async function submitRestoreWithAccountPasskey(): Promise<void> {
  restoreOnDeviceError.value = "";
  accountPasskeyRestoreLoading.value = true;
  try {
    const { recoveryPhrase } = await restoreWithAccountPasskey();
    if (!(await confirmRestoreOwnerSwitchIfNeeded(recoveryPhrase))) {
      return;
    }
    resetEvoluBootstrapForRetry();
    const result = await bindRecoveryPhraseOnThisDevice(recoveryPhrase);
    storedGuestMnemonic.value = getStoredGuestMnemonic();
    showRestoreOnDeviceModal.value = false;
    if (result === "restored" || result === "migrated_legacy_owner") {
      const { evolu } = await import("@/evolu/client");
      allowEvoluPageReload();
      evolu.reloadApp();
    }
    flashStore.success(t("account.recovery_phrase_restore_on_device_success"));
  } catch (error) {
    if (error instanceof PasskeyCancelledError) {
      // Dismissed prompt - not an error state.
    } else if (error instanceof PasskeyPrfUnsupportedError) {
      restoreOnDeviceError.value = t("account.passkey_prf_unsupported");
    } else if (error instanceof PasskeyUnsupportedError) {
      restoreOnDeviceError.value = t("account.passkey_unsupported");
    } else {
      restoreOnDeviceError.value = t("account.recovery_phrase_restore_on_device_failed");
    }
  } finally {
    accountPasskeyRestoreLoading.value = false;
  }
}

function resetRestoreOwnerSwitchGuard(): void {
  restoreOwnerSwitchImpact.value = null;
  restoreOwnerSwitchConfirmedFor.value = "";
}

function restorePreviousSessionMnemonic(previousMnemonic: string | null): void {
  clearSessionAccountMnemonic();
  if (previousMnemonic) {
    storeAccountMnemonic(previousMnemonic);
  }
  storedGuestMnemonic.value = getStoredGuestMnemonic();
}

async function confirmRestoreOwnerSwitchIfNeeded(recoveryPhrase: string): Promise<boolean> {
  const recoveryPublicKeyHex = deriveRecoveryPublicKeyHex(recoveryPhrase);
  if (restoreOwnerSwitchConfirmedFor.value !== recoveryPublicKeyHex) {
    const impact = await previewOwnerSwitchImpact(recoveryPhrase);
    if (impact.switches) {
      restoreOwnerSwitchImpact.value = impact;
      restoreOwnerSwitchConfirmedFor.value = recoveryPublicKeyHex;
      return false;
    }
  }
  resetRestoreOwnerSwitchGuard();
  return true;
}


async function submitChangeDevicePassphrase(): Promise<void> {
  changeDevicePassphraseError.value = "";
  if (!isAcceptableDevicePassphrase(newDevicePassphraseInput.value)) {
    changeDevicePassphraseError.value = t("account.device_passphrase_too_weak");
    return;
  }
  try {
    await changeDevicePassphrase(oldDevicePassphraseInput.value, newDevicePassphraseInput.value);
    oldDevicePassphraseInput.value = "";
    newDevicePassphraseInput.value = "";
    showChangeDevicePassphrase.value = false;
    flashStore.success(t("account.device_passphrase_changed"));
  } catch {
    changeDevicePassphraseError.value = t("account.device_unlock_failed");
  }
}
const storedGuestMnemonic = ref<string | null>(null);
const copiedSeed = ref(false);
const localFirst = isInvoicingLocalFirst();

type ProfileTab = "account" | "sync" | "billing" | "data";

function parseProfileTab(value: unknown): ProfileTab {
  if (value === "sync" && localFirst) {
    return "sync";
  }
  if (value === "billing") {
    return "billing";
  }
  if (value === "data") {
    return "data";
  }
  return "account";
}

const activeTab = ref<ProfileTab>(parseProfileTab(route.query.tab));

watch(
  () => route.query.tab,
  (q) => {
    activeTab.value = parseProfileTab(q);
  },
);

function setProfileTab(tab: ProfileTab) {
  activeTab.value = tab;
  const query =
    tab === "account"
      ? Object.fromEntries(
          Object.entries(route.query).filter(([key]) => key !== "tab"),
        )
      : { ...route.query, tab };
  void router.replace({ path: route.path, query });
}

const evoluRelayForm = ref({ url: "" });
const evoluRelaySaving = ref(false);
const relayBuildDefault = computed(() => {
  const build = getEvoluRelayBuildInfo();
  return build.enabled ? build.url : "";
});
const activeRelayUrl = computed(() => getEvoluRelayRuntimeInfo().url);
const evoluLocalStats = ref<InvoicingLocalStats | null>(null);
const evoluRelayUsage = ref<EvoluRelayUsageResponse | null>(null);
const evoluStatsLoading = ref(false);
const evoluRelayUsageChecked = ref(false);
const evoluStatsNeedPhrase = computed(
  () => localFirst && !getStoredAccountMnemonic(),
);

const accountTabBadge = computed(() => {
  if (!authStore.user?.guest_recovery_enrolled) {
    return true;
  }
  return !storedGuestMnemonic.value && !getStoredAccountMnemonic();
});

const syncTabBadge = computed(() => {
  if (!localFirst) {
    return false;
  }
  const relayError = evoluLocalStats.value?.relayError;
  return typeof relayError === "string" && relayError.length > 0;
});

const visibleProfileTabs = computed(() => {
  const tabs: {
    id: ProfileTab;
    labelKey: string;
    badge?: boolean;
    badgeHintKey?: string;
  }[] = [
    {
      id: "account",
      labelKey: "account.tab_account",
      badge: accountTabBadge.value,
      badgeHintKey: "account.tab_account_badge_hint",
    },
  ];
  if (localFirst) {
    tabs.push({
      id: "sync",
      labelKey: "account.tab_sync",
      badge: syncTabBadge.value,
      badgeHintKey: "account.tab_sync_badge_hint",
    });
  }
  tabs.push(
    { id: "billing", labelKey: "account.tab_billing" },
    { id: "data", labelKey: "account.tab_data" },
  );
  return tabs;
});

watch(activeTab, (tab) => {
  if (tab === "sync" && localFirst) {
    void refreshEvoluStats();
  }
});

const documentTypeSummary = computed(() => {
  const byType = evoluLocalStats.value?.counts.documentByType;
  if (!byType) {
    return "";
  }
  const parts = Object.entries(byType)
    .filter(([, count]) => count > 0)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([type, count]) => `${type}: ${count}`);
  return parts.join(", ");
});
type ServerLegacyCompany = {
  id: string;
  legal_name: string;
  contacts_count?: number;
  documents_count?: number;
};
const serverLegacyCompanies = ref<ServerLegacyCompany[]>([]);
const serverLegacyLoading = ref(false);
const serverLegacyDeleting = ref<string | null>(null);

const { planCode: effectivePlanCode } = useCurrentPlan();

// Plan information
const currentPlanName = computed(() => {
  const code = effectivePlanCode.value;
  if (code === "enterprise") return t("account.plan_enterprise");
  if (code === "pro") return t("account.plan_pro");
  return t("account.plan_free");
});

const currentPlanPrice = computed(() => {
  const code = effectivePlanCode.value;
  const p = pricing.value;
  if (code === "enterprise") return "-";
  if (code === "pro") return formatSats(proEffectiveMonthlySats(p.pro));
  return formatSats(p?.free?.sats_per_year ?? 0);
});

const currentPlanDescription = computed(() => {
  const code = effectivePlanCode.value;
  if (code === "enterprise") return t("account.plan_desc_enterprise");
  if (code === "pro") return t("account.plan_desc_pro");
  return t("account.plan_desc_free");
});

const currentPlanFeatures = computed(() => {
  const code = effectivePlanCode.value;
  const keys =
    code === "enterprise"
      ? planFeatures.value.enterprise.feature_keys
      : code === "pro"
        ? planFeatures.value.pro.feature_keys
        : planFeatures.value.free.feature_keys;
  return keys.map((key: string) => t("plans.features." + key));
});

const isPaidPlan = computed(() => {
  const code = effectivePlanCode.value;
  return code === "pro" || code === "enterprise";
});

const isProPlan = computed(() => effectivePlanCode.value === "pro");

// Upgrade options logic
const showUpgradeOptions = computed(() => {
  const role = authStore.user?.role || "free";
  if (authStore.user?.is_guest) {
    return false;
  }
  if (role === "admin" || role === "support") {
    return false;
  }
  return effectivePlanCode.value !== "enterprise";
});

const showProUpgrade = computed(() => effectivePlanCode.value === "free");

const showEnterpriseUpgrade = computed(() => {
  const code = effectivePlanCode.value;
  return code === "free" || code === "pro";
});

onMounted(async () => {
  await Promise.all([loadPricing(), loadPlanFeatures()]);
  storedGuestMnemonic.value = getStoredGuestMnemonic();
  if (localFirst) {
    // Await: the restore_phrase redirect below reads deviceRemembered to pick
    // the modal mode (passphrase unlock vs phrase entry).
    await refreshDeviceRemembered();
  }
  if (authStore.user) {
    profileForm.value.name = authStore.user.name || "";
    evoluRelayForm.value.url = authStore.user.evolu_relay_url ?? "";
  }

  await loadSubscriptionDetails();
  if (localFirst) {
    await loadServerLegacyCompanies();
    void refreshEvoluStats();
  }
  if (route.query.restore_phrase === "1" && !storedGuestMnemonic.value) {
    setProfileTab("account");
    // deviceRemembered was awaited above; if remembered, default to the shorter
    // passphrase unlock. The modal still offers "use recovery phrase instead".
    useRecoveryPhraseInstead.value = !deviceRemembered.value;
    showRestoreOnDeviceModal.value = true;
    flashStore.warning(t("account.recovery_phrase_required_for_invoicing"));
    void router.replace({
      query: { ...route.query, restore_phrase: undefined },
    });
  }
});

async function loadServerLegacyCompanies(): Promise<void> {
  serverLegacyLoading.value = true;
  try {
    serverLegacyCompanies.value = await invoicingApi.companies.list<ServerLegacyCompany>();
  } catch {
    serverLegacyCompanies.value = [];
  } finally {
    serverLegacyLoading.value = false;
  }
}

async function deleteServerLegacyCompany(companyId: string): Promise<void> {
  if (!window.confirm(t("account.server_legacy_companies_delete_confirm"))) {
    return;
  }
  serverLegacyDeleting.value = companyId;
  try {
    await invoicingApi.companies.delete(companyId);
    serverLegacyCompanies.value = serverLegacyCompanies.value.filter(
      (row) => row.id !== companyId,
    );
    flashStore.success(t("account.server_legacy_companies_deleted"));
  } catch (error: unknown) {
    const err = error as { response?: { data?: { message?: string } } };
    flashStore.error(
      err?.response?.data?.message ??
        t("account.server_legacy_companies_delete_failed"),
    );
  } finally {
    serverLegacyDeleting.value = null;
  }
}

async function loadSubscriptionDetails() {
  loadingSubscription.value = true;
  try {
    const response = await api.get("/subscriptions/details");
    subscriber.value = response.data.subscriber;
    subscriptionBilling.value = response.data.billing || null;
    creditBalance.value = response.data.creditBalance || 0;
    creditHistory.value = response.data.creditHistory || [];
  } catch (rawError) {
    const error = asApiError(rawError);
    // If 404, user doesn't have subscription yet - that's ok
    if (error.response?.status !== 404) {
      console.error("Error loading subscription details:", error);
    }
    // Reset values on error
    subscriber.value = null;
    subscriptionBilling.value = null;
    creditBalance.value = 0;
    creditHistory.value = [];
  } finally {
    loadingSubscription.value = false;
  }
}

async function startCreditCheckout(amount: number) {
  const response = await api.post("/subscriptions/credits", {
    amount,
    currency: "SATS",
  });

  const redirectUrl =
    response.data.paymentUrl ||
    response.data.invoiceUrl ||
    response.data.checkoutUrl ||
    null;

  if (redirectUrl) {
    window.location.href = redirectUrl;
    return true;
  }

  alert(t("account.checkout_failed"));
  return false;
}

async function handlePayNow() {
  const amount = subscriptionBilling.value?.nextChargeSats ?? 0;
  if (amount < 1) return;

  payingNow.value = true;
  try {
    const redirected = await startCreditCheckout(amount);
    if (!redirected) {
      payingNow.value = false;
    }
  } catch (rawError) {
    const error = asApiError(rawError);
    console.error("Failed to start subscription payment:", error);
    alert(error.response?.data?.message || t("account.checkout_failed"));
    payingNow.value = false;
  }
}

async function handleAddCredit() {
  if (!creditAmount.value || creditAmount.value < 1) return;

  addingCredit.value = true;
  try {
    const redirected = await startCreditCheckout(creditAmount.value);
    if (!redirected) {
      addingCredit.value = false;
    }
  } catch (rawError) {
    const error = asApiError(rawError);
    console.error("Failed to add credit:", error);
    alert(error.response?.data?.message || t("account.add_credit_failed"));
    addingCredit.value = false;
  }
}

function formatCreditHistoryDate(value: string): string {
  if (!value) return "";
  const date = new Date(value);
  return date.toLocaleDateString(localeTagFor(locale.value), {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

function formatSignedSats(amount: number): string {
  const formatted = formatSats(Math.abs(amount));
  if (amount > 0) return `+${formatted}`;
  if (amount < 0) return `-${formatted}`;
  return formatted;
}

function formatDate(timestamp: number | string): string {
  if (!timestamp) return "";
  const date =
    typeof timestamp === "number"
      ? new Date(timestamp * 1000)
      : new Date(timestamp);
  return date.toLocaleDateString(localeTagFor(locale.value), {
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  });
}

async function upgradePlan(plan: string) {
  upgrading.value = true;
  try {
    const response = await api.post("/subscriptions/checkout", {
      plan: plan,
    });

    if (response.data.checkoutUrl) {
      window.location.href = response.data.checkoutUrl;
    } else {
      alert(t("account.checkout_failed"));
      upgrading.value = false;
    }
  } catch (rawError) {
    const error = asApiError(rawError);
    console.error("Failed to create checkout:", error);
    alert(error.response?.data?.message || t("account.checkout_failed"));
    upgrading.value = false;
  }
}

async function copyStoredSeed() {
  if (!storedGuestMnemonic.value) return;
  try {
    await navigator.clipboard.writeText(storedGuestMnemonic.value);
    copiedSeed.value = true;
    window.setTimeout(() => {
      copiedSeed.value = false;
    }, 2000);
  } catch {
    copiedSeed.value = false;
  }
}

async function handleRecoveryEnrolled(payload: {
  recoveryPublicKeyHex: string;
  mnemonic: string;
}) {
  showRecoveryBackupWizard.value = false;
  try {
    await authStore.enrollGuestRecoveryPublicKey(payload.recoveryPublicKeyHex);
    storeGuestMnemonic(payload.mnemonic);
    try {
      await initEvoluFromAccountSeedIfNeeded(payload.mnemonic);
    } catch (evoluError) {
      // Enrollment succeeded server-side; unavailable local-first storage
      // (private window without OPFS) must not report it as failed.
      if (!isEvoluUnavailableError(evoluError)) {
        throw evoluError;
      }
    }
    storedGuestMnemonic.value = payload.mnemonic;
    flashStore.success(t("account.recovery_phrase_saved"));
  } catch (rawError) {
    const e = asApiError(rawError);
    flashStore.error(
      e?.response?.data?.message || t("account.recovery_phrase_save_failed"),
    );
  }
}

async function submitRestoreOnDevice(): Promise<void> {
  restoreOnDeviceError.value = "";
  restoreOnDeviceLoading.value = true;
  try {
    // Optional "remember this device": validate the passphrase before touching
    // anything, so a weak passphrase fails fast without half-binding.
    const wantsRemember = rememberDeviceOptIn.value;
    if (wantsRemember && !isAcceptableDevicePassphrase(devicePassphraseInput.value)) {
      restoreOnDeviceError.value = t("account.device_passphrase_too_weak");
      restoreOnDeviceLoading.value = false;
      return;
    }

    if (!(await confirmRestoreOwnerSwitchIfNeeded(restoreOnDeviceInput.value))) {
      return;
    }
    resetEvoluBootstrapForRetry();
    const result = await bindRecoveryPhraseOnThisDevice(restoreOnDeviceInput.value);

    if (wantsRemember) {
      // Wrap the just-entered phrase in a device passphrase envelope. If this
      // fails the phrase is still bound for the session - surface the error but
      // do not block the restore.
      try {
        await rememberDeviceWithPassphrase(restoreOnDeviceInput.value, devicePassphraseInput.value);
        await refreshDeviceRemembered();
      } catch {
        flashStore.warning(t("account.device_remember_failed"));
      }
    }

    storedGuestMnemonic.value = getStoredGuestMnemonic();
    showRestoreOnDeviceModal.value = false;
    restoreOnDeviceInput.value = "";
    devicePassphraseInput.value = "";
    rememberDeviceOptIn.value = false;
    if (result === "restored" || result === "migrated_legacy_owner") {
      const { evolu } = await import("@/evolu/client");
      allowEvoluPageReload();
      evolu.reloadApp();
    }
    flashStore.success(t("account.recovery_phrase_restore_on_device_success"));
  } catch {
    restoreOnDeviceError.value = t("account.recovery_phrase_restore_on_device_failed");
  } finally {
    restoreOnDeviceLoading.value = false;
  }
}

const evoluRelayTesting = ref(false);
const evoluRelayTestResult = ref<"ok" | "failed" | null>(null);
const relaySyncDisabledOnDevice = ref(readRelayOverride().kind === "disabled");

/** Probe the ENTERED URL (not the saved one) before committing to it. */
async function testEvoluRelayUrl(): Promise<void> {
  evoluRelayTesting.value = true;
  evoluRelayTestResult.value = null;
  try {
    const url = normalizeEvoluRelayBaseUrl(evoluRelayForm.value.url.trim() || activeRelayUrl.value);
    const reachable = url ? await probeRelayReachability(url) : false;
    evoluRelayTestResult.value = reachable ? "ok" : "failed";
  } catch {
    evoluRelayTestResult.value = "failed";
  } finally {
    evoluRelayTesting.value = false;
  }
}

/**
 * Device-level sync off (P2 phase 1): stored only on this device; the Evolu
 * worker boots with empty transports on next load, so a reload applies it.
 */
function toggleRelaySyncOnDevice(): void {
  if (relaySyncDisabledOnDevice.value) {
    writeRelayOverrideDisabled();
  } else {
    clearRelayOverride();
    cacheProfileIntoOverride();
  }
  window.location.reload();
}

function cacheProfileIntoOverride(): void {
  const normalized = normalizeEvoluRelayBaseUrl(authStore.user?.evolu_relay_url ?? "");
  writeRelayOverrideUrl(normalized);
}

async function saveEvoluRelayUrl(): Promise<void> {
  evoluRelaySaving.value = true;
  try {
    const url = evoluRelayForm.value.url.trim();
    const previous = authStore.user?.evolu_relay_url ?? "";
    await api.put("/user", {
      evolu_relay_url: url !== "" ? url : null,
    });
    await authStore.fetchUser();
    evoluRelayForm.value.url = authStore.user?.evolu_relay_url ?? "";
    // Ensure the worker transport cache matches the saved profile before the
    // reload below boots Evolu (fetchUser also caches; belt and braces).
    if (readRelayOverride().kind !== "disabled") {
      cacheProfileIntoOverride();
    }
    flashStore.success(t("account.evolu_relay_saved"));

    // The Content-Security-Policy connect-src allowlist for the custom relay is
    // built at document load. A full reload gets a fresh CSP header that permits
    // the new relay websocket; a live reconnect alone would be blocked under an
    // enforced CSP. Only reload when the relay origin actually changed.
    if (
      localFirst
      && normalizeEvoluRelayBaseUrl(url) !== normalizeEvoluRelayBaseUrl(previous)
    ) {
      window.location.reload();
      return;
    }
    if (localFirst) {
      const { evolu } = await import("@/evolu/client");
      await refreshEvoluRelaySubscription(evolu);
    }
    void refreshEvoluStats();
  } catch {
    flashStore.error(t("account.evolu_relay_save_failed"));
  } finally {
    evoluRelaySaving.value = false;
  }
}

async function clearEvoluRelayUrl(): Promise<void> {
  evoluRelayForm.value.url = "";
  await saveEvoluRelayUrl();
}

function formatStatsTime(ms: number): string {
  try {
    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(ms));
  } catch {
    return new Date(ms).toISOString();
  }
}

function formatStatsIso(iso: string): string {
  try {
    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date(iso));
  } catch {
    return iso;
  }
}

async function refreshEvoluStats(): Promise<void> {
  if (!localFirst || evoluStatsNeedPhrase.value) {
    evoluLocalStats.value = null;
    evoluRelayUsage.value = null;
    evoluRelayUsageChecked.value = false;
    return;
  }

  evoluStatsLoading.value = true;
  evoluRelayUsageChecked.value = false;
  try {
    await ensureEvoluBoundToAccountSeed();
    const { evolu } = await import("@/evolu/client");
    const stats = await loadInvoicingLocalStats(evolu, { includeDbExport: true });
    evoluLocalStats.value = stats;

    const relayUrl = activeRelayUrl.value;
    if (relayUrl && stats.ownerId) {
      evoluRelayUsage.value = await fetchEvoluRelayUsage(relayUrl, stats.ownerId);
    } else {
      evoluRelayUsage.value = null;
    }
    evoluRelayUsageChecked.value = true;
  } catch {
    evoluLocalStats.value = null;
    evoluRelayUsage.value = null;
    evoluRelayUsageChecked.value = true;
  } finally {
    evoluStatsLoading.value = false;
  }
}

async function handleUpdateProfile() {
  profileLoading.value = true;
  try {
    await api.put("/user", { name: profileForm.value.name ?? null });
    await authStore.fetchUser();
    alert(t("account.profile_updated"));
  } catch (error) {
    alert(t("account.profile_update_failed"));
  } finally {
    profileLoading.value = false;
  }
}

async function handleUpdatePassword() {
  passwordLoading.value = true;
  try {
    await api.put("/user/password", passwordForm.value);
    passwordForm.value = {
      current_password: "",
      password: "",
      password_confirmation: "",
    };
    alert(t("account.password_updated"));
  } catch (error) {
    alert(t("account.password_update_failed"));
  } finally {
    passwordLoading.value = false;
  }
}
</script>
