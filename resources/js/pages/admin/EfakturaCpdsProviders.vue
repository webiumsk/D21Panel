<template>
  <div
    class="min-h-0 flex-1 max-md:flex-none max-md:overflow-visible md:overflow-y-auto overscroll-y-contain custom-scrollbar"
  >
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="mb-8 flex justify-between items-center">
        <div>
          <h1 class="text-3xl font-bold text-white">{{ t('admin.efaktura.providers_title') }}</h1>
          <p class="text-gray-400 mt-1">{{ t('admin.efaktura.providers_description') }}</p>
        </div>
        <button
          class="inline-flex items-center px-5 py-2.5 text-sm font-medium rounded-xl text-white bg-indigo-600 hover:bg-indigo-500"
          @click="openCreate"
        >
          {{ t('admin.efaktura.add_provider') }}
        </button>
      </div>

      <p class="text-xs text-amber-400/80 mb-6">{{ t('admin.efaktura.verified_only_hint') }}</p>

      <div v-if="loadError" class="bg-red-500/10 border border-red-500/40 rounded-xl px-4 py-3 mb-6 text-sm text-red-300">
        {{ loadError }}
      </div>

      <div class="bg-gray-800 rounded-xl border border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-700">
            <thead class="bg-gray-900/50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.efaktura.name') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.efaktura.base_url') }}</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase">{{ t('admin.efaktura.active') }}</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-400 uppercase">{{ t('admin.regwatch.actions') }}</th>
              </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
              <tr v-if="loading">
                <td colspan="4" class="px-6 py-12 text-center text-gray-400">...</td>
              </tr>
              <tr v-else-if="providers.length === 0">
                <td colspan="4" class="px-6 py-12 text-center text-gray-400">{{ t('admin.efaktura.no_providers') }}</td>
              </tr>
              <tr v-else v-for="provider in providers" :key="provider.id" class="hover:bg-gray-700/50">
                <td class="px-6 py-4 text-sm font-medium text-white">{{ provider.name }}</td>
                <td class="px-6 py-4 text-sm text-gray-300 font-mono">{{ provider.base_url }}</td>
                <td class="px-6 py-4">
                  <span
                    :class="[
                      'px-2 py-1 text-xs font-semibold rounded-full',
                      provider.active ? 'bg-green-500/20 text-green-400' : 'bg-gray-500/20 text-gray-400',
                    ]"
                  >
                    {{ provider.active ? t('admin.efaktura.active') : t('admin.efaktura.inactive') }}
                  </span>
                </td>
                <td class="px-6 py-4 text-right text-sm font-medium space-x-3">
                  <button class="text-indigo-400 hover:text-indigo-300" @click="openEdit(provider)">
                    {{ t('admin.regwatch.edit') }}
                  </button>
                  <button class="text-red-400 hover:text-red-300" @click="remove(provider)">
                    {{ t('admin.efaktura.delete') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Create/edit modal -->
      <div
        v-if="editing"
        class="fixed inset-0 bg-black bg-opacity-70 z-50 flex items-center justify-center p-4 backdrop-blur-sm"
        @click.self="editing = null"
      >
        <div class="bg-gray-800 rounded-xl border border-gray-700 max-w-xl w-full max-h-[85vh] overflow-y-auto">
          <div class="px-6 py-4 border-b border-gray-700">
            <h2 class="text-lg font-semibold text-white">
              {{ editing.id ? t('admin.efaktura.edit_provider') : t('admin.efaktura.add_provider') }}
            </h2>
          </div>

          <div class="px-6 py-4 space-y-4">
            <div>
              <label for="cpds-name" class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.efaktura.name') }}</label>
              <input
                id="cpds-name"
                v-model="form.name"
                type="text"
                maxlength="128"
                class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label for="cpds-base-url" class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.efaktura.base_url') }}</label>
              <input
                id="cpds-base-url"
                v-model="form.base_url"
                type="url"
                placeholder="https://"
                class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white font-mono text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label for="cpds-send-detail-path" class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.efaktura.send_detail_path') }}</label>
              <input
                id="cpds-send-detail-path"
                v-model="form.send_detail_path"
                type="text"
                placeholder="/sapi/v1/document/send/{id}"
                class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white font-mono text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
              <p class="text-xs text-gray-500 mt-1">{{ t('admin.efaktura.send_detail_path_hint') }}</p>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <label class="flex items-center gap-2 text-sm text-gray-300">
                <input v-model="form.active" type="checkbox" class="rounded border-gray-600 text-indigo-600" />
                {{ t('admin.efaktura.active') }}
              </label>
              <div>
                <label for="cpds-sort-order" class="block text-sm font-medium text-gray-300 mb-2">{{ t('admin.efaktura.sort_order') }}</label>
                <input
                  id="cpds-sort-order"
                  v-model.number="form.sort_order"
                  type="number"
                  min="0"
                  max="10000"
                  class="block w-full px-4 py-2 border border-gray-600 rounded-lg bg-gray-700/50 text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                />
              </div>
            </div>
            <p v-if="saveError" class="text-sm text-red-400">{{ saveError }}</p>
          </div>

          <div class="px-6 py-4 border-t border-gray-700 flex items-center justify-end space-x-2">
            <button class="px-4 py-2 text-sm text-gray-300 hover:text-white" @click="editing = null">
              {{ t('admin.regwatch.cancel') }}
            </button>
            <button
              class="px-4 py-2 text-sm font-medium rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="saving"
              @click="save"
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
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { adminEfakturaApi } from '../../services/api';
import { getApiErrorMessage } from '../../composables/useApiError';

const { t } = useI18n();

interface ProviderRow {
  id: string;
  name: string;
  base_url: string;
  send_detail_path: string | null;
  active: boolean;
  sort_order: number;
}

const loading = ref(false);
const providers = ref<ProviderRow[]>([]);
const loadError = ref('');

const editing = ref<{ id: string | null } | null>(null);
const saving = ref(false);
const saveError = ref('');
const form = reactive({
  name: '',
  base_url: '',
  send_detail_path: '',
  active: true,
  sort_order: 0,
});

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = '';
  try {
    const response = await adminEfakturaApi.cpdsProviders.index();
    providers.value = response.data.data;
  } catch {
    providers.value = [];
    loadError.value = t('admin.efaktura.load_error');
  } finally {
    loading.value = false;
  }
}

function openCreate(): void {
  saveError.value = '';
  form.name = '';
  form.base_url = '';
  form.send_detail_path = '';
  form.active = true;
  form.sort_order = providers.value.length;
  editing.value = { id: null };
}

function openEdit(provider: ProviderRow): void {
  saveError.value = '';
  form.name = provider.name;
  form.base_url = provider.base_url;
  form.send_detail_path = provider.send_detail_path ?? '';
  form.active = provider.active;
  form.sort_order = provider.sort_order;
  editing.value = { id: provider.id };
}

async function save(): Promise<void> {
  if (!editing.value) return;
  saving.value = true;
  saveError.value = '';
  const payload = {
    name: form.name,
    base_url: form.base_url,
    send_detail_path: form.send_detail_path || null,
    active: form.active,
    sort_order: form.sort_order,
  };
  try {
    if (editing.value.id) {
      await adminEfakturaApi.cpdsProviders.update(editing.value.id, payload);
    } else {
      await adminEfakturaApi.cpdsProviders.store(payload);
    }
    editing.value = null;
    await load();
  } catch (error) {
    saveError.value = getApiErrorMessage(error, t('admin.efaktura.save_error'));
  } finally {
    saving.value = false;
  }
}

async function remove(provider: ProviderRow): Promise<void> {
  if (!window.confirm(t('admin.efaktura.delete_confirm', { name: provider.name }))) return;
  try {
    await adminEfakturaApi.cpdsProviders.destroy(provider.id);
    await load();
  } catch (error) {
    loadError.value = getApiErrorMessage(error, t('admin.efaktura.save_error'));
  }
}

onMounted(() => {
  void load();
});
</script>
