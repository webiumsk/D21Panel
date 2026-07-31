import { createRouter, createWebHistory } from 'vue-router';
import { invoicingRoutes } from './invoicingRoutes';
import { useAuthStore } from '../store/auth';
import { useStoresStore } from '../store/stores';
import { storesApi, walletApi } from '../services/api';
import { updatePageMeta } from '../composables/usePageMeta';
import { ensureEvoluBoundToAccountSeed } from '../evolu/bootstrap';
import { getStoredAccountMnemonic } from '../services/accountSeed';
import { isInvoicingLocalFirst } from '../evolu/flags';

/** Guest sessions are PoS-oriented: block the SPA until wallet (Lightning or Cashu) is actually configured. */
async function isGuestWalletReady(storeId: string): Promise<boolean> {
    try {
        const store = await storesApi.get(storeId);
        const walletType = store?.wallet_type;

        if (walletType == null || (walletType as string) === '') {
            return false;
        }

        if (walletType === 'cashu') {
            const s = await walletApi.cashu.getSettings(storeId);
            const mint = typeof s.mint_url === 'string' ? s.mint_url.trim() : '';
            const lnAddr = typeof s.lightning_address === 'string' ? s.lightning_address.trim() : '';
            return mint !== '' && lnAddr !== '';
        }

        const conn = await walletApi.connection.get(storeId);
        return conn?.status === 'connected';
    } catch {
        return false;
    }
}

