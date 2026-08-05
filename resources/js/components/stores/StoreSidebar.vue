<template>
  <!-- Mobile menu button -->
  <button
    v-if="store"
    @click="showMobileMenu = !showMobileMenu"
    class="fixed top-4 left-4 z-50 lg:hidden p-2 bg-gray-800 text-white rounded-md"
  >
    <!-- Arrow right when closed, arrow left when open -->
    <svg
      v-if="!showMobileMenu"
      class="w-6 h-6"
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
    <svg
      v-else
      class="w-6 h-6"
      fill="none"
      stroke="currentColor"
      viewBox="0 0 24 24"
    >
      <path
        stroke-linecap="round"
        stroke-linejoin="round"
        stroke-width="2"
        d="M15 19l-7-7 7-7"
      />
    </svg>
  </button>

  <!-- Mobile overlay -->
  <div
    v-if="showMobileMenu"
    class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
    @click="showMobileMenu = false"
  ></div>

  <aside
    v-if="store"
    class="fixed lg:static inset-y-0 pt-12 md:pt-0 left-0 z-40 w-64 bg-gray-800 text-white flex-shrink-0 min-h-full transform transition-transform duration-300 ease-in-out flex flex-col"
    :class="{ '-translate-x-full lg:translate-x-0': !showMobileMenu }"
  >
    <div class="flex-1 overflow-y-auto p-4">
      <!-- Store Selector Dropdown -->
      <div class="mb-6 relative store-dropdown-container">
        <button
          @click.stop="showStoreDropdown = !showStoreDropdown"
          class="w-full flex items-center justify-between px-3 py-2 bg-gray-700 hover:bg-gray-600 rounded-md text-left transition-colors"
        >
          <span class="text-sm font-medium truncate flex-1">{{
            store.name
          }}</span>
          <svg
            class="w-4 h-4 ml-2 flex-shrink-0 transition-transform"
            :class="{ 'rotate-180': showStoreDropdown }"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M19 9l-7 7-7-7"
            />
          </svg>
        </button>

        <!-- Dropdown Menu -->
        <div
          v-if="showStoreDropdown"
          class="absolute z-50 mt-1 w-full bg-white rounded-md shadow-lg ring-1 ring-black ring-opacity-5 max-h-96 overflow-y-auto"
          @click.stop
        >
          <div class="py-1">
            <!-- Active Store List (non-archived) -->
            <button
              v-for="s in activeStores"
              :key="s.id"
              @click="selectStore(s.id)"
              class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors"
              :class="{ 'bg-gray-100 font-medium': s.id === store.id }"
            >
              <div class="flex items-center">
                <svg
                  v-if="s.id === store.id"
                  class="w-4 h-4 mr-2 text-indigo-600"
                  fill="currentColor"
                  viewBox="0 0 20 20"
                >
                  <path
                    fill-rule="evenodd"
                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                    clip-rule="evenodd"
                  />
                </svg>
                <span class="truncate">{{ s.name }}</span>
              </div>
            </button>

            <!-- Divider -->
            <div
              v-if="activeStores.length > 0"
              class="border-t border-gray-200 my-1"
            ></div>

            <!-- Create Store Button -->
            <button
              @click="createStore"
              type="button"
              class="w-full text-left px-4 py-2 text-sm font-medium transition-colors flex items-center"
              :class="
                isGuestUser && activeStores.length >= 1
                  ? 'text-gray-400 hover:bg-gray-50 cursor-pointer border-l-2 border-amber-500/50'
                  : 'text-indigo-600 hover:bg-gray-100'
              "
              :title="
                isGuestUser && activeStores.length >= 1
                  ? t('stores.guest_one_store_hint')
                  : undefined
              "
            >
              <svg
                class="w-4 h-4 mr-2 shrink-0"
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
              <span class="flex-1 text-left">{{
                t("stores.create_store")
              }}</span>
              <span
                v-if="isGuestUser && activeStores.length >= 1"
                class="text-[10px] uppercase tracking-wide text-amber-600/90 shrink-0 ml-1"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>

            <!-- Archived Stores -->
            <div
              v-if="archivedStores.length > 0"
              class="border-t border-gray-200 my-1"
            ></div>
            <div
              v-if="archivedStores.length > 0"
              class="px-4 py-2 text-xs font-medium text-gray-500 uppercase tracking-wider"
            >
              {{ archivedStores.length }}
              {{
                archivedStores.length === 1
                  ? t("stores.archived_store")
                  : t("stores.archived_stores")
              }}
            </div>
            <button
              v-for="s in archivedStores"
              :key="s.id"
              @click="selectStore(s.id)"
              class="w-full text-left px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 transition-colors"
              :class="{ 'bg-gray-100 font-medium': s.id === store.id }"
            >
              <div class="flex items-center">
                <svg
                  v-if="s.id === store.id"
                  class="w-4 h-4 mr-2 text-indigo-600"
                  fill="currentColor"
                  viewBox="0 0 20 20"
                >
                  <path
                    fill-rule="evenodd"
                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                    clip-rule="evenodd"
                  />
                </svg>
                <span class="truncate">{{ s.name }}</span>
              </div>
            </button>
          </div>
        </div>
      </div>

      <!-- Store Name + Wallet & Settings icons -->
      <div
        class="flex items-center gap-2 mb-3 py-3 border-y border-gray-300 border-dotted"
      >
        <AppNavLink :href="`/stores/${store.id}`"
          class="flex items-center min-w-0 flex-1 text-md font-semibold text-white hover:text-gray-200 transition-colors cursor-pointer"
          @click="showMobileMenu = false">
          <img
            v-if="store.logo_url"
            :src="store.logo_url"
            :alt="`${store.name} logo`"
            class="mr-2 h-8 w-8 object-contain rounded flex-shrink-0"
          />
          <span class="truncate">{{ store.name }}</span>
        </AppNavLink>
        <div class="flex items-center gap-1 flex-shrink-0">
          <!-- Wallet connection (plug / unplug by status) -->
          <AppNavLink :href="`/stores/${store.id}/wallet-connection`"
            :title="t('stores.wallet_connection')"
            class="p-1.5 rounded-md transition-colors"
            :class="[
              isLinkActive(
                `/stores/${store.id}/wallet-connection`,
                'stores-wallet-connection',
              )
                ? 'bg-gray-700 text-white'
                : 'text-gray-400 hover:bg-gray-700 hover:text-white',
              getWalletConnectionIconClass(),
            ]"
            @click="showMobileMenu = false">
            <!-- Link (connected) vs LinkSlash (disconnected / needs support / pending) -->
            <svg
              v-if="
                store?.wallet_type === 'cashu' ||
                store?.wallet_connection?.status === 'connected'
              "
              class="w-5 h-5"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"
              />
            </svg>
            <svg
              v-else
              class="w-5 h-5"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"
              />
            </svg>
          </AppNavLink>
          <!-- Store settings (onboarding pos-5) -->
          <button
            type="button"
            data-onboarding="pos-5"
            :title="t('stores.store_settings')"
            class="p-1.5 rounded-md transition-colors text-gray-400 hover:bg-gray-700 hover:text-white"
            :class="{ 'bg-gray-700 text-white': isStoreShowSettings }"
            @click="
              $emit('show-settings');
              showMobileMenu = false;
            "
          >
            <svg
              class="w-5 h-5"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
              />
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
              />
            </svg>
          </button>
        </div>
      </div>

      <!-- PAYMENTS Section -->
      <div class="mb-8">
        <h3
          class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3"
        >
          {{ t("stores.payments") }}
        </h3>
        <nav class="space-y-1">
          <button
            @click="handleSectionClick('invoices')"
            class="w-full flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors text-left"
            :class="
              querySection === 'invoices'
                ? 'bg-gray-900 text-white'
                : 'text-gray-300 hover:bg-gray-700 hover:text-white'
            "
          >
            <svg
              class="w-5 h-5 mr-3"
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
            {{ t("stores.invoices") }}
          </button>

          <button
            v-if="store?.wallet_type === 'cashu'"
            type="button"
            class="w-full flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors text-left"
            :class="
              querySection === 'cashu'
                ? 'bg-gray-900 text-white'
                : 'text-gray-300 hover:bg-gray-700 hover:text-white'
            "
            @click="handleSectionClick('cashu')"
          >
            <svg
              class="w-5 h-5 mr-3 shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            {{ t("stores.cashu_nav_payments") }}
          </button>
          <button
            type="button"
            @click="handleReportsClick"
            class="w-full flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors text-left border border-transparent"
            :class="
              isGuestUser
                ? 'text-gray-400 opacity-80 hover:opacity-100 cursor-pointer border-amber-500/25'
                : querySection === 'reports'
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
            "
            :title="isGuestUser ? t('stores.guest_nav_locked_hint') : undefined"
          >
            <svg
              class="w-5 h-5 mr-3 shrink-0"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            <span class="flex-1 min-w-0">{{ t("stores.reports") }}</span>
            <span
              v-if="showReportsProBadge"
              class="ml-2 text-xs px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-400 border border-amber-500/30 inline-flex items-center shrink-0"
              ><ProPlanBadge
            /></span>
            <span
              v-else-if="isGuestUser"
              class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0 ml-1"
              >{{ t("stores.guest_nav_locked_short") }}</span
            >
          </button>
        </nav>
      </div>

      <!-- APPS Section -->
      <div class="mb-8">
        <h3
          class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3"
        >
          {{ t("stores.apps") }}
        </h3>
        <nav class="space-y-1">
          <!-- LN Address -->
          <div class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('stores.ln_address')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"
                  />
                </svg>
                {{ t("stores.ln_address") }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0 ml-1"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/lightning-addresses`" v-else
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium transition-colors"
              :class="
                isLinkActive(
                  `/stores/${store.id}/lightning-addresses`,
                  'stores-lightning-addresses',
                )
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"
                  />
                </svg>
                {{ t("stores.ln_address") }}
              </span>
              <span
                v-if="limits?.ln_addresses?.max != null"
                class="ml-2 bg-gray-700 text-gray-300 text-xs px-2 py-0.5 rounded-full shrink-0"
                >{{ limits.ln_addresses.current }} /
                {{ limits.ln_addresses.max }}</span
              >
              <span
                v-else-if="limits?.ln_addresses?.unlimited"
                class="ml-2 text-xs text-gray-500 shrink-0"
                >∞</span
              >
            </AppNavLink>
          </div>

          <!-- Point of Sale -->
          <div class="mb-2">
            <button
              v-if="guestPosCreateLocked"
              type="button"
              data-onboarding="pos-1"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_pos_limit_hint')"
              @click="openGuestUpgradeForFeature('stores.point_of_sale')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z M9 7h6m-6 3h.01m0 3h.01m0 3h.01m6-9h.01m0 3h.01m0 3h.01m0 3h.01"
                  />
                </svg>
                {{ t("stores.point_of_sale") }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/apps/create?type=PointOfSale`" v-else
              data-onboarding="pos-1"
              class="flex items-center justify-between px-3 py-2 rounded-md text-sm font-medium transition-colors cursor-pointer"
              :class="
                isAppsCreateWithType('PointOfSale')
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z M9 7h6m-6 3h.01m0 3h.01m0 3h.01m6-9h.01m0 3h.01m0 3h.01m0 3h.01"
                  />
                </svg>
                {{ t("stores.point_of_sale") }}
              </span>
              <svg
                class="w-4 h-4 flex-shrink-0"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 4v16m8-8H4"
                />
              </svg>
            </AppNavLink>
            <div
              v-if="getAppsByType('PointOfSale').length > 0"
              class="ml-4 space-y-1"
            >
              <AppNavLink :href="`/stores/${store.id}/apps/${app.id}`" v-for="app in getAppsByType('PointOfSale')"
                :key="app.id"
                class="block px-3 py-2 rounded-md text-sm transition-colors"
                :class="
                  paramAppId === app.id
                    ? 'bg-gray-900 text-white'
                    : 'text-gray-400 hover:bg-gray-700 hover:text-white'
                "
                @click="showMobileMenu = false">
                {{ app.name }}
              </AppNavLink>
            </div>
            <div v-else class="ml-4 text-xs text-gray-500">
              {{ t("stores.no_pos") }}
            </div>
          </div>

          <!-- Crowdfund -->
          <div class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('stores.crowdfund')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                  />
                </svg>
                {{ t("stores.crowdfund") }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/apps/create?type=Crowdfund`" v-else
              class="flex items-center justify-between px-3 py-2 rounded-md text-sm font-medium transition-colors cursor-pointer"
              :class="
                isAppsCreateWithType('Crowdfund')
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                  />
                </svg>
                {{ t("stores.crowdfund") }}
              </span>
              <svg
                class="w-4 h-4 flex-shrink-0"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 4v16m8-8H4"
                />
              </svg>
            </AppNavLink>
            <div
              v-if="!isGuestUser && getAppsByType('Crowdfund').length > 0"
              class="ml-4 space-y-1"
            >
              <AppNavLink :href="`/stores/${store.id}/apps/${app.id}`" v-for="app in getAppsByType('Crowdfund')"
                :key="app.id"
                class="block px-3 py-2 rounded-md text-sm transition-colors"
                :class="
                  paramAppId === app.id
                    ? 'bg-gray-900 text-white'
                    : 'text-gray-400 hover:bg-gray-700 hover:text-white'
                "
                @click="showMobileMenu = false">
                {{ app.name }}
              </AppNavLink>
            </div>
            <div v-else-if="!isGuestUser" class="ml-4 text-xs text-gray-500">
              {{ t("stores.no_crowdfund") }}
            </div>
          </div>

          <!-- Pay Button -->
          <div class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('stores.pay_button')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 3.219M3.239 7.188l3.22.777M6.5 15.205l-3.75-.75M15.205 6.5l-.75-3.75"
                  />
                </svg>
                {{ t("stores.pay_button") }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/pay-button`" v-else
              class="flex items-center px-3 py-2 rounded-md text-sm font-medium transition-colors"
              :class="
                isLinkActive(
                  `/stores/${store.id}/pay-button`,
                  'stores-pay-button',
                )
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <svg
                class="w-5 h-5 mr-3 flex-shrink-0"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
                aria-hidden="true"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 3.219M3.239 7.188l3.22.777M6.5 15.205l-3.75-.75M15.205 6.5l-.75-3.75"
                />
              </svg>
              <span>{{ t("stores.pay_button") }}</span>
            </AppNavLink>
          </div>

          <!-- Raffles (BTCPay plugin; shown after availability probe, always for guests) -->
          <div v-if="showRaffleNav" class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('apps.raffles')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"
                  />
                </svg>
                {{ t("apps.raffles") }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/raffles`" v-else
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium transition-colors"
              :class="
                isLinkActive(`/stores/${store.id}/raffles`, 'stores-raffles') ||
                isLinkActive(
                  `/stores/${store.id}/raffles/create`,
                  'stores-raffles-create',
                ) ||
                currentPath.includes(`/stores/${store.id}/raffles/`)
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"
                  />
                </svg>
                {{ t("apps.raffles") }}
              </span>
              <span
                v-if="limits?.raffles?.max != null"
                class="ml-2 bg-gray-700 text-gray-300 text-xs px-2 py-0.5 rounded-full shrink-0"
                >{{ limits.raffles.current ?? 0 }} /
                {{ limits.raffles.max }}</span
              >
              <span
                v-else-if="limits?.raffles?.unlimited"
                class="ml-2 text-xs text-gray-500 shrink-0"
                >∞</span
              >
            </AppNavLink>
          </div>

          <!-- Tickets (store-level events, like LN Address) -->
          <div class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('apps.tickets')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"
                  />
                </svg>
                {{ t("apps.tickets") }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/tickets`" v-else
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium transition-colors"
              :class="
                isLinkActive(`/stores/${store.id}/tickets`, 'stores-tickets')
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"
                  />
                </svg>
                {{ t("apps.tickets") }}
              </span>
              <span
                v-if="limits?.events?.max != null"
                class="ml-2 bg-gray-700 text-gray-300 text-xs px-2 py-0.5 rounded-full shrink-0"
                >{{ limits.events.current ?? 0 }} /
                {{ limits.events.max }}</span
              >
              <span
                v-else-if="limits?.events?.unlimited"
                class="ml-2 text-xs text-gray-500 shrink-0"
                >∞</span
              >
            </AppNavLink>
          </div>

          <!-- E-shop Integration -->
          <div class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('stores.eshop_integration')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"
                  />
                </svg>
                {{ t("stores.eshop_integration") }}
              </span>
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/api-keys`" v-else
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium transition-colors"
              :class="
                isLinkActive(`/stores/${store.id}/api-keys`, 'stores-api-keys')
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center">
                <svg
                  class="w-5 h-5 mr-3"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"
                  />
                </svg>
                {{ t("stores.eshop_integration") }}
              </span>
              <span
                v-if="limits?.api_keys?.max != null"
                class="ml-2 bg-gray-700 text-gray-300 text-xs px-2 py-0.5 rounded-full shrink-0"
                >{{ limits.api_keys.current }} / {{ limits.api_keys.max }}</span
              >
              <span
                v-else-if="limits?.api_keys?.unlimited"
                class="ml-2 text-xs text-gray-500 shrink-0"
                >∞</span
              >
            </AppNavLink>
          </div>

          <!-- Stripe (visible for all, PRO badge for Free) -->
          <div class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('stores.stripe')"
            >
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
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
                {{ t("stores.stripe") }}
              </span>
              <span
                v-if="showStripeProBadge"
                class="ml-2 text-xs px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-400 border border-amber-500/30 shrink-0 inline-flex items-center"
                ><ProPlanBadge
              /></span>
              <span
                v-else
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/stripe`" v-else
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium transition-colors"
              :class="
                isLinkActive(`/stores/${store.id}/stripe`, 'stores-stripe')
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
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
                {{ t("stores.stripe") }}
              </span>
              <span
                v-if="showStripeProBadge"
                class="ml-2 text-xs px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-400 border border-amber-500/30 shrink-0 inline-flex items-center"
                ><ProPlanBadge
              /></span>
            </AppNavLink>
          </div>

          <!-- SEPA Instant QR (available to all roles incl. guests) -->
          <div class="mb-2">
            <AppNavLink :href="`/stores/${store.id}/sepa`"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium transition-colors"
              :class="
                isLinkActive(`/stores/${store.id}/sepa`, 'stores-sepa')
                  ? 'bg-gray-900 text-white'
                  : 'text-gray-300 hover:bg-gray-700 hover:text-white'
              "
              @click="showMobileMenu = false">
              <span class="flex items-center min-w-0">
                <svg
                  class="w-5 h-5 mr-3 flex-shrink-0"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"
                  />
                </svg>
                {{ t("sepa.nav_label") }}
              </span>
            </AppNavLink>
          </div>

          <!-- Archived Apps link -->
          <div v-if="archivedAppsCount > 0" class="mb-2">
            <button
              v-if="isGuestUser"
              type="button"
              class="flex items-center justify-between w-full px-3 py-2 rounded-md text-sm font-medium text-left text-gray-400 opacity-85 hover:opacity-100 border border-amber-500/20 cursor-pointer"
              :title="t('stores.guest_nav_locked_hint')"
              @click="openGuestUpgradeForFeature('stores.archived_apps')"
            >
              <span class="text-red-400/80"
                >{{ archivedAppsCount }} {{ t("stores.archived") }}</span
              >
              <span
                class="text-[10px] uppercase tracking-wide text-amber-400/90 shrink-0"
                >{{ t("stores.guest_nav_locked_short") }}</span
              >
            </button>
            <AppNavLink :href="`/stores/${store.id}/apps?archived=1`" v-else
              class="flex items-center w-full px-3 py-2 rounded-md text-sm font-medium transition-colors text-red-400 hover:bg-gray-700 hover:text-red-300"
              @click="showMobileMenu = false">
              <span>{{ archivedAppsCount }} {{ t("stores.archived") }}</span>
            </AppNavLink>
          </div>
        </nav>
      </div>

      <!-- Setup guide + PoS tour -->
      <div class="mt-4 space-y-2">
        <button
          type="button"
          @click="$emit('open-setup-wizard')"
          class="w-full flex items-center justify-center px-3 py-2.5 rounded-md text-sm font-medium text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 hover:bg-indigo-500/20 hover:border-indigo-500/30 transition-colors"
        >
          <svg
            class="w-5 h-5 mr-2 shrink-0"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
            />
          </svg>
          {{ t("setup_wizard.open_guide") }}
        </button>
        <button
          type="button"
          @click="handleStartPosTour"
          class="w-full flex items-center justify-center px-3 py-2.5 rounded-md text-sm font-medium text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 hover:bg-indigo-500/20 hover:border-indigo-500/30 transition-colors"
        >
          <svg
            class="w-5 h-5 mr-2 shrink-0"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
            />
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
            />
          </svg>
          {{ t("onboarding.start_tour") }}
        </button>
      </div>
    </div>
  </aside>
</template>

<script setup lang="ts">
import AppNavLink from "../layout/AppNavLink.vue";
import { ref, computed, onMounted, onUnmounted, watch, inject } from "vue";
import { useRouter, useRoute } from "vue-router";
import { router as inertiaRouter, usePage } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { useStoresStore } from "../../store/stores";
import { useAccountLimits } from "../../composables/useAccountLimits";
import { useAuthStore } from "../../store/auth";
import { useOnboardingStore } from "../../store/onboarding";
import { useRaffleAvailability } from "../../composables/useRaffleAvailability";
import { useGuestUpgradeModal } from "../../composables/useGuestUpgradeModal";
import ProPlanBadge from "./ProPlanBadge.vue";

const { t } = useI18n();

const authStore = useAuthStore();
const onboardingStore = useOnboardingStore();
const { limits, load: loadLimits } = useAccountLimits();
const isInertia = inject<boolean>("inertia", false);
const vueRouter = !isInertia ? useRouter() : null;
const route = !isInertia ? useRoute() : null;
const page = isInertia ? usePage() : null;

interface Props {
  store: {
    id: string;
    name: string;
    logo_url?: string | null;
    wallet_type?: "blink" | "blitz" | "aqua_boltz" | "cashu" | "nwc" | null;
    wallet_connection?: {
      status: "pending" | "needs_support" | "connected";
    } | null;
  } | null;
  apps: Array<{
    id: string;
    name: string;
    app_type: string;
    archived?: boolean;
  }>;
}

const props = defineProps<Props>();
const emit = defineEmits<{
  "create-app": [];
  "show-settings": [];
  "show-section": [section: string];
  "open-setup-wizard": [];
}>();

const authUser = computed(() => {
  if (authStore.user) return authStore.user;
  if (isInertia && page?.props?.auth?.user) return page.props.auth.user;
  return null;
});

const isGuestUser = computed(
  () => !!(authUser.value as { is_guest?: boolean } | null)?.is_guest,
);

const skipRaffleAvailabilityProbe = computed(
  () => !authUser.value || isGuestUser.value,
);

/** True when user has Pro-level access; null while auth user is not loaded (avoid flashing PRO badges). */
const isProOrAdminUser = computed((): boolean | null => {
  const u = authUser.value;
  if (!u) return null;
  const code = (u.plan?.code ?? "free") as string;
  const role = u.role ?? "";
  return (
    code === "pro" ||
    code === "enterprise" ||
    role === "admin" ||
    role === "support"
  );
});
/** Show sidebar PRO badge only when we know the user is on a plan without these features. */
const showReportsProBadge = computed(() => isProOrAdminUser.value === false);
const showStripeProBadge = computed(() => isProOrAdminUser.value === false);

const storesStore = useStoresStore();
const showStoreDropdown = ref(false);
const showMobileMenu = ref(false);

const storeIdForRaffle = computed(() => props.store?.id);
const { openGuestUpgradeModal } = useGuestUpgradeModal();
const { available: raffleAvailability } = useRaffleAvailability(
  storeIdForRaffle,
  skipRaffleAvailabilityProbe,
);

const raffleAvailable = computed(() => raffleAvailability.value === true);
const showRaffleNav = computed(
  () => raffleAvailable.value || isGuestUser.value,
);
const allStores = computed(() => storesStore.stores);
const activeStores = computed(() =>
  allStores.value.filter((s) => !s.archived),
);
const archivedStores = computed(() =>
  allStores.value.filter((s) => s.archived),
);

// Current URL state for both Inertia and SPA
const currentPath = computed(() => {
  if (isInertia && page) {
    try {
      return new URL(page.url, window.location.origin).pathname;
    } catch {
      return page.url;
    }
  }
  return route?.path ?? "";
});

const querySection = computed(() => {
  if (isInertia && page) {
    try {
      return new URL(page.url, window.location.origin).searchParams.get(
        "section",
      );
    } catch {
      return null;
    }
  }
  return (route?.query?.section as string) ?? null;
});

const paramAppId = computed(() => {
  if (isInertia && page) {
    const match = page.url.match(/\/apps\/([^/?#]+)/);
    return match ? match[1] : null;
  }
  return (route?.params?.appId as string) ?? null;
});

const isStoreShowSettings = computed(() => {
  if (isInertia && page) {
    const path = currentPath.value;
    const section = querySection.value;
    return (
      path.includes("/stores/") &&
      !path.includes("/apps/") &&
      section === "settings"
    );
  }
  return route?.name === "stores-show" && route?.query?.section === "settings";
});

/** True when the current route can show a PoS tour step (dashboard or PoS app page). */
const canShowPosTourOnCurrentRoute = computed(() => {
  const path = currentPath.value;
  const onStoreDashboard = path.match(/^\/stores\/[^/]+\/?$/) !== null;
  const onAppShow =
    path.includes("/stores/") &&
    path.includes("/apps/") &&
    !path.includes("/apps/create");
  return onStoreDashboard || onAppShow;
});

function handleStartPosTour() {
  showMobileMenu.value = false;
  if (canShowPosTourOnCurrentRoute.value) {
    onboardingStore.reset();
    return;
  }
  if (!props.store?.id) return;
  if (isInertia && inertiaRouter) {
    inertiaRouter.visit(`/stores/${props.store.id}?openPosTour=1`);
  } else if (vueRouter) {
    vueRouter.push({
      name: "stores-show",
      params: { id: props.store.id },
      query: { openPosTour: "1" },
    });
  }
}

function isLinkActive(path: string, routeName?: string): boolean {
  if (isInertia && page) {
    const urlPath = currentPath.value;
    return urlPath === path || urlPath.startsWith(path + "/");
  }
  if (route) {
    return routeName ? route.name === routeName : route.path.startsWith(path);
  }
  return false;
}

function isAppsCreateWithType(type: string): boolean {
  if (isInertia && page) {
    const path = currentPath.value;
    try {
      const url = new URL(page.url, window.location.origin);
      const q = url.searchParams.get("type");
      return path.includes("/apps/create") && q === type;
    } catch {
      return path.includes("/apps/create");
    }
  }
  return (
    route?.name === "stores-apps-create" &&
    (route?.query?.type as string) === type
  );
}

onMounted(async () => {
  // Load stores if not already loaded
  if (storesStore.stores.length === 0) {
    await storesStore.fetchStores();
  }

  // Load account limits for sidebar badges (LN addresses, API keys)
  if (props.store) {
    loadLimits().catch(() => {});
  }

  // Close dropdown on outside click
  document.addEventListener("click", handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener("click", handleClickOutside);
});

function handleClickOutside(event: Event) {
  const target = event.target as HTMLElement;
  const dropdown = target.closest(".store-dropdown-container");
  if (!dropdown && showStoreDropdown.value) {
    showStoreDropdown.value = false;
  }
}

function selectStore(storeId: string) {
  showStoreDropdown.value = false;
  showMobileMenu.value = false; // Close mobile menu on store change
  if (storeId !== props.store?.id) {
    if (isInertia) {
      inertiaRouter.visit(`/stores/${storeId}`);
    } else {
      vueRouter!.push({ name: "stores-show", params: { id: storeId } });
    }
  }
}

function openGuestUpgradeForFeature(labelKey: string) {
  showStoreDropdown.value = false;
  showMobileMenu.value = false;
  openGuestUpgradeModal(labelKey);
}

function createStore() {
  showStoreDropdown.value = false;
  showMobileMenu.value = false;
  if (isGuestUser.value && activeStores.value.length >= 1) {
    openGuestUpgradeForFeature("header.stores");
    return;
  }
  if (isInertia) {
    inertiaRouter.visit("/stores/create");
  } else {
    vueRouter!.push({ name: "stores-create" });
  }
}

function getAppsByType(type: string) {
  // Handle different type formats (PointOfSale, PaymentButton)
  // Normalize both to lowercase for comparison
  // Exclude archived apps from sidebar listing (BTCPay behavior)
  const normalizedType = type.toLowerCase().replace(/[_-]/g, "");
  return props.apps.filter((app) => {
    if (app.archived) return false;
    const appType = (app.app_type || "")
      .toString()
      .toLowerCase()
      .replace(/[_-]/g, "");
    return appType === normalizedType || appType.includes(normalizedType);
  });
}

const archivedAppsCount = computed(
  () => props.apps.filter((app) => app.archived).length,
);

const guestActivePosCount = computed(() => getAppsByType("PointOfSale").length);

const guestPosCreateLocked = computed(
  () => isGuestUser.value && guestActivePosCount.value >= 1,
);

function handleSectionClick(section: string) {
  showMobileMenu.value = false;
  if (isGuestUser.value && section === "reports") {
    openGuestUpgradeForFeature("stores.reports");
    return;
  }
  emit("show-section", section);
}

function handleReportsClick() {
  showMobileMenu.value = false;
  if (isGuestUser.value) {
    openGuestUpgradeForFeature("stores.reports");
    return;
  }
  emit("show-section", "reports");
}

function getWalletConnectionIconClass(): string {
  if (props.store?.wallet_type === "cashu") {
    return "text-green-400";
  }
  if (!props.store?.wallet_connection) {
    return "text-red-400"; // Non-existent - red
  }

  const status = props.store.wallet_connection.status;
  switch (status) {
    case "connected":
      return "text-green-400"; // Connected - green
    case "needs_support":
      return "text-blue-400"; // Needs support - blue
    case "pending":
    default:
      return "text-gray-300"; // Pending/default - gray
  }
}

// Watch for route/URL changes to close mobile menu
if (isInertia && page) {
  watch(
    () => page.url,
    () => {
      showMobileMenu.value = false;
    },
  );
} else if (vueRouter) {
  watch(
    () => vueRouter.currentRoute.value.fullPath,
    () => {
      showMobileMenu.value = false;
    },
  );
}
</script>
