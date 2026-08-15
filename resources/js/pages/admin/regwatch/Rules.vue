<template>
  <div
    class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar"
  >
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="mb-8 flex justify-between items-center">
        <div>
          <h1 class="text-3xl font-bold text-white">{{ t('admin.regwatch.rules_title') }}</h1>
          <p class="text-gray-400 mt-1">{{ t('admin.regwatch.rules_description') }}</p>
        </div>
        <router-link
          to="/admin/regwatch"
          class="inline-flex items-center px-5 py-2.5 text-sm font-medium rounded-xl text-gray-200 bg-gray-700 hover:bg-gray-600"
        >
          {{ t('admin.regwatch.title') }}
        </router-link>
      </div>

      <!-- Filters -->
      <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.jurisdiction') }}</label>
            <Select v-model="jurisdictionFilter" :options="jurisdictionOptions" :placeholder="t('admin.regwatch.all')" @change="resetAndLoad" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.topic') }}</label>
            <Select v-model="topicFilter" :options="topicOptions" :placeholder="t('admin.regwatch.all')" @change="resetAndLoad" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.verified') }}</label>
            <Select v-model="verifiedFilter" :options="verifiedOptions" :placeholder="t('admin.regwatch.all')" @change="resetAndLoad" />
          </div>
        </div>
      </div>

      <div v-if="loadError" class="bg-red-500/10 border border-red-500/40 rounded-xl px-4 py-3 mb-6 text-sm text-red-300">
        {{ loadError }}
      </div>

      <!-- Rules table -->
      <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-700">
            <thead class="bg-gray-900/50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.jurisdiction') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.topic') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.rule_title') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.verified') }}</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.actions') }}</th>
              </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
              <tr v-if="loading">
                <td colspan="5" class="px-6 py-12 text-center">
                  <svg class="animate-spin h-8 w-8 text-indigo-500 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                </td>
              </tr>
              <tr v-else-if="rules.length === 0">
                <td colspan="5" class="px-6 py-12 text-center text-gray-400">{{ t('admin.regwatch.no_rules') }}</td>
              </tr>
              <tr v-else v-for="rule in rules" :key="rule.id" class="hover:bg-gray-700/50">
                <td class="px-6 py-4">
                  <span class="px-2 py-0.5 text-xs rounded-full bg-indigo-500/20 text-indigo-300">
                    {{ rule.jurisdiction?.code }}
                  </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-300 whitespace-nowrap">{{ topicLabel(rule.topic) }}</td>
                <td class="px-6 py-4 text-sm font-medium text-white max-w-md truncate">{{ rule.title }}</td>
                <td class="px-6 py-4">
                  <span
                    :class="[
                      'px-2 py-1 text-xs font-semibold rounded-full',
                      rule.verified_on ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400',
                    ]"
                  >
                    {{ rule.verified_on ? `${t('admin.regwatch.verified')} ${rule.verified_on}` : t('admin.regwatch.unverified') }}
                  </span>
                </td>
                <td class="px-6 py-4 text-right text-sm font-medium">
                  <button class="text-indigo-400 hover:text-indigo-300" @click="openEdit(rule.id)">
                    {{ t('admin.regwatch.edit') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="meta && meta.last_page > 1" class="px-6 py-4 border-t border-gray-700 flex items-center justify-between">
          <span class="text-sm text-gray-400">
            {{ t('admin.regwatch.page_of', { current: meta.current_page, last: meta.last_page, total: meta.total }) }}
          </span>
          <div class="space-x-2">
            <button
              class="px-3 py-1.5 text-sm rounded-lg bg-gray-700 text-gray-200 hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="meta.current_page <= 1"
              @click="goToPage(meta.current_page - 1)"
            >
              {{ t('admin.regwatch.previous') }}
            </button>
            <button
              class="px-3 py-1.5 text-sm rounded-lg bg-gray-700 text-gray-200 hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="meta.current_page >= meta.last_page"
              @click="goToPage(meta.current_page + 1)"
            >
              {{ t('admin.regwatch.next') }}
            </button>
          </div>
        </div>
      </div>

      <p class="text-xs text-gray-500 mt-4">{{ t('admin.regwatch.rules_hint') }}</p>

      <!-- Edit modal -->
      <div
        v-if="editing"
        class="fixed inset-0 bg-black bg-opacity-70 z-50 flex items-center justify-center p-4 backdrop-blur-sm"
        @click.self="editing = null"
      >
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-3xl w-full max-h-[85vh] overflow-y-auto">
          <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold text-white">
              {{ topicLabel(editing.topic) }}
              <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-indigo-500/20 text-indigo-300">
                {{ editing.jurisdiction?.code }}
              </span>
            </h2>
            <p class="text-xs text-gray-500 mt-1">{{ editing.slug }}</p>
          </div>

          <div class="px-6 py-4 space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.rule_title') }}</label>
              <input
                v-model="form.title"
                type="text"
                class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.rule_text') }}</label>
              <textarea
                v-model="form.rule_text"
                rows="8"
                class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white font-mono text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              ></textarea>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.source_url') }}</label>
              <input
                v-model="form.source_url"
                type="url"
                class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.verified_on') }}</label>
                <div class="flex gap-2">
                  <input
                    v-model="form.verified_on"
                    type="date"
                    :max="today"
                    class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                  />
                  <button
                    type="button"
                    class="px-3 py-2 text-sm rounded-lg bg-gray-700 text-gray-200 hover:bg-gray-600 shrink-0"
                    @click="form.verified_on = today"
                  >
                    {{ t('admin.regwatch.today') }}
                  </button>
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.effective_from') }}</label>
                <input
                  v-model="form.effective_from"
                  type="date"
                  class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
            </div>

            <p class="text-xs text-amber-400/80">{{ t('admin.regwatch.rules_hint') }}</p>
            <p v-if="saveError" class="text-sm text-red-400">{{ saveError }}</p>
          </div>

          <div class="px-6 py-4 border-t border-gray-700 flex items-center justify-end space-x-2">
            <button class="px-4 py-2 text-sm text-gray-300 hover:text-white" @click="editing = null">
              {{ t('admin.regwatch.cancel') }}
            </button>
            <button
              class="px-4 py-2 text-sm font-medium rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="saving"
              @click="saveRule"
            >
              {{ t('admin.regwatch.save') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { adminRegwatchApi } from '../../../services/api';
import { getApiErrorMessage } from '../../../composables/useApiError';
import Select from '../../../components/ui/Select.vue';

const { t } = useI18n();

interface RuleJurisdiction {
  id: string;
  code: string;
  name: string;
}

interface RuleSource {
  id: string;
  slug: string;
  name: string;
}

interface RuleRow {
  id: string;
  slug: string;
  topic: string;
  title: string;
  source_url: string;
  verified_on: string | null;
  effective_from: string | null;
  jurisdiction: RuleJurisdiction | null;
  source: RuleSource | null;
  rule_text?: string;
}

interface Meta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const TOPICS = [
  'vat_registration',
  'reverse_charge',
  'oss',
  'us_llc_income',
  'income_tax',
  'archiving',
];

const loading = ref(false);
const rules = ref<RuleRow[]>([]);
const meta = ref<Meta | null>(null);
const jurisdictions = ref<RuleJurisdiction[]>([]);
const jurisdictionFilter = ref('');
const topicFilter = ref('');
const verifiedFilter = ref('');
const page = ref(1);
const loadError = ref('');

const editing = ref<RuleRow | null>(null);
const saving = ref(false);
const saveError = ref('');
const form = reactive({
  title: '',
  rule_text: '',
  source_url: '',
  verified_on: '',
  effective_from: '',
});

const today = new Date().toISOString().slice(0, 10);

const jurisdictionOptions = computed(() => [
  { label: t('admin.regwatch.all'), value: '' },
  ...jurisdictions.value.map((j) => ({ label: `${j.code} - ${j.name}`, value: j.id })),
]);

const topicOptions = computed(() => [
  { label: t('admin.regwatch.all'), value: '' },
  ...TOPICS.map((topic) => ({ label: topicLabel(topic), value: topic })),
]);

const verifiedOptions = computed(() => [
  { label: t('admin.regwatch.all'), value: '' },
  { label: t('admin.regwatch.verified'), value: '1' },
  { label: t('admin.regwatch.unverified'), value: '0' },
]);

function topicLabel(topic: string): string {
  return t(`admin.regwatch.topic_${topic}`);
}

async function loadRules(): Promise<void> {
  loading.value = true;
  loadError.value = '';
  try {
    const params: { jurisdiction_id?: string; topic?: string; verified?: string; page?: number } = { page: page.value };
    if (jurisdictionFilter.value) params.jurisdiction_id = jurisdictionFilter.value;
    if (topicFilter.value) params.topic = topicFilter.value;
    if (verifiedFilter.value !== '') params.verified = verifiedFilter.value;
    const response = await adminRegwatchApi.rules.index(params);
    rules.value = response.data.data;
    meta.value = response.data.meta;
  } catch {
    rules.value = [];
    meta.value = null;
    loadError.value = t('admin.regwatch.load_error');
  } finally {
    loading.value = false;
  }
}

async function loadJurisdictions(): Promise<void> {
  try {
    const response = await adminRegwatchApi.jurisdictions.index();
    jurisdictions.value = response.data.data;
  } catch {
    jurisdictions.value = [];
    loadError.value = t('admin.regwatch.load_error');
  }
}

function resetAndLoad(): void {
  page.value = 1;
  void loadRules();
}

function goToPage(target: number): void {
  page.value = target;
  void loadRules();
}

async function openEdit(id: string): Promise<void> {
  saveError.value = '';
  try {
    const response = await adminRegwatchApi.rules.show(id);
    const rule: RuleRow = response.data.data;
    editing.value = rule;
    form.title = rule.title;
    form.rule_text = rule.rule_text ?? '';
    form.source_url = rule.source_url;
    form.verified_on = rule.verified_on ?? '';
    form.effective_from = rule.effective_from ?? '';
    loadError.value = '';
  } catch {
    editing.value = null;
    loadError.value = t('admin.regwatch.load_error');
  }
}

async function saveRule(): Promise<void> {
  if (!editing.value) return;
  saving.value = true;
  saveError.value = '';
  try {
    await adminRegwatchApi.rules.update(editing.value.id, {
      title: form.title,
      rule_text: form.rule_text,
      source_url: form.source_url,
      source_id: editing.value.source?.id ?? null,
      verified_on: form.verified_on || null,
      effective_from: form.effective_from || null,
    });
    editing.value = null;
    await loadRules();
  } catch (error) {
    // Backend 422s carry the specific reason (placeholder verification,
    // cross-jurisdiction source, field validation) - show it when present.
    saveError.value = getApiErrorMessage(error, t('admin.regwatch.save_error'));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  void loadJurisdictions();
  void loadRules();
});
</script>