async function resolveGuestPrimaryStoreId(): Promise<string | null> {
    const storesStore = useStoresStore();
    if (storesStore.stores?.length) {
        return storesStore.stores[0].id;
    }
    try {
        const list = await storesApi.list();
        storesStore.stores = list;
        return list[0]?.id ?? null;
    } catch {
        return null;
    }
}

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            name: 'landing',
            component: () => import('../pages/Landing.vue'),
            meta: { public: true, titleKey: 'seo.landing_title', descriptionKey: 'seo.landing_description' },
        },
        {
            path: '/dashboard',
            name: 'home',
            component: () => import('../pages/Dashboard.vue'),
            meta: { requiresAuth: true, titleKey: 'seo.dashboard_title' },
        },
        {
            path: '/dashboard/stats',
            redirect: () => ({ name: 'home' }),
        },
        {
            path: '/login',
            name: 'login',
            component: () => import('../pages/auth/Login.vue'),
            meta: { requiresGuest: true, titleKey: 'seo.login_title', descriptionKey: 'seo.login_description' },
        },
        {
            path: '/register',
            name: 'register',
            component: () => import('../pages/auth/Register.vue'),
            meta: { requiresGuest: true, titleKey: 'seo.register_title', descriptionKey: 'seo.register_description' },
        },
        {
            path: '/password/reset',
            name: 'password-reset',
            component: () => import('../pages/auth/PasswordReset.vue'),
            meta: { requiresGuest: true, titleKey: 'seo.password_reset_title', descriptionKey: 'seo.password_reset_description' },
        },
        {
            path: '/auth/verify-email/:id/:hash',
            name: 'verify-email',
            component: () => import('../pages/auth/VerifyEmail.vue'),
        },
        {
            path: '/pricing',
            name: 'pricing',
            component: () => import('../pages/Pricing.vue'),
            meta: { public: true, titleKey: 'seo.pricing_title' },
        },
        {
            path: '/account/check-email',
            name: 'account-check-email',
            component: () => import('../pages/account/PendingEmailVerification.vue'),
            meta: {
                requiresAuth: true,
                titleKey: 'seo.account_check_email_title',
                descriptionKey: 'seo.account_check_email_description',
            },
        },
        {
            path: '/account',
            name: 'account',
            component: () => import('../pages/account/Profile.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/account/profile',
            redirect: { name: 'account' },
        },
        {
            path: '/messages',
            name: 'messages',
            component: () => import('../pages/Messages.vue'),
            meta: { requiresAuth: true, titleKey: 'messages.title' },
        },
        {
            path: '/info',
            name: 'info',
            component: () => import('../pages/Info.vue'),
            meta: { requiresAuth: true, titleKey: 'common.info_page_title' },
        },
        {
            path: '/dev/evolu',
            name: 'dev-evolu',
            component: () => import('../pages/dev/EvoluPoc.vue'),
            meta: { requiresAuth: true, titleKey: 'evolu.poc_title' },
        },
        {
            path: '/invoicing',
            component: () => import('../layouts/InvoicingEvoluLayout.vue'),
            meta: { requiresAuth: true },
            children: invoicingRoutes,
        },
        {
            path: '/stores',
            name: 'stores',
            component: () => import('../pages/stores/Index.vue'),
            meta: { requiresAuth: true, titleKey: 'seo.stores_title' },
        },
        {
            path: '/stores/create',
            name: 'stores-create',
            component: () => import('../pages/stores/Create.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id',
            name: 'stores-show',
            component: () => import('../pages/stores/Show.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/next-steps',
            name: 'stores-next-steps',
            component: () => import('../pages/stores/NextSteps.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/checklist',
            name: 'stores-checklist',
            component: () => import('../pages/stores/Checklist.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/settings',
            name: 'stores-settings',
            redirect: to => {
                return { name: 'stores-show', params: { id: to.params.id }, query: { section: 'settings' } }
            }
        },
        {
            path: '/stores/:id/wallet-connection',
            name: 'stores-wallet-connection',
            component: () => import('../pages/stores/WalletConnection.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/cashu',
            name: 'stores-cashu',
            redirect: (to) => ({
                name: 'stores-show',
                params: { id: to.params.id as string },
                query: { section: 'cashu' },
            }),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/apps',
            name: 'stores-apps',
            component: () => import('../pages/stores/Apps.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/apps/create',
            name: 'stores-apps-create',
            component: () => import('../pages/stores/AppsCreate.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/lightning-addresses',
            name: 'stores-lightning-addresses',
            component: () => import('../pages/stores/LightningAddresses.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/pay-button',
            name: 'stores-pay-button',
            component: () => import('../pages/stores/PayButton.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/tickets',
            name: 'stores-tickets',
            component: () => import('../pages/stores/StoreTickets.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/raffles',
            name: 'stores-raffles',
            component: () => import('../pages/stores/RafflesIndex.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/raffles/create',
            name: 'stores-raffles-create',
            component: () => import('../pages/stores/RafflesCreate.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/raffles/:raffleId',
            name: 'stores-raffles-show',
            component: () => import('../pages/stores/RaffleShow.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/api-keys',
            name: 'stores-api-keys',
            component: () => import('../pages/stores/ApiKeys.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/stripe',
            name: 'stores-stripe',
            component: () => import('../pages/stores/Stripe.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/sepa',
            name: 'stores-sepa',
            component: () => import('../pages/stores/Sepa.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/apps/:appId',
            name: 'stores-apps-show',
            component: () => import('../pages/stores/AppsShow.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/invoices',
            name: 'stores-invoices',
            redirect: to => {
                return { name: 'stores-show', params: { id: to.params.id }, query: { section: 'invoices' } }
            }
        },
        {
            path: '/stores/:id/ticket-check-in/:eventId',
            name: 'stores-ticket-check-in',
            component: () => import('../pages/stores/TicketCheckIn.vue'),
            meta: { public: true },
        },
        {
            path: '/stores/:id/invoices/:invoiceId',
            name: 'stores-invoices-show',
            component: () => import('../pages/stores/InvoiceShow.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/stores/:id/exports',
            name: 'stores-exports',
            redirect: to => {
                return { name: 'stores-show', params: { id: to.params.id }, query: { section: 'reports' } }
            }
        },
        {
            path: '/stores/:id/reports',
            name: 'stores-reports',
            redirect: to => {
                return { name: 'stores-show', params: { id: to.params.id }, query: { section: 'reports' } }
            }
        },
        {
            path: '/legal/terms',
            name: 'legal-terms',
            component: () => import('../pages/legal/TermsOfService.vue'),
            meta: { public: true, titleKey: 'seo.legal_terms_title', descriptionKey: 'seo.legal_terms_description' },
        },
        {
            path: '/legal/privacy',
            name: 'legal-privacy',
            component: () => import('../pages/legal/PrivacyPolicy.vue'),
            meta: { public: true, titleKey: 'seo.legal_privacy_title', descriptionKey: 'seo.legal_privacy_description' },
        },
        {
            path: '/legal/imprint',
            name: 'legal-imprint',
            component: () => import('../pages/legal/Imprint.vue'),
            meta: { public: true, titleKey: 'seo.legal_imprint_title', descriptionKey: 'seo.legal_imprint_description' },
        },
        {
            path: '/legal/dpa',
            name: 'legal-dpa',
            component: () => import('../pages/legal/DataProcessingAgreement.vue'),
            meta: { public: true, titleKey: 'seo.legal_dpa_title', descriptionKey: 'seo.legal_dpa_description' },
        },
        {
            path: '/support',
            name: 'support',
            component: () => import('../pages/Support.vue'),
            meta: { public: true, titleKey: 'seo.support_title', descriptionKey: 'seo.support_description' },
        },
        {
            path: '/success',
            name: 'subscription-success',
            component: () => import('../pages/BillingSuccess.vue'),
            meta: { public: true },
        },
        {
            path: '/billing/success',
            name: 'billing-success',
            component: () => import('../pages/BillingSuccess.vue'),
            meta: { public: true },
        },
        {
            path: '/support/wallet-connections',
            name: 'support-wallet-connections',
            component: () => import('../pages/support/WalletConnections.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/admin',
            name: 'admin-dashboard',
            component: () => import('../pages/admin/Dashboard.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
        {
            path: '/admin/system-health',
            name: 'admin-system-health',
            component: () => import('../pages/admin/SystemHealth.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/regwatch',
            name: 'admin-regwatch',
            component: () => import('../pages/admin/regwatch/Changes.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/regwatch/rules',
            name: 'admin-regwatch-rules',
            component: () => import('../pages/admin/regwatch/Rules.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/efaktura-cpds',
            name: 'admin-efaktura-cpds',
            component: () => import('../pages/admin/EfakturaCpdsProviders.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/users',
            name: 'admin-users',
            component: () => import('../pages/admin/Users.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/users/:id',
            name: 'admin-user-detail',
            component: () => import('../pages/admin/UserDetail.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/documentation',
            name: 'documentation',
            component: () => import('../pages/documentation/Index.vue'),
            meta: { public: true, titleKey: 'seo.documentation_title', descriptionKey: 'seo.documentation_description' },
        },
        {
            path: '/documentation/:slug',
            name: 'documentation-show',
            component: () => import('../pages/documentation/Show.vue'),
            meta: { public: true, titleKey: 'seo.documentation_title', descriptionKey: 'seo.documentation_description' },
        },
        {
            path: '/faq',
            name: 'faq',
            component: () => import('../pages/faq/Index.vue'),
            meta: { public: true, titleKey: 'seo.faq_title', descriptionKey: 'seo.faq_description' },
        },
        {
            path: '/admin/documentation',
            name: 'admin-documentation',
            component: () => import('../pages/admin/documentation/Articles.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
        {
            path: '/admin/documentation/articles/create',
            name: 'admin-documentation-articles-create',
            component: () => import('../pages/admin/documentation/ArticleForm.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
        {
            path: '/admin/documentation/articles/:id/edit',
            name: 'admin-documentation-articles-edit',
            component: () => import('../pages/admin/documentation/ArticleForm.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
        {
            path: '/admin/documentation/categories',
            name: 'admin-documentation-categories',
            component: () => import('../pages/admin/documentation/Categories.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
        {
            path: '/admin/faq',
            name: 'admin-faq',
            component: () => import('../pages/admin/faq/Items.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
        {
            path: '/admin/faq/items/create',
            name: 'admin-faq-items-create',
            component: () => import('../pages/admin/faq/ItemForm.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
        {
            path: '/admin/faq/items/:id/edit',
            name: 'admin-faq-items-edit',
            component: () => import('../pages/admin/faq/ItemForm.vue'),
            meta: { requiresAuth: true, requiresSupportOrAdmin: true },
        },
    ],
});

// Navigation guards
router.beforeEach(async (to, from, next) => {
    const authStore = useAuthStore();

    if (to.meta.public) {
        if (
            !authStore.user
            && to.name !== 'login'
            && to.name !== 'register'
            && to.name !== 'password-reset'
        ) {
            void authStore.fetchUser().catch(() => {});
        }
        next();
        return;
    }

    // Fetch user info for protected pages to determine auth state
    if (!authStore.user && to.name !== 'login' && to.name !== 'register' && to.name !== 'password-reset') {
        try {
            await authStore.fetchUser();
        } catch (error) {
            // If fetch fails (401), user is null which is correct
        }
    }

    if (
        authStore.isAuthenticated
        && isInvoicingLocalFirst()
        && !getStoredAccountMnemonic()
        && (to.path.startsWith('/invoicing') || String(to.name ?? '').startsWith('invoicing'))
    ) {
        next({ name: 'account', query: { restore_phrase: '1' } });
        return;
    }

    if (
        authStore.isAuthenticated
        && isInvoicingLocalFirst()
        && getStoredAccountMnemonic()
        && to.meta.requiresAuth
    ) {
        try {
            await ensureEvoluBoundToAccountSeed();
        } catch {
            // Evolu bootstrap is best-effort; do not block navigation (avoids reload loops when WASM fails in dev).
        }
    }

    // Hard gate: guests cannot use the app until wallet setup is complete (Lightning connected or Cashu mint+LN address).
    if (authStore.user?.is_guest) {
        const authOnlyExempt =
            to.name === 'login' ||
            to.name === 'register' ||
            to.name === 'password-reset' ||
            to.name === 'verify-email' ||
            to.name === 'account-check-email' ||
            to.name === 'account';

        if (!authOnlyExempt) {
            const primaryStoreId = await resolveGuestPrimaryStoreId();
            if (!primaryStoreId) {
                if (to.name !== 'stores-create') {
                    next({ name: 'stores-create' });
                    return;
                }
                next();
                return;
            }

            const routeStoreId =
                typeof to.params.id === 'string'
                    ? to.params.id
                    : Array.isArray(to.params.id)
                      ? to.params.id[0]
                      : null;
            if (routeStoreId && routeStoreId !== primaryStoreId) {
                next({ name: 'stores-wallet-connection', params: { id: primaryStoreId } });
                return;
            }

            const ready = await isGuestWalletReady(primaryStoreId);
            if (!ready && (to.name !== 'stores-wallet-connection' || to.params.id !== primaryStoreId)) {
                next({ name: 'stores-wallet-connection', params: { id: primaryStoreId } });
                return;
            }

            if (to.name === 'stores-create') {
                next({ name: 'stores-show', params: { id: primaryStoreId } });
                return;
            }
        }
    }

    if (
        authStore.user?.is_guest
        && (to.path.startsWith('/invoicing') || to.name?.toString().startsWith('invoicing'))
    ) {
        next({ name: 'home', query: { tab: 'invoicing' } });
        return;
    }

    if (to.meta.requiresAuth && !authStore.isAuthenticated) {
        // Redirect to login, but prevent infinite loops
        if (to.name !== 'login') {
            next({ name: 'login', query: { redirect: to.fullPath } });
        } else {
            next(); // Already on login page, allow it
        }
    } else if (to.meta.requiresAdmin && authStore.user?.role !== 'admin') {
        // Redirect to dashboard if not admin
        next({ name: 'home' });
    } else if (to.meta.requiresSupportOrAdmin && (!authStore.user || (authStore.user.role !== 'support' && authStore.user.role !== 'admin'))) {
        // Redirect to dashboard if not support or admin
        next({ name: 'home' });
    } else if (to.meta.requiresGuest && authStore.isAuthenticated) {
        next({ name: 'home' });
    } else {
        next();
    }
});

// Update document title and meta description on route change
router.afterEach((to) => {
    updatePageMeta(to);
});

export default router;
