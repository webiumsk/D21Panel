import { computed } from 'vue';
import { useAuthStore, type User } from '../store/auth';

export type PlanCode = 'free' | 'pro' | 'enterprise';

/** Resolve effective plan from role, subscription plan, and PRO feature flags. */
export function resolvePlanCode(user: User | null | undefined): PlanCode {
  if (!user) {
    return 'free';
  }

  const role = user.role ?? 'free';
  if (role === 'enterprise' || role === 'admin' || role === 'support') {
    return 'enterprise';
  }
  if (role === 'pro') {
    return 'pro';
  }

  const planCode = user.plan?.code;
  if (planCode === 'enterprise') {
    return 'enterprise';
  }
  if (planCode === 'pro') {
    return 'pro';
  }

  if (
    user.plan_features?.business_invoicing
    || user.plan?.features?.includes('business_invoicing')
  ) {
    return 'pro';
  }

  return 'free';
}

export function useCurrentPlan() {
  const authStore = useAuthStore();

  const planCode = computed(() => resolvePlanCode(authStore.user));

  const hasProOrHigher = computed(
    () => planCode.value === 'pro' || planCode.value === 'enterprise',
  );

  const canUpgradeToPro = computed(
    () => authStore.isAuthenticated && planCode.value === 'free',
  );

  // The server forces isTrial=false at checkout once the one-time trial was
  // consumed - hide "free trial" framing then (the upgrade CTA itself stays).
  const canStartTrial = computed(
    () => canUpgradeToPro.value && !authStore.user?.trial_consumed_at,
  );

  return {
    planCode,
    hasProOrHigher,
    canUpgradeToPro,
    canStartTrial,
  };
}
