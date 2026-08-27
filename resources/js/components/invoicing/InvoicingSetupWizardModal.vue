<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-[100] flex items-center justify-center p-4">
      <!-- Backdrop -->
      <div
        class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm"
        @click="close"
      />

      <!-- Modal -->
      <div
        class="relative w-full max-w-lg bg-white border border-gray-200 rounded-2xl shadow-2xl overflow-hidden"
        role="dialog"
        aria-modal="true"
        @click.stop
      >
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
          <div class="flex items-center gap-3">
            <div class="h-10 w-10 rounded-xl bg-emerald-100 flex items-center justify-center">
              <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
            </div>
            <h2 class="text-lg font-bold text-gray-900">{{ t('invoicingOnboarding.title') }}</h2>
          </div>
          <button
            type="button"
            class="p-2 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
            :aria-label="t('common.close')"
            @click="close"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Step indicator -->
        <div class="flex px-6 pt-4 gap-2">
          <button
            v-for="(step, idx) in steps"
            :key="step.key"
            type="button"
            class="flex-1 h-1.5 rounded-full transition-colors"
            :class="currentStep === idx ? 'bg-emerald-500' : completedSteps.includes(idx) ? 'bg-emerald-300' : 'bg-gray-200'"
            :aria-label="t(step.titleKey)"
            @click="currentStep = idx"
          />
        </div>

        <!-- Content -->
        <div class="px-6 py-6 min-h-[220px]">
          <!-- Step 1: Welcome -->
          <div v-show="currentStep === 0" class="space-y-3">
            <h3 class="text-base font-bold text-gray-900">{{ t('invoicingOnboarding.step_welcome_title') }}</h3>
            <p class="text-sm text-gray-600 leading-relaxed">{{ t('invoicingOnboarding.step_welcome_desc') }}</p>
            <div class="invoicing-alert-info">
              {{ t('invoicingOnboarding.step_welcome_local_note') }}
            </div>
          </div>

          <!-- Step 2: Create company -->
          <div v-show="currentStep === 1" class="space-y-3">
            <h3 class="text-base font-bold text-gray-900">{{ t('invoicingOnboarding.step_company_title') }}</h3>
            <p class="text-sm text-gray-600 leading-relaxed">{{ t('invoicingOnboarding.step_company_desc') }}</p>
            <button type="button" class="invoicing-btn-primary mt-2" @click="createCompany">
              {{ t('invoicingOnboarding.create_company') }}
            </button>
          </div>

          <!-- Step 3: What's next -->
          <div v-show="currentStep === 2" class="space-y-3">
            <h3 class="text-base font-bold text-gray-900">{{ t('invoicingOnboarding.step_next_title') }}</h3>
            <p class="text-sm text-gray-600 leading-relaxed">{{ t('invoicingOnboarding.step_next_desc') }}</p>
            <ul class="text-sm text-gray-600 space-y-1.5 mt-2 list-disc list-inside">
              <li>{{ t('invoicingOnboarding.step_next_contacts') }}</li>
              <li>{{ t('invoicingOnboarding.step_next_document') }}</li>
              <li>{{ t('invoicingOnboarding.step_next_checklist') }}</li>
            </ul>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between px-6 py-4 border-t border-gray-100 bg-gray-50">
          <button
            v-if="currentStep > 0"
            type="button"
            class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors"
            @click="currentStep--"
          >
            {{ t('common.previous') }}
          </button>
          <div v-else />
          <div class="flex items-center gap-2">
            <button
              v-if="currentStep < steps.length - 1"
              type="button"
              class="invoicing-btn-primary"
              @click="currentStep++"
            >
              {{ t('common.next') }}
            </button>
            <button
              v-else
              type="button"
              class="invoicing-btn-primary"
              @click="close"
            >
              {{ t('invoicingOnboarding.got_it') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';

const { t } = useI18n();
const router = useRouter();

const emit = defineEmits<{
  close: [];
}>();

const steps = [
  { key: 'welcome', titleKey: 'invoicingOnboarding.step_welcome_title' },
  { key: 'company', titleKey: 'invoicingOnboarding.step_company_title' },
  { key: 'next', titleKey: 'invoicingOnboarding.step_next_title' },
] as const;

const currentStep = ref(0);

const completedSteps = computed(() => {
  const completed: number[] = [];
  for (let i = 0; i < currentStep.value; i++) completed.push(i);
  return completed;
});

function close(): void {
  emit('close');
}

function createCompany(): void {
  emit('close');
  void router.push({ name: 'invoicing-company-new' });
}
</script>
