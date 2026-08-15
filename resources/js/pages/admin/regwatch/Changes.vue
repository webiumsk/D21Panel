<template>
  <div
    class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar"
  >
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="mb-8 flex justify-between items-center">
        <div>
          <h1 class="text-3xl font-bold text-white">{{ t('admin.regwatch.title') }}</h1>
          <p class="text-gray-400 mt-1">{{ t('admin.regwatch.description') }}</p>
        </div>
        <router-link
          to="/admin/regwatch/rules"
          class="inline-flex items-center px-5 py-2.5 text-sm font-medium rounded-xl text-gray-200 bg-gray-700 hover:bg-gray-600"
        >
          {{ t('admin.regwatch.rules_title') }}
        </router-link>
      </div>

      <!-- Filters -->
      <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.status') }}</label>
            <Select v-model="statusFilter" :options="statusOptions" :placeholder="t('admin.regwatch.all')" @change="resetAndLoad" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.source') }}</label>
            <Select v-model="sourceFilter" :options="sourceOptions" :placeholder="t('admin.regwatch.all')" @change="resetAndLoad" />
          </div>
        </div>
      </div>

      <div v-if="loadError" class="bg-red-500/10 border border-red-500/40 rounded-xl px-4 py-3 mb-6 text-sm text-red-300">
        {{ loadError }}
      </div>

      <!-- Changes table -->
      <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-700">
            <thead class="bg-gray-900/50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.detected') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.source') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.summary') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.status') }}</th>
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
              <tr v-else-if="changes.length === 0">
                <td colspan="5" class="px-6 py-12 text-center text-gray-400">{{ t('admin.regwatch.no_changes') }}</td>
              </tr>
              <tr v-else v-for="change in changes" :key="change.id" class="hover:bg-gray-700/50">
                <td class="px-6 py-4 text-sm text-gray-300 whitespace-nowrap">{{ formatDate(change.detected_at) }}</td>
                <td class="px-6 py-4 text-sm text-white">
                  <span class="font-medium">{{ change.source?.name }}</span>
                  <span v-if="change.source?.jurisdiction_code" class="ml-2 px-2 py-0.5 text-xs rounded-full bg-indigo-500/20 text-indigo-300">
                    {{ change.source.jurisdiction_code }}
                  </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-400 max-w-md truncate">{{ change.summary || '-' }}</td>
                <td class="px-6 py-4">
                  <span :class="['px-2 py-1 text-xs font-semibold rounded-full', statusBadgeClass(change.status)]">
                    {{ t(`admin.regwatch.status_${change.status}`) }}
                  </span>
                </td>
                <td class="px-6 py-4 text-right text-sm font-medium">
                  <button class="text-indigo-400 hover:text-indigo-300" @click="openDetail(change.id)">
                    {{ t('admin.regwatch.view') }}
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

      <p class="text-xs text-gray-500 mt-4">{{ t('admin.regwatch.disclaimer') }}</p>

      <!-- Detail modal -->
      <div
        v-if="detail"
        class="fixed inset-0 bg-black bg-opacity-70 z-50 flex items-center justify-center p-4 backdrop-blur-sm"
        @click.self="detail = null"
      >
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-4xl w-full max-h-[85vh] overflow-y-auto">
          <div class="px-6 py-4 border-b border-gray-700 flex items-start justify-between">
            <div>
              <h2 class="text-lg font-semibold text-white">
                {{ detail.source?.name }}
                <span v-if="detail.source?.jurisdiction_code" class="ml-2 px-2 py-0.5 text-xs rounded-full bg-indigo-500/20 text-indigo-300">
                  {{ detail.source.jurisdiction_code }}
                </span>
              </h2>
              <a v-if="detail.source?.url" :href="detail.source.url" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-400 hover:text-indigo-300">
                {{ detail.source.url }}
              </a>
            </div>
            <span :class="['px-2 py-1 text-xs font-semibold rounded-full shrink-0', statusBadgeClass(detail.status)]">
              {{ t(`admin.regwatch.status_${detail.status}`) }}
            </span>
          </div>

          <div class="px-6 py-4 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
              <div>
                <span class="text-gray-400">{{ t('admin.regwatch.detected') }}:</span>
                <span class="text-gray-200 ml-1">{{ formatDate(detail.detected_at) }}</span>
              </div>
              <div v-if="detail.reviewed_at">
                <span class="text-gray-400">{{ t('admin.regwatch.reviewed') }}:</span>
                <span class="text-gray-200 ml-1">{{ formatDate(detail.reviewed_at) }}</span>
                <span v-if="detail.reviewed_by_email" class="text-gray-400 ml-1">({{ detail.reviewed_by_email }})</span>
              </div>
            </div>

            <div v-if="detail.classification" class="bg-gray-900/60 rounded-lg p-4 text-sm space-y-1">
              <p class="text-gray-300">
                <span class="font-medium">{{ t('admin.regwatch.classification') }}:</span>
                <span :class="detail.classification.relevant ? 'text-amber-300' : 'text-gray-400'">
                  {{ detail.classification.relevant ? t('admin.regwatch.relevant') : t('admin.regwatch.not_relevant') }}
                </span>
                <span class="text-gray-500 ml-1">({{ detail.classification.confidence }})</span>
              </p>
              <p v-if="detail.classification.topics?.length" class="text-gray-400">
                {{ t('admin.regwatch.topics') }}: {{ detail.classification.topics.join(', ') }}
              </p>
            </div>

            <p v-if="detail.summary" class="text-sm text-gray-200">{{ detail.summary }}</p>

            <div>
              <p class="text-sm font-medium text-gray-300 mb-2">{{ t('admin.regwatch.diff') }}</p>
              <pre class="bg-gray-900 rounded-lg p-4 text-xs text-gray-300 font-mono whitespace-pre-wrap max-h-80 overflow-y-auto">{{ detail.diff || t('admin.regwatch.no_diff') }}</pre>
            </div>

            <p v-if="updateError" class="text-sm text-red-400">{{ updateError }}</p>
          </div>

          <div class="px-6 py-4 border-t border-gray-700 flex items-center justify-between">
            <div class="space-x-2">
              <button
                v-for="target in detail.allowed_transitions"
                :key="target"
                :class="['px-4 py-2 text-sm font-medium rounded-lg disabled:opacity-50 disabled:cursor-not-allowed', transitionButtonClass(target)]"
                :disabled="updating"
                @click="applyTransition(target)"
              >
                {{ t(`admin.regwatch.action_${target}`) }}
              </button>
            </div>
            <button class="px-4 py-2 text-sm text-gray-300 hover:text-white" @click="detail = null">
              {{ t('admin.regwatch.close') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { adminRegwatchApi } from '../../../services/api';
import Select from '../../../components/ui/Select.vue';

const { t } = useI18n();

interface ChangeSource {
  id: string;
  slug: string;
  name: string;
  url: string;
  jurisdiction_code: string | null;
}

interface ChangeClassification {
  relevant: boolean;
  confidence: string;
  topics?: string[];
  summary?: string;
}

interface ChangeRow {
  id: string;
  status: string;
  summary: string | null;
  classification: ChangeClassification | null;
  detected_at: string;
  reviewed_at: string | null;
  reviewed_by_email: string | null;
  allowed_transitions: string[];
  source: ChangeSource | null;
  diff?: string | null;
}

interface SourceRow {
  id: string;
  slug: string;
  name: string;
  jurisdiction_code: string | null;
}

interface Meta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const loading = ref(false);
const changes = ref<ChangeRow[]>([]);
const meta = ref<Meta | null>(null);
const sources = ref<SourceRow[]>([]);
const statusFilter = ref('');
const sourceFilter = ref('');
const page = ref(1);
const detail = ref<ChangeRow | null>(null);
const updating = ref(false);
const updateError = ref('');
const loadError = ref('');

const statusOptions = computed(() => [
  { label: t('admin.regwatch.all'), value: '' },
  { label: t('admin.regwatch.status_new'), value: 'new' },
  { label: t('admin.regwatch.status_reviewed'), value: 'reviewed' },
  { label: t('admin.regwatch.status_applied'), value: 'applied' },
  { label: t('admin.regwatch.status_dismissed'), value: 'dismissed' },
]);

const sourceOptions = computed(() => [
  { label: t('admin.regwatch.all'), value: '' },
  ...sources.value.map((source) => ({
    label: `${source.name}${source.jurisdiction_code ? ` (${source.jurisdiction_code})` : ''}`,
    value: source.id,
  })),
]);

function statusBadgeClass(status: string): string {
  switch (status) {
    case 'new':
      return 'bg-yellow-500/20 text-yellow-400';
    case 'reviewed':
      return 'bg-blue-500/20 text-blue-400';
    case 'applied':
      return 'bg-green-500/20 text-green-400';
    default:
      return 'bg-gray-500/20 text-gray-400';
  }
}

function transitionButtonClass(target: string): string {
  switch (target) {
    case 'applied':
      return 'bg-green-600 hover:bg-green-500 text-white';
    case 'dismissed':
      return 'bg-gray-600 hover:bg-gray-500 text-white';
    default:
      return 'bg-indigo-600 hover:bg-indigo-500 text-white';
  }
}

function formatDate(value: string): string {
  return new Date(value).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

async function loadChanges(): Promise<void> {
  loading.value = true;
  loadError.value = '';
  try {
    const params: { status?: string; source_id?: string; page?: number } = { page: page.value };
    if (statusFilter.value) params.status = statusFilter.value;
    if (sourceFilter.value) params.source_id = sourceFilter.value;
    const response = await adminRegwatchApi.changes.index(params);
    changes.value = response.data.data;
    meta.value = response.data.meta;
  } catch {
    changes.value = [];
    meta.value = null;
    loadError.value = t('admin.regwatch.load_error');
  } finally {
    loading.value = false;
  }
}

async function loadSources(): Promise<void> {
  try {
    const response = await adminRegwatchApi.sources.index();
    sources.value = response.data.data;
  } catch {
    sources.value = [];
    loadError.value = t('admin.regwatch.load_error');
  }
}

function resetAndLoad(): void {
  page.value = 1;
  void loadChanges();
}

function goToPage(target: number): void {
  page.value = target;
  void loadChanges();
}

async function openDetail(id: string): Promise<void> {
  updateError.value = '';
  try {
    const response = await adminRegwatchApi.changes.show(id);
    detail.value = response.data.data;
    loadError.value = '';
  } catch {
    detail.value = null;
    loadError.value = t('admin.regwatch.load_error');
  }
}

async function applyTransition(target: string): Promise<void> {
  if (!detail.value) return;
  updating.value = true;
  updateError.value = '';
  try {
    await adminRegwatchApi.changes.updateStatus(detail.value.id, target);
    await openDetail(detail.value.id);
    await loadChanges();
  } catch {
    updateError.value = t('admin.regwatch.update_error');
  } finally {
    updating.value = false;
  }
}

onMounted(() => {
  void loadSources();
  void loadChanges();
});
</script>
