<template>
  <div>
  <section
    id="how-it-works"
    class="relative scroll-mt-20 overflow-hidden bg-gray-900/85 py-20 md:py-28"
  >
    <div
      class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_100%_70%_at_50%_-10%,rgba(99,102,241,0.14),transparent_55%),radial-gradient(ellipse_55%_45%_at_85%_95%,rgba(251,146,60,0.09),transparent_60%)]"
      aria-hidden="true"
    />
    <div
      class="pointer-events-none absolute inset-0 bg-[linear-gradient(to_right,rgba(148,163,184,0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgba(148,163,184,0.05)_1px,transparent_1px)] bg-[length:40px_40px] opacity-50 [mask-image:linear-gradient(180deg,#000_0%,#000_85%,transparent)]"
      aria-hidden="true"
    />
    <div
      class="absolute left-0 right-0 top-0 h-px bg-[linear-gradient(90deg,transparent,rgba(129,140,248,0.42),rgba(251,146,60,0.38),rgba(167,139,250,0.35),transparent)]"
      aria-hidden="true"
    />
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="mx-auto mb-20 max-w-3xl text-center md:mb-28">
        <p
          class="mb-6 mb-6 text-xs font-mono uppercase tracking-[0.35em] text-orange-400/90"
        >
          {{ t("landing.how_it_works_kicker") }}
        </p>
        <h2
          class="mb-5 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-4xl font-bold tracking-tight md:text-6xl"
        >
          {{ t("landing.how_it_works") }}
        </h2>
        <p class="text-lg text-indigo-200/85 md:text-xl md:leading-relaxed">
          {{ t("landing.how_it_works_intro") }}
        </p>
        <div
          class="mx-auto mt-8 h-1 w-24 rounded-full bg-gradient-to-r from-transparent via-indigo-500/60 to-transparent"
          aria-hidden="true"
        />
      </div>

      <div class="relative">
        <div
          class="pointer-events-none absolute bottom-0 left-6 top-0 w-px -translate-x-1/2 bg-gradient-to-b from-transparent via-indigo-500/35 via-50% to-transparent md:left-1/2"
          aria-hidden="true"
        />
        <div
          class="pointer-events-none absolute bottom-0 left-6 top-0 w-4 -translate-x-1/2 bg-gradient-to-b from-transparent via-indigo-500/25 to-transparent opacity-40 blur-md md:left-1/2"
          aria-hidden="true"
        />

        <div class="space-y-12 md:space-y-0">
          <!-- Step 1: Registration -->
          <div
            class="landing-howitworks-step flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <!-- Dot on timeline (Desktop) -->
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <!-- Number on timeline (Mobile) -->
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              1
            </div>

            <div
              class="md:w-5/12 text-left md:text-right pl-16 md:pl-0 pr-0 md:pr-16 order-1 md:order-1"
            >
              <div class="mb-4 flex justify-start md:justify-end">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                >
                  01
                </span>
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step1_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step1_text") }}
                </p>
              </div>
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:pl-16 order-2 md:order-2"
            >
              <router-link
                to="/register"
                class="group block w-full max-w-md overflow-hidden rounded-2xl border border-gray-700 shadow-2xl transition-colors duration-500 hover:border-orange-500/40 hover:shadow-[0_0_32px_-6px_rgba(251,146,60,0.18)]"
              >
                <img
                  src="/img/satflux-create-account.webp"
                  :alt="t('landing.alt_register')"
                  class="block h-auto w-full"
                  width="512"
                  height="660"
                  loading="lazy"
                  decoding="async"
                />
              </router-link>
            </div>
          </div>

          <!-- Step 2: Wallet Connection -->
          <div
            class="landing-howitworks-step flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              2
            </div>

            <div
              class="md:w-5/12 flex justify-center md:justify-end pl-16 md:pl-0 pr-0 md:pr-16 order-2 md:order-1"
            >
              <div class="flex flex-col gap-10 w-full max-w-sm mt-12 md:mt-0">
                <!-- Blink Card (Shifted left) -->
                <div
                  class="relative group/card cursor-pointer transform md:-translate-x-12 transition-transform duration-500 hover:scale-105"
                >
                  <!-- Card Content -->
                  <div
                    class="bg-gray-800 rounded-2xl p-6 border border-gray-700 shadow-xl flex items-center gap-6 transition-all duration-300 group-hover/card:border-orange-500/50 group-hover/card:bg-gray-800/80"
                  >
                    <img
                      src="/img/wallets/blink-64.webp"
                      :alt="t('landing.blink_wallet_title')"
                      class="w-20 h-20 rounded-2xl object-contain p-2 bg-white/5 border border-white/10 shadow-inner"
                    />
                    <div>
                      <h4
                        class="mb-1 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
                      >
                        {{ t("landing.blink_wallet_title") }}
                      </h4>
                      <p class="text-sm text-orange-400 font-medium">
                        {{ t("landing.blink_wallet_tagline") }}
                      </p>
                    </div>
                  </div>

                  <!-- Hover Details (Tooltip/Overlay) -->
                  <div
                    class="absolute inset-0 bg-gray-900/95 backdrop-blur-xl rounded-2xl p-6 opacity-0 group-hover/card:opacity-100 transition-opacity duration-300 flex flex-col justify-center border border-orange-500/50 z-20 pointer-events-none group-hover/card:pointer-events-auto shadow-[0_0_30px_rgba(249,115,22,0.3)]"
                  >
                    <div class="space-y-4">
                      <div>
                        <span
                          class="text-green-400 font-bold text-xs uppercase tracking-wider flex items-center gap-2 mb-1"
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
                              stroke-width="3"
                              d="M5 13l4 4L19 7"
                            ></path>
                          </svg>
                          {{ t("landing.wallet_pros") }}
                        </span>
                        <p class="text-gray-300 text-sm leading-relaxed">
                          {{ t("landing.blink_pros") }}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Blitz & Flash Card: LN-address-only self-custodial wallets.
                     Focusable so the details overlay also opens via keyboard/touch, not only hover. -->
                <div
                  tabindex="0"
                  class="relative group/card cursor-pointer transform md:-translate-x-6 transition-transform duration-500 hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-yellow-500/60 rounded-2xl"
                >
                  <div
                    class="bg-gray-800 rounded-2xl p-6 border border-gray-700 shadow-xl flex items-center gap-6 transition-all duration-300 group-hover/card:border-yellow-500/50 group-hover/card:bg-gray-800/80"
                  >
                    <div
                      class="w-20 h-20 rounded-2xl bg-white/5 border border-white/10 flex flex-col items-center justify-center gap-2 p-2 shadow-inner"
                    >
                      <img
                        src="/img/wallets/blitz-64.webp"
                        :alt="t('create_store.wallet_type_blitz')"
                        class="h-4 w-auto max-w-[3.5rem] object-contain flex-shrink-0"
                      />
                      <img
                        src="/img/wallets/flash-64.webp"
                        :alt="t('create_store.wallet_type_flash')"
                        class="h-4 w-auto max-w-[3.5rem] object-contain flex-shrink-0"
                      />
                      <img
                        src="/img/wallets/coinos-64.webp"
                        :alt="t('create_store.wallet_type_coinos')"
                        class="h-4 w-auto max-w-[3.5rem] object-contain flex-shrink-0"
                      />
                    </div>
                    <div>
                      <h4
                        class="mb-1 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
                      >
                        {{ t("landing.blitzflash_wallet_title") }}
                      </h4>
                      <p class="text-sm text-yellow-400 font-medium">
                        {{ t("landing.blitzflash_wallet_tagline") }}
                      </p>
                    </div>
                  </div>

                  <!-- Hover Details -->
                  <div
                    class="absolute inset-0 bg-gray-900/95 backdrop-blur-xl rounded-2xl p-6 opacity-0 group-hover/card:opacity-100 group-focus-within/card:opacity-100 transition-opacity duration-300 flex flex-col justify-center border border-yellow-500/50 z-20 pointer-events-none group-hover/card:pointer-events-auto group-focus-within/card:pointer-events-auto shadow-[0_0_30px_rgba(234,179,8,0.3)]"
                  >
                    <div class="space-y-4">
                      <div>
                        <span
                          class="text-green-400 font-bold text-xs uppercase tracking-wider flex items-center gap-2 mb-1"
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
                              stroke-width="3"
                              d="M5 13l4 4L19 7"
                            ></path>
                          </svg>
                          {{ t("landing.wallet_pros") }}
                        </span>
                        <p class="text-gray-300 text-sm leading-relaxed">
                          {{ t("landing.blitzflash_pros") }}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Aqua Card (Shifted right/default) -->
                <div
                  class="relative group/card cursor-pointer transition-transform duration-500 hover:scale-105"
                >
                  <!-- Card Content -->
                  <div
                    class="bg-gray-800 rounded-2xl p-6 border border-gray-700 shadow-xl flex items-center gap-6 transition-all duration-300 group-hover/card:border-blue-500/50 group-hover/card:bg-gray-800/80"
                  >
                    <div
                      class="w-20 h-20 rounded-2xl bg-white/5 border border-white/10 flex flex-col items-center justify-center gap-1.5 p-2 shadow-inner"
                    >
                      <img
                        src="/img/wallets/aqua-64.webp"
                        :alt="t('create_store.wallet_type_aqua')"
                        class="w-8 h-8 object-contain flex-shrink-0"
                      />
                      <img
                        src="/img/wallets/bull-64.webp"
                        :alt="t('create_store.wallet_type_bull')"
                        class="h-4 w-auto max-w-[3.25rem] object-contain flex-shrink-0"
                      />
                    </div>
                    <div>
                      <h4
                        class="mb-1 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
                      >
                        {{ t("landing.aqua_wallet_title") }}
                      </h4>
                      <p class="text-sm text-blue-400 font-medium">
                        {{ t("landing.aqua_wallet_tagline") }}
                      </p>
                    </div>
                  </div>

                  <!-- Hover Details -->
                  <div
                    class="absolute inset-0 bg-gray-900/95 backdrop-blur-xl rounded-2xl p-6 opacity-0 group-hover/card:opacity-100 transition-opacity duration-300 flex flex-col justify-center border border-blue-500/50 z-20 pointer-events-none group-hover/card:pointer-events-auto shadow-[0_0_30px_rgba(59,130,246,0.3)]"
                  >
                    <div class="space-y-4">
                      <div>
                        <span
                          class="text-green-400 font-bold text-xs uppercase tracking-wider flex items-center gap-2 mb-1"
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
                              stroke-width="3"
                              d="M5 13l4 4L19 7"
                            ></path>
                          </svg>
                          {{ t("landing.wallet_pros") }}
                        </span>
                        <p class="text-gray-300 text-sm leading-relaxed">
                          {{ t("landing.aqua_pros") }}
                        </p>
                      </div>
                      <div>
                        <span
                          class="text-red-400 font-bold text-xs uppercase tracking-wider flex items-center gap-2 mb-1"
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
                              stroke-width="3"
                              d="M6 18L18 6M6 6l12 12"
                            ></path>
                          </svg>
                          {{ t("landing.wallet_cons") }}
                        </span>
                        <p class="text-gray-300 text-sm leading-relaxed">
                          {{ t("landing.aqua_cons") }}
                        </p>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Cashu Card (Beta): hover panel grows with content and expands beyond card -->
                <div
                  class="group/card cursor-pointer md:translate-x-6 transition-transform duration-500"
                >
                  <div class="relative overflow-visible">
                    <div
                      class="relative z-10 flex w-full items-center gap-6 rounded-2xl border border-gray-700 bg-gray-800 p-6 shadow-xl ring-1 ring-amber-500/20 transition-all duration-300 group-hover/card:border-emerald-500/50 group-hover/card:bg-gray-800/80"
                    >
                      <div
                        class="w-20 h-20 shrink-0 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-2 shadow-inner flex items-center justify-center"
                      >
                        <svg
                          class="h-11 w-11 text-emerald-400"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                          aria-hidden="true"
                        >
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.5"
                            d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                          />
                        </svg>
                      </div>
                      <div class="min-w-0 flex-1">
                        <h4
                          class="mb-1 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-2xl font-bold"
                        >
                          {{ t("landing.cashu_wallet_title") }}
                        </h4>
                        <p class="text-sm font-medium text-emerald-400/90">
                          {{ t("landing.cashu_wallet_tagline") }}
                        </p>
                      </div>
                    </div>
                    <span
                      class="pointer-events-none absolute right-3 top-3 z-40 rounded-full bg-amber-400 px-2.5 py-1 text-[10px] font-extrabold uppercase tracking-wider text-gray-900 shadow-lg ring-2 ring-gray-900 transition-all duration-300 group-hover/card:right-4 group-hover/card:top-4"
                      :aria-label="t('landing.cashu_beta_badge')"
                    >
                      {{ t("landing.cashu_beta_badge") }}
                    </span>
                    <div
                      class="pointer-events-none absolute left-0 right-0 top-0 z-30 min-h-full h-auto rounded-2xl border border-emerald-500/50 bg-gray-900/95 opacity-0 shadow-lg shadow-emerald-950/20 ring-1 ring-emerald-500/20 backdrop-blur-xl transition-all duration-300 ease-out group-hover/card:pointer-events-auto group-hover/card:opacity-100 group-hover/card:-inset-x-4 group-hover/card:-top-3 group-hover/card:min-h-[calc(100%+0.75rem)] group-hover/card:shadow-[0_0_42px_rgba(16,185,129,0.35)] group-hover/card:ring-2 md:group-hover/card:-inset-x-5"
                    >
                      <div class="flex flex-col gap-3 p-5 md:p-6">
                        <p
                          class="border-b border-amber-500/25 pb-2 text-[11px] font-bold uppercase leading-snug tracking-wide text-amber-300"
                        >
                          {{ t("landing.cashu_beta_notice") }}
                        </p>
                        <div class="space-y-4">
                          <div>
                            <span
                              class="mb-1.5 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-green-400"
                            >
                              <svg
                                class="h-4 w-4 shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                              >
                                <path
                                  stroke-linecap="round"
                                  stroke-linejoin="round"
                                  stroke-width="3"
                                  d="M5 13l4 4L19 7"
                                ></path>
                              </svg>
                              {{ t("landing.wallet_pros") }}
                            </span>
                            <p class="text-sm leading-relaxed text-gray-300">
                              {{ t("landing.cashu_pros") }}
                            </p>
                          </div>
                          <div>
                            <span
                              class="mb-1.5 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-red-400"
                            >
                              <svg
                                class="h-4 w-4 shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                              >
                                <path
                                  stroke-linecap="round"
                                  stroke-linejoin="round"
                                  stroke-width="3"
                                  d="M6 18L18 6M6 6l12 12"
                                ></path>
                              </svg>
                              {{ t("landing.wallet_cons") }}
                            </span>
                            <p class="text-sm leading-relaxed text-gray-300">
                              {{ t("landing.cashu_cons") }}
                            </p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              class="md:w-5/12 text-left pl-16 md:pl-16 order-1 md:order-2"
            >
              <div class="mb-4 flex justify-start">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                  >02</span
                >
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step2_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step2_text") }}
                </p>
              </div>
            </div>
          </div>

          <!-- Step 3: PoS Terminal (Interactive) -->
          <div
            id="how-it-works-pos"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:min-h-[min(100svh,920px)] md:py-20 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              3
            </div>

            <div
              class="md:w-5/12 text-left md:text-right pl-16 md:pl-0 pr-0 md:pr-16 order-1 md:order-1"
            >
              <div class="mb-4 flex justify-start md:justify-end">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                  >03</span
                >
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step3_title") }}
                </h3>
                <p
                  class="mb-6 text-gray-400 text-lg md:text-xl leading-relaxed"
                >
                  {{ t("landing.step3_text") }}
                </p>
                <p class="hidden text-sm text-emerald-400/85 md:block">
                  {{ t("landing.how_it_works_demo_real") }}
                </p>
                <div
                  v-if="posDemoUrl"
                  class="mt-4 flex justify-center md:hidden"
                >
                  <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-6 py-3 text-base font-bold text-white shadow-lg transition-all hover:bg-indigo-500"
                    @click="openPosModal"
                  >
                    {{ t("landing.how_it_works_open_pos_mobile") }}
                  </button>
                </div>
              </div>
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:pl-16 order-2 md:order-2"
            >
              <div
                class="hidden md:block w-full max-w-[380px] bg-gray-900 rounded-[2.5rem] border-[8px] border-gray-800 shadow-2xl overflow-hidden h-[800px] relative"
              >
                <!-- Phone Notches -->
                <div
                  class="absolute top-0 left-1/2 -translate-x-1/2 w-32 h-6 bg-gray-800 rounded-b-xl z-20"
                ></div>
                <!-- Iframe -->
                <iframe
                  v-if="posDemoUrl"
                  :src="posDemoUrl"
                  title="SATFLUX PoS Demo"
                  class="w-full h-full border-0 bg-gray-900"
                  allow="payment"
                  loading="lazy"
                />
                <div
                  v-else
                  class="flex h-full w-full items-center justify-center bg-gray-900"
                  aria-hidden="true"
                />
                <!-- Overlay hint -->
                <div
                  class="absolute bottom-4 left-0 right-0 text-center pointer-events-none"
                >
                  <span
                    class="inline-block px-3 py-1 bg-black/50 text-white/50 text-xs rounded-full backdrop-blur-sm"
                    >{{ t("landing.live_demo") }}</span
                  >
                </div>
              </div>
              <!-- Mobile Visual (Placeholder card instead of full iframe) -->
              <div
                class="md:hidden w-full max-w-xs rounded-2xl border border-gray-700/80 bg-gray-950/50 p-4 shadow-inner ring-1 ring-white/[0.04]"
              >
                <div
                  class="mb-2 text-center font-mono text-[10px] uppercase tracking-wider text-gray-500"
                >
                  {{ t("landing.live_demo") }}
                </div>
                <div class="grid grid-cols-3 gap-2">
                  <div
                    v-for="n in 9"
                    :key="n"
                    class="flex aspect-square items-center justify-center rounded-lg border border-gray-700/50 bg-gray-800/60 text-sm font-medium text-gray-500"
                  >
                    {{ n }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Step 4: Integrations -->
          <div
            id="how-it-works-eshop"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              4
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:justify-end pr-8 md:pr-16 order-2 md:order-1"
            >
              <div
                class="flex max-w-md flex-wrap justify-start gap-3 md:justify-end"
              >
                <div
                  class="flex h-14 min-w-[6.5rem] cursor-default items-center justify-center rounded-xl border border-gray-500/40 bg-gray-100 px-4 shadow-lg transition-transform delay-75 group-hover:-translate-y-1 md:px-5"
                >
                  <img
                    src="/img/integrations/woo-wordmark.svg"
                    alt="WooCommerce"
                    title="WooCommerce"
                    class="h-4 w-auto max-w-[6.75rem] object-contain object-center"
                    width="184"
                    height="48"
                    loading="lazy"
                    decoding="async"
                  />
                </div>
                <div
                  class="flex h-14 min-w-[6.5rem] items-center justify-center rounded-xl border border-white/15 bg-[#95BF47] px-5 shadow-lg transition-transform delay-100 group-hover:-translate-y-1"
                >
                  <img
                    src="/img/integrations/shopify.svg"
                    alt="Shopify"
                    title="Shopify"
                    class="h-8 w-auto max-w-[5.5rem] object-contain object-center [filter:brightness(0)_invert(1)]"
                    width="88"
                    height="32"
                    loading="lazy"
                    decoding="async"
                  />
                </div>
                <div
                  class="flex h-14 min-w-[6.5rem] items-center justify-center rounded-xl border border-white/15 bg-[#F46F25] px-5 shadow-lg transition-transform delay-150 group-hover:-translate-y-1"
                >
                  <img
                    src="/img/integrations/magento.svg"
                    alt="Magento"
                    title="Magento"
                    class="h-8 w-auto max-w-[5rem] object-contain object-center [filter:brightness(0)_invert(1)]"
                    width="72"
                    height="36"
                    loading="lazy"
                    decoding="async"
                  />
                </div>
                <div
                  class="flex h-14 min-w-[6.5rem] items-center justify-center rounded-xl border border-white/15 bg-[#0678BE] px-5 shadow-lg transition-transform delay-200 group-hover:-translate-y-1"
                >
                  <img
                    src="/img/integrations/drupal.svg"
                    alt="Drupal"
                    title="Drupal"
                    class="h-8 w-auto max-w-[4.5rem] object-contain object-center [filter:brightness(0)_invert(1)]"
                    width="72"
                    height="36"
                    loading="lazy"
                    decoding="async"
                  />
                </div>
                <div
                  class="mt-1 w-full rounded-xl border border-gray-700/60 bg-gray-950/60 p-3 text-center font-mono text-xs text-gray-400"
                >
                  {{ t("landing.api_key_example") }}
                </div>
              </div>
            </div>

            <div
              class="md:w-5/12 text-left pl-16 md:pl-16 order-1 md:order-2"
            >
              <div class="mb-4 flex justify-start">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                  >04</span
                >
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step4_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step4_text") }}
                </p>
              </div>
            </div>
          </div>

          <!-- Step 5: Crowdfund -->
          <div
            id="how-it-works-crowdfund"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              5
            </div>

            <div
              class="md:w-5/12 text-left md:text-right pl-16 md:pl-0 pr-0 md:pr-16 order-1 md:order-1"
            >
              <div class="mb-4 flex justify-start md:justify-end">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                  >05</span
                >
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step5_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step5_text") }}
                </p>
              </div>
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:pl-16 order-2 md:order-2"
            >
              <div
                class="w-full max-w-md rounded-2xl border border-violet-500/30 bg-gray-800 p-6 shadow-xl shadow-violet-950/20 transform group-hover:scale-105 transition-transform duration-500"
              >
                <p
                  class="text-xs font-medium uppercase tracking-wider text-violet-300/80 mb-2"
                >
                  {{ t("landing.step5_goal_label") }}
                </p>
                <p class="text-lg font-bold text-white mb-4">
                  {{ t("landing.step5_goal_amount") }}
                </p>
                <div
                  class="h-3 w-full rounded-full bg-gray-700 overflow-hidden mb-2"
                >
                  <div
                    class="h-full w-[64%] rounded-full bg-gradient-to-r from-violet-500 to-indigo-500"
                  />
                </div>
                <p class="text-sm text-violet-200/90 font-medium">
                  {{ t("landing.step5_goal_progress") }}
                </p>
              </div>
            </div>
          </div>

          <!-- Step 6: LN Address -->
          <div
            id="how-it-works-ln-address"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              6
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:justify-end pr-8 md:pr-16 order-2 md:order-1"
            >
              <div
                class="bg-gradient-to-r from-purple-600 to-indigo-600 p-1 rounded-2xl w-full max-w-sm shadow-xl shadow-purple-900/20 transform group-hover:scale-105 transition-transform duration-500"
              >
                <div
                  class="bg-gray-900 rounded-xl p-8 text-center relative overflow-hidden"
                >
                  <!-- Shine effect -->
                  <div
                    class="absolute inset-0 bg-gradient-to-tr from-white/10 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-700"
                  ></div>
                  <div
                    class="w-16 h-16 bg-gray-800 rounded-full mb-4 flex items-center justify-center shadow-inner mx-auto"
                  >
                    <svg
                      class="w-8 h-8 text-yellow-400"
                      fill="currentColor"
                      viewBox="0 0 20 20"
                    >
                      <path
                        fill-rule="evenodd"
                        d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z"
                        clip-rule="evenodd"
                      />
                    </svg>
                  </div>
                  <div class="text-white font-mono text-lg md:text-xl">
                    satoshi<span
                      v-if="displayLightningDomain"
                      class="text-indigo-400"
                      >@{{ displayLightningDomain }}</span
                    >
                  </div>
                </div>
              </div>
            </div>

            <div
              class="md:w-5/12 text-left pl-16 md:pl-16 order-1 md:order-2"
            >
              <div class="mb-4 flex justify-start">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                  >06</span
                >
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step6_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{
                    t("landing.step6_text", {
                      domain: displayLightningDomain || "…",
                    })
                  }}
                </p>
              </div>
            </div>
          </div>

          <!-- Step 7: Ticket Sale -->
          <div
            id="how-it-works-tickets"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              7
            </div>

            <div
              class="md:w-5/12 text-left md:text-right pl-16 md:pl-0 pr-0 md:pr-16 order-1 md:order-1"
            >
              <div class="mb-4 flex justify-start md:justify-end">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                >
                  07
                </span>
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step7_title") }}
                </h3>
                <p
                  class="mb-6 text-gray-400 text-lg md:text-xl leading-relaxed"
                >
                  {{ t("landing.step7_text") }}
                </p>
                <div class="space-y-3 border-t border-gray-700/50 pt-6">
                  <p
                    class="text-sm font-medium uppercase tracking-wider text-gray-500"
                  >
                    {{ t("landing.step7_plugins_title") }}
                  </p>
                  <div class="flex flex-wrap gap-3">
                    <a
                      href="https://wordpress.org/plugins/satflux-for-woocommerce/"
                      target="_blank"
                      rel="noopener noreferrer"
                      :title="t('landing.step7_plugin_woocommerce_title')"
                      class="group inline-flex flex-wrap items-center gap-2 rounded-xl border border-gray-600 bg-gray-800/80 px-4 py-2.5 transition-all hover:border-orange-500/50 hover:bg-gray-800"
                    >
                      <span
                        class="font-semibold text-white transition-colors group-hover:text-orange-400"
                        >{{ t("landing.step7_plugin_woocommerce") }}</span
                      >
                      <img
                        src="https://img.shields.io/wordpress/plugin/v/satflux-for-woocommerce?label=release"
                        :alt="t('landing.badge_release_alt')"
                        class="h-4"
                      />
                      <img
                        src="https://img.shields.io/wordpress/plugin/installs/satflux-for-woocommerce?label=installs"
                        :alt="t('landing.badge_installs_alt')"
                        class="h-4"
                      />
                    </a>
                  </div>
                </div>
              </div>
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:pl-16 order-2 md:order-2"
            >
              <div
                class="w-full max-w-md bg-gray-800 rounded-2xl border border-gray-700 p-6 flex flex-col items-center gap-4 transform group-hover:scale-105 transition-transform duration-500 relative overflow-hidden"
              >
                <!-- Background ticket watermark -->
                <div class="absolute top-0 right-0 p-4 opacity-5">
                  <svg
                    class="w-36 h-36 text-orange-400"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      d="M15.58 16.8L12 14.5l-3.58 2.3 1.08-4.12L6.21 10l4.25-.26L12 5.8l1.54 3.94 4.25.26-3.29 2.68 1.08 4.12zM22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24z"
                    />
                  </svg>
                </div>

                <!-- Event card header -->
                <div
                  class="w-full bg-gradient-to-r from-orange-500/20 to-yellow-500/20 rounded-xl border border-orange-500/30 p-4 relative z-10"
                >
                  <div class="flex items-center gap-3 mb-2">
                    <div
                      class="w-10 h-10 bg-orange-500/20 rounded-lg flex items-center justify-center"
                    >
                      <svg
                        class="w-5 h-5 text-orange-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                        />
                      </svg>
                    </div>
                    <div>
                      <div class="text-white font-bold text-sm">
                        {{ t("landing.step7_badge_event") }}
                      </div>
                      <div class="text-orange-300/70 text-xs">
                        14 Mar 2026 &middot; 18:00
                      </div>
                    </div>
                  </div>
                  <div class="flex gap-4 text-xs">
                    <div class="flex items-center gap-1.5">
                      <svg
                        class="w-3.5 h-3.5 text-green-400"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                      >
                        <path
                          d="M2 6a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 100 4v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2a2 2 0 100-4V6z"
                        />
                      </svg>
                      <span class="text-gray-300"
                        >{{ t("landing.step7_badge_tickets") }}:
                        <span class="text-green-400 font-bold"
                          >142</span
                        ></span
                      >
                    </div>
                    <div class="flex items-center gap-1.5">
                      <svg
                        class="w-3.5 h-3.5 text-indigo-400"
                        fill="currentColor"
                        viewBox="0 0 20 20"
                      >
                        <path
                          fill-rule="evenodd"
                          d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                          clip-rule="evenodd"
                        />
                      </svg>
                      <span class="text-gray-300"
                        >{{ t("landing.step7_badge_checkin") }}:
                        <span class="text-indigo-400 font-bold"
                          >89</span
                        ></span
                      >
                    </div>
                  </div>
                </div>

                <!-- QR code / scan area -->
                <div
                  class="w-full bg-gray-900 rounded-xl border border-gray-700 p-4 flex items-center gap-4 relative z-10"
                >
                  <div
                    class="w-16 h-16 bg-white rounded-lg p-1.5 flex-shrink-0"
                  >
                    <!-- Stylized QR code -->
                    <svg viewBox="0 0 100 100" class="w-full h-full">
                      <rect
                        x="0"
                        y="0"
                        width="100"
                        height="100"
                        fill="white"
                      />
                      <rect
                        x="5"
                        y="5"
                        width="25"
                        height="25"
                        rx="3"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="10"
                        y="10"
                        width="15"
                        height="15"
                        rx="2"
                        fill="white"
                      />
                      <rect
                        x="13"
                        y="13"
                        width="9"
                        height="9"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="70"
                        y="5"
                        width="25"
                        height="25"
                        rx="3"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="75"
                        y="10"
                        width="15"
                        height="15"
                        rx="2"
                        fill="white"
                      />
                      <rect
                        x="78"
                        y="13"
                        width="9"
                        height="9"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="5"
                        y="70"
                        width="25"
                        height="25"
                        rx="3"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="10"
                        y="75"
                        width="15"
                        height="15"
                        rx="2"
                        fill="white"
                      />
                      <rect
                        x="13"
                        y="78"
                        width="9"
                        height="9"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="35"
                        y="5"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="48"
                        y="5"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="35"
                        y="18"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="5"
                        y="35"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="18"
                        y="35"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="35"
                        y="35"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#f59e0b"
                      />
                      <rect
                        x="48"
                        y="35"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="61"
                        y="35"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="5"
                        y="48"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="35"
                        y="48"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="48"
                        y="48"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#f59e0b"
                      />
                      <rect
                        x="61"
                        y="48"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="5"
                        y="61"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="48"
                        y="61"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="70"
                        y="48"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="83"
                        y="48"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="70"
                        y="61"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="83"
                        y="61"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="70"
                        y="70"
                        width="8"
                        height="8"
                        rx="1"
                        fill="#1a1a2e"
                      />
                      <rect
                        x="83"
                        y="83"
                        width="12"
                        height="12"
                        rx="2"
                        fill="#f59e0b"
                      />
                    </svg>
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs text-gray-400 mb-1.5">
                      {{ t("landing.step7_scan_label") }}
                    </div>
                    <div class="flex items-center gap-2">
                      <div
                        class="flex-1 bg-gray-800 rounded-lg px-3 py-2 border border-gray-600 text-xs text-gray-500 font-mono truncate"
                      >
                        TKT-2026-00482
                      </div>
                      <div
                        class="w-8 h-8 bg-green-500/20 border border-green-500/40 rounded-lg flex items-center justify-center flex-shrink-0"
                      >
                        <svg
                          class="w-4 h-4 text-green-400"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2.5"
                            d="M5 13l4 4L19 7"
                          />
                        </svg>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Price in sats -->
                <div
                  class="w-full flex items-center justify-between px-1 relative z-10"
                >
                  <span class="text-xs text-gray-500 font-mono"
                    >21,000 sats / ticket</span
                  >
                  <span
                    class="text-xs font-bold text-orange-400 bg-orange-400/10 px-2.5 py-1 rounded-full border border-orange-400/20"
                    >&#9889; Lightning</span
                  >
                </div>
              </div>
            </div>
          </div>

          <!-- Step 8: Raffle / Tombola -->
          <div
            id="how-it-works-raffle"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              8
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:justify-end pr-8 md:pr-16 order-2 md:order-1"
            >
              <LandingRafflePresenterPreview class="group-hover:scale-105" />
            </div>

            <div
              class="md:w-5/12 text-left pl-16 md:pl-16 order-1 md:order-2"
            >
              <div class="mb-4 flex justify-start">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                >
                  08
                </span>
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step8_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step8_text") }}
                </p>
              </div>
            </div>
          </div>

          <!-- Step 9: Pay Button -->
          <div
            id="how-it-works-pay-button"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              9
            </div>

            <div
              class="md:w-5/12 text-left md:text-right pl-16 md:pl-0 pr-0 md:pr-16 order-1 md:order-1"
            >
              <div class="mb-4 flex justify-start md:justify-end">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                >
                  09
                </span>
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step9_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step9_text") }}
                </p>
              </div>
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:pl-16 order-2 md:order-2"
            >
              <div
                class="transform group-hover:scale-105 transition-transform duration-500"
              >
                <LandingPayButtonEmbed />
                <p class="mt-4 text-center text-xs text-gray-500 max-w-sm">
                  {{ t("landing.step9_embed_hint") }}
                </p>
              </div>
            </div>
          </div>

          <!-- Step 10: Accounting Exports -->
          <div
            id="how-it-works-exports"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              10
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:justify-end pr-8 md:pr-16 order-2 md:order-1"
            >
              <div
                class="w-full max-w-md bg-gray-800 rounded-2xl border border-gray-700 p-6 flex flex-col items-center gap-6 transform group-hover:scale-105 transition-transform duration-500 relative overflow-hidden"
              >
                <div class="absolute top-0 right-0 p-4 opacity-10">
                  <svg
                    class="w-32 h-32 text-indigo-500"
                    fill="currentColor"
                    viewBox="0 0 20 20"
                  >
                    <path
                      fill-rule="evenodd"
                      d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z"
                      clip-rule="evenodd"
                    />
                  </svg>
                </div>
                <div class="grid grid-cols-2 gap-4 w-full relative z-10">
                  <div
                    class="bg-gray-700/50 p-4 rounded-xl border border-gray-600 flex flex-col items-center hover:bg-green-500/10 hover:border-green-500/50 transition-colors"
                  >
                    <span class="font-bold text-white text-lg mb-1">CSV</span>
                    <span class="text-xs text-gray-400">{{
                      t("landing.export_csv_label")
                    }}</span>
                  </div>
                  <div
                    class="bg-gray-700/50 p-4 rounded-xl border border-gray-600 flex flex-col items-center hover:bg-red-500/10 hover:border-red-500/50 transition-colors"
                  >
                    <span class="font-bold text-white text-lg mb-1">PDF</span>
                    <span class="text-xs text-gray-400">{{
                      t("landing.export_pdf_label")
                    }}</span>
                  </div>
                </div>
                <div
                  class="w-full bg-gray-900 rounded-lg p-3 border border-gray-700 flex items-center justify-between text-xs text-gray-400 font-mono"
                >
                  <span>export_2026_02.csv</span>
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
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                    ></path>
                  </svg>
                </div>
              </div>
            </div>

            <div
              class="md:w-5/12 text-left pl-16 md:pl-16 order-1 md:order-2"
            >
              <div class="mb-4 flex justify-start">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                >
                  10
                </span>
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step10_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step10_text") }}
                </p>
              </div>
            </div>
          </div>

          <!-- Step 11: SEPA Instant QR -->
          <div
            id="how-it-works-sepa"
            class="landing-howitworks-step scroll-mt-24 flex flex-col md:flex-row items-center justify-between gap-10 py-16 md:py-24 relative group"
          >
            <div
              class="absolute left-1/2 top-1/2 z-20 hidden h-12 w-12 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-indigo-500/35 bg-gray-950/90 shadow-[0_0_32px_rgba(99,102,241,0.35)] backdrop-blur-sm md:flex motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-110"
              aria-hidden="true"
            >
              <span
                class="block h-3 w-3 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 shadow-[0_0_14px_rgba(167,139,250,0.85)]"
              />
            </div>
            <div
              class="absolute left-6 top-14 z-20 flex h-11 w-11 -translate-x-1/2 items-center justify-center rounded-full border-2 border-orange-400/60 bg-gray-950/95 font-mono text-sm font-bold text-orange-200 shadow-[0_0_24px_rgba(251,146,60,0.4)] ring-2 ring-orange-500/15 md:hidden"
            >
              11
            </div>

            <div
              class="md:w-5/12 text-left md:text-right pl-16 md:pl-0 pr-0 md:pr-16 order-1 md:order-1"
            >
              <div class="mb-4 flex justify-start md:justify-end">
                <span
                  class="inline-flex rounded-lg border border-orange-500/35 bg-orange-500/10 px-2.5 py-1 font-mono text-[11px] font-bold tabular-nums tracking-[0.2em] text-orange-300/95"
                  aria-hidden="true"
                >
                  11
                </span>
              </div>
              <div
                class="rounded-2xl border border-gray-700/10 p-6 shadow-xl shadow-black/25 ring-1 ring-white/[0.04] backdrop-blur-md transition-all duration-300 group-hover:border-orange-500/25 group-hover:bg-gray-950/55 group-hover:shadow-orange-950/10 md:p-8"
              >
                <h3
                  class="mb-4 bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-3xl font-bold md:text-5xl"
                >
                  {{ t("landing.step11_title") }}
                </h3>
                <p class="text-gray-400 text-lg md:text-xl leading-relaxed">
                  {{ t("landing.step11_text") }}
                </p>
              </div>
            </div>

            <div
              class="md:w-5/12 flex justify-start pl-16 md:pl-16 order-2 md:order-2"
            >
              <div
                class="w-full max-w-md rounded-2xl border border-gray-700 bg-gradient-to-br from-sky-950/40 via-gray-900 to-indigo-950/50 p-6 shadow-2xl transition-colors duration-500 group-hover:border-sky-500/40 md:p-8"
              >
                <div class="mb-5 flex items-center gap-3">
                  <div
                    class="flex h-12 w-12 items-center justify-center rounded-xl bg-sky-500/15 text-sky-400 ring-1 ring-sky-500/25"
                  >
                    <svg
                      class="h-6 w-6"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                      aria-hidden="true"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M3 4h4v4H3V4zm0 12h4v4H3v-4zM17 4h4v4h-4V4zM3 10h2m4 0h2m2 0h2m4 0h2M11 4h2v4h-2V4zm0 8h2v8h-2v-8zm6 2h4v2h-4v-2zm0 4h4v2h-4v-2z"
                      />
                    </svg>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <span
                      class="rounded-md border border-sky-500/30 bg-gray-800/80 px-2.5 py-1 text-xs font-medium text-sky-200/90"
                      >{{ t("landing.sepa_chip_payme") }}</span
                    >
                    <span
                      class="rounded-md border border-sky-500/30 bg-gray-800/80 px-2.5 py-1 text-xs font-medium text-sky-200/90"
                      >{{ t("landing.sepa_chip_bysquare") }}</span
                    >
                    <span
                      class="rounded-md border border-gray-600 bg-gray-800/80 px-2.5 py-1 text-xs font-medium text-gray-300"
                      >{{ t("landing.sepa_chip_qrplatba") }}</span
                    >
                    <span
                      class="rounded-md border border-gray-600 bg-gray-800/80 px-2.5 py-1 text-xs font-medium text-gray-300"
                      >{{ t("landing.sepa_chip_epc") }}</span
                    >
                  </div>
                </div>
                <ul class="space-y-3 text-sm text-gray-300">
                  <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 text-sky-400" aria-hidden="true"
                      >✓</span
                    >
                    <span>{{ t("landing.feature_sepa_bullet_qr") }}</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 text-sky-400" aria-hidden="true"
                      >✓</span
                    >
                    <span>{{ t("landing.feature_sepa_bullet_confirm") }}</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 text-sky-400" aria-hidden="true"
                      >✓</span
                    >
                    <span>{{ t("landing.feature_sepa_bullet_mandate") }}</span>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- PoS Demo Modal (mobile) -->
  <Teleport to="body">
    <transition
      enter-active-class="transition-opacity duration-200 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="showPosModal"
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="pos-modal-title"
        @keydown.escape="closePosModal"
        @keydown="onModalKeydown"
      >
        <div
          class="fixed inset-0 bg-gray-900/90 backdrop-blur-sm"
          @click="closePosModal"
        />
        <div class="flex min-h-full items-center justify-center p-4">
          <div
            ref="modalRef"
            tabindex="-1"
            class="relative w-full max-w-lg transform rounded-2xl bg-gray-800 border border-gray-700 shadow-2xl transition-all focus:outline-none"
          >
            <div
              class="flex items-center justify-between px-4 py-3 border-b border-gray-700"
            >
              <h2
                id="pos-modal-title"
                class="bg-clip-text text-transparent bg-gradient-to-br from-white via-white to-indigo-300/90 text-lg font-bold"
              >
                {{ t("landing.how_it_works_demo_title") }}
              </h2>
              <button
                type="button"
                @click="closePosModal"
                class="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                :aria-label="t('landing.how_it_works_close_modal')"
              >
                <svg
                  class="w-5 h-5"
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
            <div
              class="p-2 bg-gray-900 rounded-b-2xl"
              style="min-height: 500px"
            >
              <iframe
                v-if="posDemoUrl"
                :src="posDemoUrl"
                title="SATFLUX PoS Demo"
                class="w-full h-[500px] rounded-lg border-0"
                allow="payment"
              />
            </div>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from "vue";
