import { ref, type Ref } from "vue";
import { loginWithAccountPasskey } from "../services/deviceUnlock/provider";
import { DeviceUnlockError } from "../services/deviceUnlock/envelope";
import {
    isPasskeyPrfSupported,
    PasskeyCancelledError,
    PasskeyPrfUnsupportedError,
    PasskeyUnsupportedError,
} from "../services/deviceUnlock/passkeyPrf";
import {
    deriveRecoveryPublicKeyHex,
    previewOwnerSwitchImpact,
    type OwnerSwitchImpact,
} from "../services/accountSeed";
import { trackEvent } from "../services/analytics";

export type PasskeyOwnerSwitchImpact = Extract<OwnerSwitchImpact, { switches: true }>;

/**
 * i18n key for a failed passkey sign-in. Cancellation is handled by the
 * caller (silent); everything else maps to a message that tells the user
 * what to do next instead of a generic failure.
 */
export function passkeyLoginErrorKey(error: unknown): string {
    if (error instanceof PasskeyPrfUnsupportedError) {
        return "auth.passkey_browser_no_prf";
    }
    if (error instanceof PasskeyUnsupportedError) {
        return "account.passkey_unsupported";
    }
    if (error instanceof DeviceUnlockError) {
        return "auth.passkey_no_envelope_hint";
    }
    return "auth.passkey_login_failed";
}

/**
 * Shared "sign in with a passkey" flow (Login page + GuestRestoreModal):
 * one discoverable WebAuthn gesture decrypts the account envelope into the
 * recovery phrase, guarded by the owner-switch two-click confirm (P1
 * data-loss guard). The confirm is keyed by the phrase's derived public key,
 * so the SECOND gesture with the same credential proceeds without holding
 * the phrase in a ref between clicks.
 */
export function usePasskeyAccountLogin(callbacks: {
    /** Runs after the (possibly confirmed) phrase is accepted. */
    onRestore: (recoveryPhrase: string) => Promise<void> | void;
    /** Receives an i18n KEY (see passkeyLoginErrorKey); cancellations never reach it. */
    onError: (messageKey: string) => void;
}): {
    supported: Ref<boolean>;
    loading: Ref<boolean>;
    ownerSwitchImpact: Ref<PasskeyOwnerSwitchImpact | null>;
    probeSupport: () => Promise<void>;
    run: () => Promise<void>;
    reset: () => void;
} {
    const supported = ref(false);
    const loading = ref(false);
    const ownerSwitchImpact = ref<PasskeyOwnerSwitchImpact | null>(null);
    const ownerSwitchConfirmedFor = ref("");

    async function probeSupport(): Promise<void> {
        supported.value = await isPasskeyPrfSupported();
    }

    function reset(): void {
        ownerSwitchImpact.value = null;
        ownerSwitchConfirmedFor.value = "";
    }

    async function run(): Promise<void> {
        loading.value = true;
        try {
            const { recoveryPhrase } = await loginWithAccountPasskey();
            const recoveryPublicKeyHex = deriveRecoveryPublicKeyHex(recoveryPhrase);
            if (ownerSwitchConfirmedFor.value !== recoveryPublicKeyHex) {
                const impact = await previewOwnerSwitchImpact(recoveryPhrase);
                if (impact.switches) {
                    ownerSwitchImpact.value = impact;
                    ownerSwitchConfirmedFor.value = recoveryPublicKeyHex;
                    return;
                }
            }
            reset();
            trackEvent("auth", "passkey_login_success");
            await callbacks.onRestore(recoveryPhrase);
        } catch (rawError) {
            if (!(rawError instanceof PasskeyCancelledError)) {
                reset();
                trackEvent("auth", "passkey_login_failed");
                callbacks.onError(passkeyLoginErrorKey(rawError));
            }
        } finally {
            loading.value = false;
        }
    }

    return { supported, loading, ownerSwitchImpact, probeSupport, run, reset };
}
