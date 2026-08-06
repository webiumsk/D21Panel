<template>
    <div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="$emit('close')">
        <div class="relative top-10 mx-auto p-6 border max-w-3xl w-full mx-4 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Reveal Wallet Connection Secret</h3>
                
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3 mb-4">
                    <p class="text-sm text-yellow-800">
                        Warning: Revealing the secret will be logged for audit purposes.
                    </p>
                </div>

                <div v-if="!revealed" class="space-y-4">
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            Confirm Password
                        </label>
                        <input
                            id="password"
                            v-model="password"
                            type="password"
                            class="block w-full p-2 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            placeholder="Enter your password"
                            required
                            autofocus
                        />
                        <p v-if="errors.password" class="mt-1 text-sm text-red-600">{{ errors.password }}</p>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="$emit('close')"
                            class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                        >
                            Cancel
                        </button>
                        <button
                            @click="handleReveal"
                            :disabled="submitting || !password"
                            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                        >
                            {{ submitting ? 'Revealing...' : 'Reveal' }}
                        </button>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <!-- Store Info -->
                    <div class="bg-gray-50 border border-gray-200 rounded-md p-4">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="font-medium text-gray-700">Store:</span>
                                <span class="ml-2 text-gray-900">{{ connection.store_name }}</span>
                            </div>
                            <div>
                                <span class="font-medium text-gray-700">Type:</span>
                                <span class="ml-2 inline-flex items-center gap-1.5">
                                    <WalletTypeIcon
                                      :type="connection.type"
                                      :brand="connection.type === 'aqua_descriptor' || connection.type === 'lnaddress' ? (connection.brand ?? undefined) : undefined"
                                      size="sm"
                                      :show-label="true"
                                    />
                                </span>
                            </div>
                            <div v-if="btcPayStoreId" class="col-span-2">
                                <span class="font-medium text-gray-700">BTCPay Store ID:</span>
                                <code class="ml-2 text-gray-900 font-mono text-xs">{{ btcPayStoreId }}</code>
                            </div>
                        </div>
                    </div>

                    <!-- Connection String -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-sm font-medium text-gray-700">
                                Connection String
                            </label>
                            <span class="text-xs text-gray-500">
                                Auto-hides in {{ countdown }}s
                            </span>
                        </div>
                        <div class="relative">
                            <textarea
                                :value="revealedSecret"
                                readonly
                                :rows="connection.type === 'blink' ? 4 : 6"
                                class="block w-full rounded-md border-2 border-gray-300 shadow-sm bg-gray-900 text-green-400 font-mono text-xs pr-24 resize-none focus:border-indigo-500 focus:ring-indigo-500"
                                ref="secretInput"
                                spellcheck="false"
                            ></textarea>
                            <button
                                @click="copyToClipboard"
                                :class="copySuccess ? 'bg-green-600 text-white border-green-700' : 'bg-indigo-600 text-white hover:bg-indigo-700 border-indigo-700'"
                                class="absolute right-2 top-2 px-3 py-1.5 text-xs font-medium rounded-md transition-all border shadow-sm flex items-center space-x-1"
                            >
                                <svg v-if="copySuccess" class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                                <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                </svg>
                                <span>{{ copySuccess ? 'Copied!' : 'Copy' }}</span>
                            </button>
                        </div>
                        <p v-if="copySuccess" class="mt-1 text-xs text-green-600 flex items-center">
                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            Connection string copied to clipboard!
                        </p>
                    </div>

                    <!-- Instructions -->
                    <div class="border border-gray-200 rounded-md overflow-hidden">
                        <button
                            @click="showInstructions = !showInstructions"
                            class="w-full px-4 py-3 bg-gray-50 hover:bg-gray-100 flex items-center justify-between text-left transition-colors"
                        >
                            <span class="text-sm font-medium text-gray-700">Configuration Instructions</span>
                            <svg class="w-5 h-5 text-gray-500 transition-transform" :class="{ 'rotate-180': showInstructions }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div v-if="showInstructions" class="px-4 py-3 bg-white border-t border-gray-200">
                            <div class="prose prose-sm max-w-none">
                                <div v-if="connection.type === 'blink'" class="space-y-2 text-sm text-gray-700">
                                    <p><strong>Steps to configure Blink wallet:</strong></p>
                                    <ol class="list-decimal list-inside space-y-1 ml-2">
                                        <li>Click "Open Wallet Connection" button below to open Store settings in a new tab</li>
                                        <li>Select "Use custom lightning node"</li>
                                        <li>In the Connection String field, paste the connection string above</li>
                                        <li>Save the settings</li>
                                        <li>Test by creating a test invoice</li>
                                    </ol>
                                    <p class="mt-2 text-xs text-gray-500">
                                        Connection string format: <code class="bg-gray-100 px-1 py-0.5 rounded">type=blink;server=https://api.blink.sv/graphql;api-key=...;wallet-id=...</code>
                                    </p>
                                </div>
                                <div v-else class="space-y-2 text-sm text-gray-700">
                                    <p><strong>Steps to configure Aqua/Bull (Boltz) wallet:</strong></p>
                                    <ol class="list-decimal list-inside space-y-1 ml-2">
                                        <li>Click "Open Wallet Connection" button below to open Store settings in a new tab</li>
                                        <li>Select Boltz plugin (enable it first if not already enabled)</li>
                                        <li>In the Core Descriptor field, paste the descriptor above</li>
                                        <li>Save the settings</li>
                                        <li>Test by creating a test invoice</li>
                                    </ol>
                                    <p class="mt-2 text-xs text-gray-500">
                                        The descriptor is watch-only and does not contain private keys.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex justify-between items-center pt-2 border-t border-gray-200">
                        <a
                            v-if="btcPayStoreUrl"
                            :href="btcPayStoreUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="btcpay-open-link inline-flex items-center px-4 py-2 rounded-xl text-sm font-medium text-orange-700 bg-orange-100 border border-orange-300 hover:bg-orange-200 hover:border-orange-400 transition-all shadow-[inset_0_0_12px_rgba(249,115,22,0.12)] hover:shadow-[inset_0_0_16px_rgba(249,115,22,0.2)]"
                        >
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            Open Wallet Connection
                        </a>
                        <div class="flex space-x-3 ml-auto">
                            <button
                                @click="handleMarkConnected"
                                :disabled="markingConnected"
                                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
                            >
                                {{ markingConnected ? 'Marking...' : 'Mark as Configured' }}
                            </button>
                            <button
                                @click="$emit('close')"
                                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import type { SupportWalletConnection } from "../../services/api";
