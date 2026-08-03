import { useAuthStore } from '../store/auth';
import { isPublicMarketingPath, navigateToAppPath } from '../utils/publicMarketingRoutes';

/**
 * Shared navigation guard logic for public marketing SPA.
 */
export async function runPublicRouteGuard(
    to: {
        meta: Record<string, unknown>;
        name?: string | symbol | null;
        fullPath: string;
        path: string;
        query?: Record<string, unknown>;
    },
    next: (value?: unknown) => void,
): Promise<void> {
    if (!isPublicMarketingPath(to.path)) {
        navigateToAppPath(to.fullPath);
        // Cancel the in-router navigation - the full page load takes over.
        // Without this the guard never settles and vue-router throws
        // "Invalid navigation guard".
        next(false);
        return;
    }

    const authStore = useAuthStore();

    if (
        !authStore.user
        && to.name !== 'login'
        && to.name !== 'register'
        && to.name !== 'password-reset'
    ) {
        try {
            await authStore.fetchUser();
        } catch {
            // Unauthenticated visitors stay on public routes.
        }
    }

    if (to.meta.public) {
        next();
        return;
    }

    if (to.meta.requiresGuest && authStore.isAuthenticated) {
        const redirectQuery = to.query?.redirect;
        const redirect = typeof redirectQuery === 'string' && redirectQuery.trim() !== ''
            ? redirectQuery
            : '/dashboard';
        navigateToAppPath(redirect);
        next(false);
        return;
    }

    next();
}
