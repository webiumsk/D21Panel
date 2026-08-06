import { ref, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { walletApi } from '../services/api';
import { getApiErrorMessage } from './useApiError';

export function useSamRockPairing(storeId: () => string) {
    const { t } = useI18n();

    const samrockOtp = ref('');
    const samrockExpiresAt = ref<string | null>(null);
    const samrockQrObjectUrl = ref<string | null>(null);
    const samrockBusy = ref(false);
    const samrockPollStatus = ref('');
    const samrockErrorMessage = ref('');
    let samrockPollInterval: number | null = null;
    let samrockPollInFlight = false;
    let pairingStoreId: string | null = null;
    let pairingFallbackAddress = '';

    function revokeSamRockQr() {
        if (samrockQrObjectUrl.value) {
            URL.revokeObjectURL(samrockQrObjectUrl.value);
            samrockQrObjectUrl.value = null;
        }
    }

    function stopSamRockPolling() {
        if (samrockPollInterval != null) {
            window.clearInterval(samrockPollInterval);
            samrockPollInterval = null;
        }
        samrockPollInFlight = false;
    }

    async function cancelSamRockPairing() {
        stopSamRockPolling();
        revokeSamRockQr();
        const sid = pairingStoreId;
        const otp = samrockOtp.value;
        pairingStoreId = null;
        if (sid && otp) {
            try {
                await walletApi.samrock.deleteOtp(sid, otp);
            } catch {
                // ignore cleanup errors
            }
        }
        samrockOtp.value = '';
        samrockExpiresAt.value = null;
        samrockErrorMessage.value = '';
        samrockPollStatus.value = '';
        samrockBusy.value = false;
    }

    async function pollSamRockStatus(onComplete: () => void | Promise<void>) {
        if (samrockPollInFlight || !samrockOtp.value || !pairingStoreId) {
            return;
        }
        samrockPollInFlight = true;
        const sid = pairingStoreId;
        const otp = samrockOtp.value;
        try {
            const { status, error_message } = await walletApi.samrock.otpStatus(sid, otp);
            if (status === 'success') {
                stopSamRockPolling();
                const completeResult = await walletApi.samrock.complete(sid, {
                    otp,
                    fallback_lightning_address: pairingFallbackAddress,
                });
                samrockOtp.value = '';
                pairingStoreId = null;
                revokeSamRockQr();
                samrockPollStatus.value = t('stores.samrock_pairing_complete');
                if (completeResult?.cashu_fallback_configured === false) {
                    samrockErrorMessage.value = t('stores.samrock_fallback_failed');
                }
                await onComplete();
            } else if (status === 'error') {
                stopSamRockPolling();
                samrockErrorMessage.value = error_message ?? t('stores.samrock_error');
            }
        } catch (err: unknown) {
            samrockErrorMessage.value = getApiErrorMessage(err, t('stores.samrock_error'));
            stopSamRockPolling();
        } finally {
            samrockPollInFlight = false;
        }
    }

    async function startSamRockPairing(
        onComplete: () => void | Promise<void>,
        fallbackLightningAddress: string,
    ) {
        const sid = storeId();
        pairingStoreId = sid;
        pairingFallbackAddress = fallbackLightningAddress.trim();
        samrockBusy.value = true;
        samrockErrorMessage.value = '';
        revokeSamRockQr();
        samrockOtp.value = '';
        stopSamRockPolling();

        try {
            const d = await walletApi.samrock.createOtp(sid, {
                btc: true,
                btcln: true,
                lbtc: false,
                expires_in_seconds: 300,
            });
            const otp = d.otp ?? '';
            if (!otp) {
                samrockErrorMessage.value = t('stores.samrock_error');
                samrockBusy.value = false;
                pairingStoreId = null;
                return;
            }

            samrockOtp.value = otp;
            // Backend returns snake_case expires_at (the old expiresAt read was always null)
            samrockExpiresAt.value = d.expires_at ?? null;

            const qrBlob = await walletApi.samrock.otpQr(sid, otp);
            revokeSamRockQr();
            samrockQrObjectUrl.value = URL.createObjectURL(qrBlob);
            samrockBusy.value = false;
            samrockPollStatus.value = t('stores.samrock_waiting_scan');

            samrockPollInterval = window.setInterval(() => {
                void pollSamRockStatus(onComplete);
            }, 3000);
            // First poll right away - the wallet may scan within seconds.
            void pollSamRockStatus(onComplete);
        } catch (err: unknown) {
            samrockErrorMessage.value = getApiErrorMessage(err, t('stores.samrock_error'));
            samrockBusy.value = false;
            pairingStoreId = null;
        }
    }

    onUnmounted(() => {
        stopSamRockPolling();
        revokeSamRockQr();
    });

    return {
        samrockOtp,
        samrockExpiresAt,
        samrockQrObjectUrl,
        samrockBusy,
        samrockPollStatus,
        samrockErrorMessage,
        cancelSamRockPairing,
        startSamRockPairing,
        stopSamRockPolling,
        revokeSamRockQr,
    };
}