import { asApiError } from "../../utils/apiError";
import { ref, onUnmounted, watch } from 'vue';
import { supportWalletApi } from '../../services/api';
import WalletTypeIcon from '../WalletTypeIcon.vue';

interface Props {
    connection: SupportWalletConnection;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    close: [];
}>();


const password = ref('');
const submitting = ref(false);
const revealed = ref(false);
const revealedSecret = ref('');
const errors = ref<Record<string, string>>({});
const countdown = ref(60);
const secretInput = ref<HTMLTextAreaElement | null>(null);
const copySuccess = ref(false);
const showInstructions = ref(true);
const markingConnected = ref(false);
const btcPayStoreUrl = ref<string | null>(null);
const btcPayStoreId = ref<string | null>(null);
let countdownTimer: ReturnType<typeof setInterval> | null = null;
let copySuccessTimer: ReturnType<typeof setTimeout> | null = null;

async function handleReveal() {
    submitting.value = true;
    errors.value = {};

    try {
        const reveal = await supportWalletApi.reveal(props.connection.id, {
            password: password.value,
        });

        revealedSecret.value = reveal.secret;
        revealed.value = true;

        // Fetch BTCPay Store URL
        await fetchBtcPayStoreUrl();

        // Start countdown (60 seconds)
        countdown.value = 60;
        countdownTimer = setInterval(() => {
            countdown.value--;
            if (countdown.value <= 0) {
                emit('close');
            }
        }, 1000);

        // Auto-focus and select secret for easy copying
        setTimeout(() => {
            if (secretInput.value) {
                secretInput.value.focus();
                secretInput.value.select();
            }
        }, 100);
    } catch (rawError) {
        const err = asApiError(rawError);
        if (err.response?.status === 422) {
            const validationErrors = err.response?.data?.errors ?? {};
            Object.keys(validationErrors).forEach(key => {
                const v = validationErrors[key];
                errors.value[key] = (Array.isArray(v) ? v[0] : v) ?? "";
            });
        } else {
            errors.value.password = err.response?.data?.message || 'Failed to reveal secret';
        }
    } finally {
        submitting.value = false;
    }
}

async function copyToClipboard() {
    if (!revealedSecret.value) return;

    try {
        await navigator.clipboard.writeText(revealedSecret.value);
        copySuccess.value = true;

        // Reset copy success indicator after 2 seconds
        if (copySuccessTimer) {
            clearTimeout(copySuccessTimer);
        }
        copySuccessTimer = setTimeout(() => {
            copySuccess.value = false;
        }, 2000);
    } catch (err) {
        // Fallback for older browsers
        if (secretInput.value) {
            secretInput.value.select();
            document.execCommand('copy');
            copySuccess.value = true;
            if (copySuccessTimer) {
                clearTimeout(copySuccessTimer);
            }
            copySuccessTimer = setTimeout(() => {
                copySuccess.value = false;
            }, 2000);
        }
    }
}

async function fetchBtcPayStoreUrl() {
    try {
        const info = await supportWalletApi.btcpayStoreUrl(props.connection.id);
        btcPayStoreUrl.value = info.url;
        btcPayStoreId.value = info.store_id ?? null;
    } catch (err) {
        // If endpoint doesn't exist yet, btcPayStoreUrl will remain null
        console.warn('Could not fetch BTCPay store URL:', err);
    }
}

async function handleMarkConnected() {
    if (!confirm('Mark this wallet connection as configured in BTCPay?')) {
        return;
    }

    markingConnected.value = true;
    try {
        await supportWalletApi.markConnected(props.connection.id);
        emit('close');
    } catch (rawError) {
        const err = asApiError(rawError);
        alert(err.response?.data?.message || 'Failed to mark connection as connected');
    } finally {
        markingConnected.value = false;
    }
}

watch(() => props.connection, () => {
    // Reset state when connection changes
    password.value = '';
    revealed.value = false;
    revealedSecret.value = '';
    errors.value = {};
    copySuccess.value = false;
    showInstructions.value = true;
    btcPayStoreUrl.value = null;
    btcPayStoreId.value = null;
    markingConnected.value = false;
    if (countdownTimer) {
        clearInterval(countdownTimer);
        countdownTimer = null;
    }
    if (copySuccessTimer) {
        clearTimeout(copySuccessTimer);
        copySuccessTimer = null;
    }
});

onUnmounted(() => {
    if (countdownTimer) {
        clearInterval(countdownTimer);
    }
    if (copySuccessTimer) {
        clearTimeout(copySuccessTimer);
    }
});
</script>