import { useI18n } from "vue-i18n";
import LandingPayButtonEmbed from "./LandingPayButtonEmbed.vue";
import LandingRafflePresenterPreview from "./LandingRafflePresenterPreview.vue";
import { useBtcPayUrl } from "../../composables/useBtcPayUrl";

const { t } = useI18n();
const { btcPayUrl, displayLightningDomain } = useBtcPayUrl();

const showPosModal = ref(false);
const modalRef = ref<HTMLElement | null>(null);
const previousActiveElement = ref<HTMLElement | null>(null);

const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), iframe, [tabindex]:not([tabindex="-1"])';

function getModalFocusableElements(): HTMLElement[] {
  if (!modalRef.value) return [];
  return Array.from(
    modalRef.value.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
  ).filter(
    (el: HTMLElement) =>
      el.offsetParent !== null || el === document.activeElement,
  );
}

function openPosModal() {
  previousActiveElement.value =
    document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
  showPosModal.value = true;
}

function closePosModal() {
  showPosModal.value = false;
  const restore = previousActiveElement.value;
  previousActiveElement.value = null;
  if (restore) {
    requestAnimationFrame(() => restore.focus());
  }
}

function onModalKeydown(event: KeyboardEvent) {
  if (event.key !== "Tab" || !modalRef.value) return;

  const focusable = getModalFocusableElements();
  if (focusable.length === 0) {
    event.preventDefault();
    modalRef.value.focus();
    return;
  }

  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  const active = document.activeElement;

  if (focusable.length === 1) {
    event.preventDefault();
    first.focus();
    return;
  }

  if (event.shiftKey) {
    if (active === first || active === modalRef.value) {
      event.preventDefault();
      last.focus();
    }
  } else if (active === last || active === modalRef.value) {
    event.preventDefault();
    first.focus();
  }
}

watch(showPosModal, (isOpen: boolean) => {
  if (!isOpen) return;
  void nextTick(() => {
    requestAnimationFrame(() => modalRef.value?.focus());
  });
});

/** Prefer `VITE_POS_DEMO_URL`; otherwise uses `BTCPAY_BASE_URL` from the server via `/api/config`. */
const posDemoUrl = computed(() => {
  const vite = (import.meta.env.VITE_POS_DEMO_URL as string) || "";
  if (vite) return vite;
  const b = btcPayUrl.value;
  return b ? `${b}/` : "";
});
</script>
