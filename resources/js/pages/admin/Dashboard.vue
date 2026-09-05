<template>
  <div
    class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar"
  >
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="mb-8">
        <h1 class="text-3xl font-bold text-white">
          {{ t("admin.dashboard.title") }}
        </h1>
        <p class="text-gray-400 mt-1">{{ t("admin.dashboard.description") }}</p>
      </div>

      <!-- Platform Overview (Admin only) -->
      <div v-if="userRole === 'admin'" class="mb-10">
        <h2 class="text-lg font-semibold text-white mb-4">
          {{ t("admin.dashboard.platform_overview") }}
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-6">
          <button
            type="button"
            class="bg-gray-800 rounded-xl p-4 border border-gray-700 hover:border-amber-500/50 transition-all cursor-pointer text-left w-full"
            :aria-label="t('admin.health.title')"
            @click="$router.push('/admin/system-health')"
          >
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.health.title") }}
            </p>
            <p class="text-2xl font-bold text-white" aria-hidden="true">→</p>
            <p class="text-xs text-gray-500 mt-1">{{ t("admin.health.description") }}</p>
          </button>
          <div
            class="bg-gray-800 rounded-xl p-4 border border-gray-700 hover:border-amber-500/50 transition-all cursor-pointer"
            @click="$router.push('/admin/users')"
          >
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.dashboard.users") }}
            </p>
            <p class="text-2xl font-bold text-white">
              {{ loading ? "..." : platformStats.users_total }}
            </p>
            <p
              v-if="platformStats.users_7d > 0"
              class="text-xs text-amber-400 mt-1"
            >
              {{ t("admin.dashboard.new_users_7d") }}
              {{ platformStats.users_7d }}
            </p>
          </div>
          <div
            class="bg-gray-800 rounded-xl p-4 border border-gray-700 hover:border-amber-500/50 transition-all cursor-pointer"
            @click="$router.push('/admin/users')"
          >
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.dashboard.merchants") }}
            </p>
            <p class="text-2xl font-bold text-white">
              {{ loading ? "..." : platformStats.merchants_total }}
            </p>
          </div>
          <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.dashboard.stores") }}
            </p>
            <p class="text-2xl font-bold text-white">
              {{ loading ? "..." : platformStats.stores_total }}
            </p>
            <p
              v-if="platformStats.stores_7d > 0"
              class="text-xs text-amber-400 mt-1"
            >
              {{ t("admin.dashboard.new_stores_7d") }}
              {{ platformStats.stores_7d }}
            </p>
          </div>
          <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.dashboard.pos_terminals") }}
            </p>
            <p class="text-2xl font-bold text-white">
              {{ loading ? "..." : platformStats.pos_terminals_total }}
            </p>
          </div>
          <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.dashboard.pos_orders") }}
            </p>
            <p class="text-2xl font-bold text-white">
              {{ loading ? "..." : platformStats.pos_orders_paid_total }}
            </p>
            <p v-if="posRevenue30d > 0" class="text-xs text-emerald-400 mt-1">
              {{ formatPosAmount(posRevenue30d) }} (30d)
            </p>
            <p
              v-else-if="platformStats.pos_orders_paid_30d > 0"
              class="text-xs text-emerald-400 mt-1"
            >
              {{ platformStats.pos_orders_paid_30d }} (30d)
            </p>
          </div>
          <div class="bg-gray-800 rounded-xl p-4 border border-gray-700">
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.dashboard.volume_sats") }}
            </p>
            <p class="text-2xl font-bold text-white">
              {{ loading ? "..." : formatSats(platformStats.volume_sats_total) }}
            </p>
            <p v-if="platformStats.volume_sats_30d > 0" class="text-xs text-emerald-400 mt-1">
              {{ formatSats(platformStats.volume_sats_30d) }} (30d)
            </p>
          </div>
          <div
            class="bg-gray-800 rounded-xl p-4 border border-gray-700 hover:border-purple-500/50 transition-all cursor-pointer"
            @click="$router.push('/support/wallet-connections')"
          >
            <p class="text-gray-400 text-xs font-medium mb-1">
              {{ t("admin.dashboard.wallet_needs_support") }}
            </p>
            <p
              class="text-2xl font-bold"
              :class="
                platformStats.wallet_connections_needs_support > 0
                  ? 'text-amber-400'
                  : 'text-white'
              "
            >
              {{
                loading ? "..." : platformStats.wallet_connections_needs_support
              }}
            </p>
          </div>
        </div>
        <div class="flex flex-wrap gap-4 mb-6 items-center">
          <div
            class="bg-gray-800/50 rounded-lg px-4 py-2 border border-gray-700"
          >
            <span class="text-gray-400 text-sm"
              >{{ t("admin.dashboard.paid_plans") }}:</span
            >
            <span class="text-white font-semibold ml-2">{{
              loading ? "..." : platformStats.paid_plan_count
            }}</span>
          </div>
          <div class="flex items-center gap-2">
            <span class="text-gray-400 text-sm"
              >{{ t("admin.dashboard.currency_toggle") }}:</span
            >
            <div class="flex rounded-lg border border-gray-600 overflow-hidden">
              <button
                type="button"
                :class="[
                  'px-3 py-1.5 text-xs font-medium transition-colors',
                  amountCurrency === 'sats'
                    ? 'bg-amber-600 text-white'
                    : 'text-gray-400 hover:text-white hover:bg-gray-700',
                ]"
                @click="amountCurrency = 'sats'"
              >
                sats
              </button>
              <button
                type="button"
                :class="[
                  'px-3 py-1.5 text-xs font-medium transition-colors',
                  amountCurrency === 'eur'
                    ? 'bg-amber-600 text-white'
                    : 'text-gray-400 hover:text-white hover:bg-gray-700',
                ]"
                @click="amountCurrency = 'eur'"
              >
                €
              </button>
            </div>
          </div>
        </div>

        <!-- Trends chart -->
        <div
          v-if="trendsChartData.length > 0"
          class="bg-gray-800 rounded-xl p-6 border border-gray-700"
        >
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold text-white">
              {{ t("admin.dashboard.trends_30d") }}
            </h3>
            <div class="flex rounded-lg border border-gray-600 overflow-hidden">
              <button
                type="button"
                :class="[
                  'px-3 py-1.5 text-xs font-medium transition-colors',
                  trendPeriod === '7d'
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-400 hover:text-white hover:bg-gray-700',
                ]"
                @click="trendPeriod = '7d'"
              >
                7d
              </button>
              <button
                type="button"
                :class="[
                  'px-3 py-1.5 text-xs font-medium transition-colors',
                  trendPeriod === '30d'
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-400 hover:text-white hover:bg-gray-700',
                ]"
                @click="trendPeriod = '30d'"
              >
                30d
              </button>
            </div>
          </div>
          <!-- Small multiples: one scale per measure (users and stores are
               unit counts, PoS volume is sats/EUR - a shared axis squashed the
               counts into invisible slivers). Shared BarChart per measure. -->
          <div class="space-y-5">
            <div v-for="metric in trendMetrics" :key="metric.key">
              <p class="flex items-center gap-2 text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">
                <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: metric.color }" />
                {{ metric.label }}
              </p>
              <BarChart
                :points="metric.points"
                :color="metric.color"
                :format-value="metric.format"
                height-class="h-20"
              />
            </div>
          </div>
          <div class="flex justify-end items-center mt-3">

            <button
              type="button"
              :disabled="exportingStats"
              @click="downloadStatsExport"
              class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-300 bg-gray-700/50 rounded-lg hover:bg-gray-600/50 border border-gray-600 transition-colors disabled:opacity-50"
            >
              <svg
                v-if="exportingStats"
                class="w-4 h-4 animate-spin"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
              <svg
                v-else
                class="w-4 h-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                />
              </svg>
              {{ exportingStats ? "..." : t("admin.dashboard.export_stats") }}
            </button>
          </div>
        </div>

        <!-- Top stores by PoS orders -->
        <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 mt-6">
          <h3 class="text-base font-semibold text-white mb-4">
            {{ t("admin.dashboard.top_stores_pos") }}
          </h3>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-700">
              <thead class="bg-gray-900/50">
                <tr>
                  <th
                    class="px-4 py-2 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    #
                  </th>
                  <th
                    class="px-4 py-2 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("admin.dashboard.stores") }}
                  </th>
                  <th
                    class="px-4 py-2 text-left text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("admin.dashboard.merchants") }}
                  </th>
                  <th
                    class="px-4 py-2 text-right text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ t("admin.dashboard.pos_orders") }}
                  </th>
                  <th
                    class="px-4 py-2 text-right text-xs font-medium text-gray-400 uppercase tracking-wider"
                  >
                    {{ amountCurrency === "sats" ? "sats" : "€" }}
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-700">
                <tr v-if="platformStats.top_stores_by_pos_orders.length === 0">
                  <td colspan="5" class="px-4 py-8 text-center text-gray-400">
                    {{ t("admin.dashboard.top_stores_empty") }}
                  </td>
                </tr>
                <tr
                  v-for="(s, idx) in platformStats.top_stores_by_pos_orders"
                  :key="s.store_id"
                  class="hover:bg-gray-700/30 transition-colors"
                >
                  <td class="px-4 py-3 text-sm text-gray-400">{{ idx + 1 }}</td>
                  <td class="px-4 py-3 text-sm font-medium text-white">
                    {{ s.store_name }}
                  </td>
                  <td class="px-4 py-3 text-sm text-gray-400">
                    {{ s.user_email || "-" }}
                  </td>
                  <td
                    class="px-4 py-3 text-sm text-right font-semibold text-emerald-400"
                  >
                    {{ s.pos_orders_paid }}
                  </td>
                  <td
                    class="px-4 py-3 text-sm text-right font-medium text-amber-400"
                  >
                    {{
                      formatPosAmount(
                        amountCurrency === "sats"
                          ? (s.pos_amount_sats ?? 0)
                          : (s.pos_amount_eur ?? 0),
                      )
                    }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Quick Stats -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Users Card -->
        <div
          v-if="userRole === 'admin'"
          class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg relative overflow-hidden group hover:border-indigo-500/50 transition-all cursor-pointer"
          @click="$router.push('/admin/users')"
        >
          <div
            class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity"
          >
            <svg
              class="w-24 h-24 text-indigo-500"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"
              />
            </svg>
          </div>
          <div class="relative z-10">
            <p class="text-gray-400 text-sm font-medium mb-1">
              {{ t("admin.dashboard.users") }}
            </p>
            <h3 class="text-3xl font-bold text-white">
              {{ loading ? "..." : userCount }}
            </h3>
            <div class="mt-4">
              <span
                class="text-sm font-medium text-indigo-400 hover:text-indigo-300 flex items-center"
              >
                {{ t("admin.dashboard.manage_users") }}
                <svg
                  class="w-4 h-4 ml-1"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M17 8l4 4m0 0l-4 4m4-4H3"
                  ></path>
                </svg>
              </span>
            </div>
          </div>
        </div>

        <!-- FAQ Card -->
        <div
          class="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg relative overflow-hidden group hover:border-green-500/50 transition-all cursor-pointer"
          @click="$router.push('/admin/faq')"
        >
          <div
            class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity"
          >
            <svg
              class="w-24 h-24 text-green-500"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
          </div>
          <div class="relative z-10">
            <p class="text-gray-400 text-sm font-medium mb-1">
              {{ t("admin.dashboard.faq") }}
            </p>
            <h3 class="text-3xl font-bold text-white">
              {{ loading ? "..." : faqCount }}
            </h3>
            <div class="mt-4">
              <span
                class="text-sm font-medium text-green-400 hover:text-green-300 flex items-center"
              >
                {{ t("admin.dashboard.manage_faq") }}
                <svg
                  class="w-4 h-4 ml-1"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M17 8l4 4m0 0l-4 4m4-4H3"
                  ></path>
                </svg>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Admin Sections Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- User Management (Admin only) -->
        <div
          v-if="userRole === 'admin'"
          class="bg-gray-800 rounded-xl p-6 border border-gray-700 hover:border-indigo-500/50 transition-all group cursor-pointer"
          @click="$router.push('/admin/users')"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="p-3 bg-indigo-500/10 rounded-lg">
              <svg
                class="w-6 h-6 text-indigo-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"
                />
              </svg>
            </div>
            <svg
              class="w-5 h-5 text-gray-400 group-hover:text-indigo-400 transition-colors"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </div>
          <h3 class="text-lg font-semibold text-white mb-2">
            {{ t("admin.dashboard.user_management") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("admin.dashboard.user_management_desc") }}
          </p>
          <span class="text-sm text-indigo-400 font-medium">{{
            t("admin.dashboard.view_section")
          }}</span>
        </div>

        <!-- FAQ Items -->
        <div
          class="bg-gray-800 rounded-xl p-6 border border-gray-700 hover:border-green-500/50 transition-all group cursor-pointer"
          @click="$router.push('/admin/faq')"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="p-3 bg-green-500/10 rounded-lg">
              <svg
                class="w-6 h-6 text-green-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
            </div>
            <svg
              class="w-5 h-5 text-gray-400 group-hover:text-green-400 transition-colors"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </div>
          <h3 class="text-lg font-semibold text-white mb-2">
            {{ t("admin.dashboard.faq_items") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("admin.dashboard.faq_items_desc") }}
          </p>
          <span class="text-sm text-green-400 font-medium">{{
            t("admin.dashboard.view_section")
          }}</span>
        </div>

        <!-- RegWatch (admin only) -->
        <div
          v-if="userRole === 'admin'"
          class="bg-gray-800 rounded-xl p-6 border border-gray-700 hover:border-amber-500/50 transition-all group cursor-pointer"
          @click="$router.push('/admin/regwatch')"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="p-3 bg-amber-500/10 rounded-lg">
              <svg
                class="w-6 h-6 text-amber-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"
                />
              </svg>
            </div>
            <svg
              class="w-5 h-5 text-gray-400 group-hover:text-amber-400 transition-colors"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </div>
          <h3 class="text-lg font-semibold text-white mb-2">
            {{ t("admin.dashboard.regwatch") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("admin.dashboard.regwatch_desc") }}
          </p>
          <span class="text-sm text-amber-400 font-medium">{{
            t("admin.dashboard.view_section")
          }}</span>
        </div>

        <!-- E-faktura CPDS presets (admin only) -->
        <RouterLink
          v-if="userRole === 'admin'"
          to="/admin/efaktura-cpds"
          class="block bg-gray-800 rounded-xl p-6 border border-gray-700 hover:border-emerald-500/50 transition-all group cursor-pointer"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="p-3 bg-emerald-500/10 rounded-lg">
              <svg
                class="w-6 h-6 text-emerald-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                />
              </svg>
            </div>
            <svg
              class="w-5 h-5 text-gray-400 group-hover:text-emerald-400 transition-colors"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </div>
          <h3 class="text-lg font-semibold text-white mb-2">
            {{ t("admin.dashboard.efaktura_cpds") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("admin.dashboard.efaktura_cpds_desc") }}
          </p>
          <span class="text-sm text-emerald-400 font-medium">{{
            t("admin.dashboard.view_section")
          }}</span>
        </RouterLink>

        <!-- Wallet change log (admin only) -->
        <RouterLink
          v-if="userRole === 'admin'"
          to="/admin/wallet-changes"
          class="block bg-gray-800 rounded-xl p-6 border border-gray-700 hover:border-red-500/50 transition-all group cursor-pointer"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="p-3 bg-red-500/10 rounded-lg">
              <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 9v2m0 4h.01M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                />
              </svg>
            </div>
          </div>
          <h3 class="text-lg font-semibold text-white mb-2 group-hover:text-red-400 transition-colors">
            {{ t("admin.dashboard.wallet_changes") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("admin.dashboard.wallet_changes_desc") }}
          </p>
          <span class="text-sm text-red-400 font-medium">{{
            t("admin.dashboard.view_section")
          }}</span>
        </RouterLink>

        <!-- Support Tools (if support role) -->
        <div
          v-if="userRole === 'support' || userRole === 'admin'"
          class="bg-gray-800 rounded-xl p-6 border border-gray-700 hover:border-purple-500/50 transition-all group cursor-pointer"
          @click="$router.push('/support/wallet-connections')"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="p-3 bg-purple-500/10 rounded-lg">
              <svg
                class="w-6 h-6 text-purple-400"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"
                />
              </svg>
            </div>
            <svg
              class="w-5 h-5 text-gray-400 group-hover:text-purple-400 transition-colors"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </div>
          <h3 class="text-lg font-semibold text-white mb-2">
            {{ t("admin.dashboard.wallet_connections") }}
          </h3>
          <p class="text-gray-400 text-sm mb-4">
            {{ t("admin.dashboard.wallet_connections_desc") }}
          </p>
          <span class="text-sm text-purple-400 font-medium">{{
            t("admin.dashboard.view_section")
          }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useAuthStore } from "../../store/auth";
import { adminFaqApi } from "../../services/api";
import api from "../../services/api";
import BarChart from "../../components/charts/BarChart.vue";

const { t } = useI18n();
const authStore = useAuthStore();

const loading = ref(false);
const userCount = ref(0);
const faqCount = ref(0);

const platformStats = ref({
  users_total: 0,
  users_7d: 0,
  users_30d: 0,
  merchants_total: 0,
  stores_total: 0,
  stores_7d: 0,
  stores_30d: 0,
  pos_terminals_total: 0,
  apps_total: 0,
  pos_orders_paid_total: 0,
  pos_orders_paid_30d: 0,
  volume_sats_total: 0,
  volume_sats_30d: 0,
  pos_orders_amount_30d_sats: 0,
  pos_orders_amount_30d_eur: 0,
  pos_orders_amount_total_sats: 0,
  pos_orders_amount_total_eur: 0,
  wallet_connections_needs_support: 0,
  paid_plan_count: 0,
  trends_7d: [] as {
    date: string;
    users: number;
    stores: number;
    pos_orders: number;
    pos_amount_sats?: number;
    pos_amount_eur?: number;
  }[],
  trends_30d: [] as {
    date: string;
    users: number;
    stores: number;
    pos_orders: number;
    pos_amount_sats?: number;
    pos_amount_eur?: number;
  }[],
  top_stores_by_pos_orders: [] as {
    store_id: string;
    store_name: string;
    user_email: string | null;
    pos_orders_paid: number;
    pos_amount_sats?: number;
    pos_amount_eur?: number;
  }[],
});

const userRole = computed(() => authStore.user?.role || "");

const trendPeriod = ref<"7d" | "30d">("30d");
const amountCurrency = ref<"sats" | "eur">("eur");

const posRevenue30d = computed(() =>
  amountCurrency.value === "sats"
    ? (platformStats.value.pos_orders_amount_30d_sats ?? 0)
    : (platformStats.value.pos_orders_amount_30d_eur ?? 0),
);

function posAmountForDay(
  d: { pos_amount_sats?: number; pos_amount_eur?: number } | undefined,
): number {
  if (!d) return 0;
  return amountCurrency.value === "sats"
    ? (d.pos_amount_sats ?? 0)
    : (d.pos_amount_eur ?? 0);
}

function formatSats(value: number): string {
  return new Intl.NumberFormat("en-US").format(Math.round(value || 0)) + " sats";
}

function formatPosAmount(n: number): string {
  if (amountCurrency.value === "sats") {
    return new Intl.NumberFormat().format(Math.round(n)) + " sats";
  }
  return (
    new Intl.NumberFormat(undefined, {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(n) + " €"
  );
}

const trendsChartData = computed(() => {
  const key = trendPeriod.value === "7d" ? "trends_7d" : "trends_30d";
  return platformStats.value[key] ?? [];
});
const exportingStats = ref(false);

async function downloadStatsExport() {
  exportingStats.value = true;
  try {
    const res = await api.get("/admin/stats/export", { responseType: "blob" });
    const blob = new Blob([res.data], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `platform-stats-${new Date().toISOString().slice(0, 19).replace(/[:-]/g, "")}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  } catch (e) {
    console.error("Export failed:", e);
  } finally {
    exportingStats.value = false;
  }
}

/**
 * Small-multiple definitions for the trends card: one BarChart per measure,
 * each with its own scale (counts vs sats/EUR volume must never share an
 * axis). Colors are fixed per measure.
 */
const trendMetrics = computed(() => {
  const days = trendsChartData.value;
  const toPoints = (pick: (d: (typeof days)[number]) => number) =>
    days.map((d) => ({ label: formatChartDate(d.date), value: pick(d) }));

  return [
    {
      key: "users",
      label: t("admin.dashboard.users"),
      color: "#6366f1",
      points: toPoints((d) => d.users),
      format: (v: number) => String(Math.round(v)),
    },
    {
      key: "stores",
      label: t("admin.dashboard.stores"),
      color: "#10b981",
      points: toPoints((d) => d.stores),
      format: (v: number) => String(Math.round(v)),
    },
    {
      key: "pos",
      label: t("admin.dashboard.pos_volume", {
        currency: amountCurrency.value === "sats" ? "sats" : "€",
      }),
      color: "#f59e0b",
      points: toPoints((d) => posAmountForDay(d)),
      format: (v: number) => formatPosAmount(v),
    },
  ];
});

function formatChartDate(dateStr: string | undefined): string {
  if (!dateStr) return "";
  const d = new Date(dateStr);
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

const loadStats = async () => {
  loading.value = true;
  try {
    // Load platform stats (admin only) - try whenever we might be admin
    try {
      const statsResponse = await api.get("/admin/stats");
      platformStats.value = { ...platformStats.value, ...statsResponse.data };
      userCount.value = platformStats.value.users_total;
    } catch (error) {
      if (userRole.value === "admin") {
        console.error("Failed to load platform stats:", error);
      }
      // Fallback: load user count from users endpoint
      try {
        const userResponse = await api.get("/admin/users", {
          params: { per_page: 1 },
        });
        userCount.value = userResponse.data.meta?.total || 0;
      } catch (e) {
        // Ignore - user may not have admin access
      }
    }

    // Load FAQ count
    try {
      const faqResponse = await adminFaqApi.items.index();
      faqCount.value =
        faqResponse.data.meta?.total || faqResponse.data.data?.length || 0;
    } catch (error) {
      console.error("Failed to load FAQ count:", error);
    }

    // Load user count for non-admin (support sees users card? No - Users card is admin only. So we're good.)
  } finally {
    loading.value = false;
  }
};

onMounted(() => {
  loadStats();
});

// Re-load platform stats when user becomes admin (e.g. auth loaded after mount)
watch(
  () => userRole.value,
  (role: string) => {
    if (role === "admin") {
      loadStats();
    }
  },
  { immediate: false },
);
</script>
