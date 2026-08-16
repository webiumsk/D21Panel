import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import type { Component } from "vue";
import { getStoredAccountMnemonic } from "../services/accountSeed";

const mocks = vi.hoisted(() => ({
    route: { query: { restore_phrase: "1" } as Record<string, string | undefined> },
    routerReplace: vi.fn(),
    flashSuccess: vi.fn(),
    flashWarning: vi.fn(),
    flashError: vi.fn(),
    restoreWithAccountPasskey: vi.fn(),
    unlockDeviceWithPassphrase: vi.fn(),
    unlockDeviceWithPasskey: vi.fn(),
    bindRecoveryPhraseOnThisDevice: vi.fn(),
    clearSessionAccountMnemonic: vi.fn(),
    previewOwnerSwitchImpact: vi.fn(),
    resetEvoluBootstrapForRetry: vi.fn(),
    storeAccountMnemonic: vi.fn(),
    isDeviceRemembered: vi.fn(),
    listDevicePasskeySlots: vi.fn(),
    listAccountEnvelopes: vi.fn(),
    reloadApp: vi.fn(),
    apiGet: vi.fn(),
    invoicingCompaniesList: vi.fn(),
}));

vi.mock("vue-router", () => ({
    useRoute: () => mocks.route,
    useRouter: () => ({
        replace: mocks.routerReplace,
        push: vi.fn(),
    }),
}));

vi.mock("../store/auth", () => ({
    useAuthStore: () => ({
        isAuthenticated: true,
        user: {
            id: 1,
            name: "Merchant",
            email: "merchant@example.com",
            role: "free",
            is_guest: false,
            guest_recovery_enrolled: true,
            can_use_password_login: true,
            evolu_relay_url: null,
        },
        fetchUser: vi.fn(),
        enrollGuestRecoveryPublicKey: vi.fn(),
    }),
}));

vi.mock("../store/flash", () => ({
    useFlashStore: () => ({
        success: mocks.flashSuccess,
        warning: mocks.flashWarning,
        error: mocks.flashError,
    }),
}));

vi.mock("../composables/usePricing", async () => {
    const { computed } = await import("vue");
    return {
        proEffectiveMonthlySats: (pro: { sats_per_year: number }) => Math.round(pro.sats_per_year / 12),
        proHasMonthlyDiscount: () => false,
        usePricing: () => ({
            pricing: computed(() => ({
                trial_days: 30,
                grace_days: 30,
                free: { sats_per_year: 0 },
                pro: { sats_per_year: 210000, sats_per_month_display: 21000 },
            })),
            formatSats: (amount: number) => `${amount} sats`,
            load: vi.fn(async () => undefined),
        }),
    };
});

vi.mock("../composables/usePlanFeatures", async () => {
    const { computed } = await import("vue");
    return {
        usePlanFeatures: () => ({
            planFeatures: computed(() => ({
                invoicing_highlight_keys: [],
                free: { feature_keys: [] },
                pro: { feature_keys: [] },
                enterprise: { feature_keys: [] },
            })),
            isInvoicingFeature: () => false,
            load: vi.fn(async () => undefined),
        }),
    };
});

vi.mock("../composables/useCurrentPlan", async () => {
    const { computed } = await import("vue");
    return {
        useCurrentPlan: () => ({
            planCode: computed(() => "free"),
            hasProOrHigher: computed(() => false),
            canUpgradeToPro: computed(() => true),
        }),
    };
});

vi.mock("../services/api", () => ({
    default: {
        get: mocks.apiGet,
        post: vi.fn(),
        put: vi.fn(),
    },
    invoicingApi: {
        companies: {
            list: mocks.invoicingCompaniesList,
        },
    },
}));

vi.mock("../services/guestRecovery", () => ({
    getStoredGuestMnemonic: vi.fn(() => null),
    storeGuestMnemonic: vi.fn(),
}));

vi.mock("../services/accountSeed", () => ({
    initEvoluFromAccountSeedIfNeeded: vi.fn(async () => "already_synced"),
    bindRecoveryPhraseOnThisDevice: mocks.bindRecoveryPhraseOnThisDevice,
    clearSessionAccountMnemonic: mocks.clearSessionAccountMnemonic,
    deriveRecoveryPublicKeyHex: vi.fn((phrase: string) => `pk:${phrase}`),
    getStoredAccountMnemonic: vi.fn(() => null),
    previewOwnerSwitchImpact: mocks.previewOwnerSwitchImpact,
    storeAccountMnemonic: mocks.storeAccountMnemonic,
}));

vi.mock("../services/deviceUnlock/provider", () => ({
    addAccountPasskeyFromSession: vi.fn(),
    addPasskeyToRememberedDevice: vi.fn(),
    changeDevicePassphrase: vi.fn(),
    forgetDevice: vi.fn(),
    isDeviceRemembered: mocks.isDeviceRemembered,
    listDevicePasskeySlots: mocks.listDevicePasskeySlots,
    passkeyPrfUnlockProvider: { isSupported: vi.fn(async () => true) },
    removeDevicePasskeySlot: vi.fn(),
    rememberDeviceWithPassphrase: vi.fn(),
    restoreWithAccountPasskey: mocks.restoreWithAccountPasskey,
    unlockDeviceWithPasskey: mocks.unlockDeviceWithPasskey,
    unlockDeviceWithPassphrase: mocks.unlockDeviceWithPassphrase,
    upgradeAccountPasskey: vi.fn(),
}));

vi.mock("../services/deviceUnlock/accountPasskeyEnvelope", () => ({
    credentialIdToB64Url: vi.fn((value: string) => value),
    deleteAccountEnvelope: vi.fn(),
    listAccountEnvelopes: mocks.listAccountEnvelopes,
}));

vi.mock("../services/deviceUnlock/passkeyPrf", () => ({
    PasskeyCancelledError: class PasskeyCancelledError extends Error {},
    PasskeyPrfUnsupportedError: class PasskeyPrfUnsupportedError extends Error {},
    PasskeyUnsupportedError: class PasskeyUnsupportedError extends Error {},
}));

vi.mock("../services/deviceUnlock/envelope", () => ({
    isAcceptableDevicePassphrase: vi.fn(() => true),
}));

vi.mock("../evolu/flags", () => ({
    isInvoicingLocalFirst: () => true,
}));

vi.mock("../evolu/bootstrap", () => ({
    ensureEvoluBoundToAccountSeed: vi.fn(async () => undefined),
    resetEvoluBootstrapForRetry: mocks.resetEvoluBootstrapForRetry,
}));

vi.mock("../evolu/reloadGuard", () => ({
    allowEvoluPageReload: vi.fn(),
}));

vi.mock("../evolu/client", () => ({
    evolu: {
        loadQuery: vi.fn(async () => []),
        reloadApp: mocks.reloadApp,
    },
}));

vi.mock("../evolu/config", () => ({
    getEvoluRelayBuildInfo: () => ({ enabled: false, url: "" }),
    normalizeEvoluRelayBaseUrl: (value: string | null | undefined) => value ?? "",
}));

vi.mock("../services/evoluRelayPreference", () => ({
    getEvoluRelayRuntimeInfo: () => ({
        url: "",
        defaultUrl: "",
        profileUrl: "",
        override: { kind: "none" },
    }),
}));

vi.mock("../evolu/evoluRelaySubscription", () => ({
    refreshEvoluRelaySubscription: vi.fn(async () => undefined),
}));

vi.mock("../evolu/invoicingLocalStats", () => ({
    formatByteSize: (value: number) => `${value} B`,
    loadInvoicingLocalStats: vi.fn(async () => ({
        ownerId: null,
        companies: 0,
        contacts: 0,
        documents: 0,
        dbBytes: 0,
    })),
}));

vi.mock("../services/evoluRelayUsageApi", () => ({
    fetchEvoluRelayUsage: vi.fn(async () => null),
    probeRelayReachability: vi.fn(async () => true),
    relayUsagePercent: vi.fn(() => 0),
}));

vi.mock("../evolu/relayOverrideStorage", () => ({
    clearRelayOverride: vi.fn(),
    readRelayOverride: vi.fn(() => ({ kind: "none" })),
    writeRelayOverrideDisabled: vi.fn(),
    writeRelayOverrideUrl: vi.fn(),
}));

vi.mock("../utils/dashboardInvoicingTab", () => ({
    dashboardInvoicingTabPath: () => "/dashboard/invoicing",
}));

function findButton(wrapper: VueWrapper, text: string) {
    const button = wrapper.findAll("button").find((candidate) => candidate.text().includes(text));
    if (!button) {
        throw new Error(`button not found: ${text}`);
    }
    return button;
}

function findLastButton(wrapper: VueWrapper, text: string) {
    const buttons = wrapper.findAll("button").filter((candidate) => candidate.text().includes(text));
    const button = buttons[buttons.length - 1];
    if (!button) {
        throw new Error(`button not found: ${text}`);
    }
    return button;
}

// Profile.vue is a large SFC; compile it once up front (outside the timed
// tests) so the per-test budget covers only mount + interaction, not the
// one-off transform that otherwise makes the first test flaky under load.
let Profile: Component;
beforeAll(async () => {
    Profile = (await import("../pages/account/Profile.vue")).default;
}, 30000);

async function mountProfile(): Promise<VueWrapper> {
    const wrapper = mount(Profile, {
        global: {
            stubs: {
                RouterLink: { template: "<a><slot /></a>" },
                GuestBackupWizardModal: true,
                GuestUpgradeForm: true,
                InvoicingBackupCard: true,
                LocalStorageCard: true,
            },
        },
    });
    await flushPromises();
    await flushPromises();
    return wrapper;
}

describe("Profile recovery phrase owner switch guard", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.route.query = { restore_phrase: "1" };
        mocks.restoreWithAccountPasskey.mockResolvedValue({ recoveryPhrase: "passkey phrase" });
        mocks.unlockDeviceWithPassphrase.mockResolvedValue({ recoveryPhrase: "device phrase" });
        mocks.unlockDeviceWithPasskey.mockResolvedValue({ recoveryPhrase: "device passkey phrase" });
        mocks.bindRecoveryPhraseOnThisDevice.mockResolvedValue("already_synced");
        mocks.previewOwnerSwitchImpact.mockResolvedValue({
            switches: true,
            companies: 1,
            contacts: 2,
            documents: 3,
        });
        mocks.isDeviceRemembered.mockResolvedValue(false);
        mocks.listDevicePasskeySlots.mockResolvedValue([]);
        mocks.listAccountEnvelopes.mockResolvedValue([
            {
                credential_id: "credential-1",
                label: "Main passkey",
                created_at: "2026-07-20T00:00:00Z",
                last_used_at: null,
            },
        ]);
        mocks.apiGet.mockResolvedValue({
            data: {
                subscriber: null,
                billing: null,
                creditBalance: 0,
                creditHistory: [],
            },
        });
        mocks.invoicingCompaniesList.mockResolvedValue([]);
    });

    it("requires confirmation before passkey restore re-links existing local invoicing data", async () => {
        const wrapper = await mountProfile();

        await findButton(wrapper, "account.passkey_restore_button").trigger("click");
        await flushPromises();

        expect(mocks.restoreWithAccountPasskey).toHaveBeenCalledTimes(1);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledWith("passkey phrase");
        expect(mocks.bindRecoveryPhraseOnThisDevice).not.toHaveBeenCalled();
        expect(mocks.resetEvoluBootstrapForRetry).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("auth.guest_restore_owner_switch_title");

        await findButton(wrapper, "auth.guest_restore_owner_switch_confirm").trigger("click");
        await flushPromises();

        expect(mocks.restoreWithAccountPasskey).toHaveBeenCalledTimes(2);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledTimes(1);
        expect(mocks.resetEvoluBootstrapForRetry).toHaveBeenCalledTimes(1);
        expect(mocks.bindRecoveryPhraseOnThisDevice).toHaveBeenCalledWith("passkey phrase");
    }, 15000);

    it("requires confirmation before typed phrase restore re-links existing local invoicing data", async () => {
        const wrapper = await mountProfile();
        await wrapper.find("textarea").setValue("typed phrase");

        await findButton(wrapper, "account.recovery_phrase_restore_on_device_submit").trigger("click");
        await flushPromises();

        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledWith("typed phrase");
        expect(mocks.bindRecoveryPhraseOnThisDevice).not.toHaveBeenCalled();
        expect(mocks.resetEvoluBootstrapForRetry).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("auth.guest_restore_owner_switch_title");

        await findLastButton(wrapper, "auth.guest_restore_owner_switch_confirm").trigger("click");
        await flushPromises();

        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledTimes(1);
        expect(mocks.resetEvoluBootstrapForRetry).toHaveBeenCalledTimes(1);
        expect(mocks.bindRecoveryPhraseOnThisDevice).toHaveBeenCalledWith("typed phrase");
    }, 15000);

    it("requires confirmation before remembered-device passphrase unlock re-links existing local invoicing data", async () => {
        mocks.isDeviceRemembered.mockResolvedValue(true);
        const wrapper = await mountProfile();
        await wrapper.find("input[type='password']").setValue("device passphrase");

        await wrapper.find("form[autocomplete='on']").trigger("submit");
        await flushPromises();

        expect(mocks.unlockDeviceWithPassphrase).toHaveBeenCalledTimes(1);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledWith("device phrase");
        expect(mocks.clearSessionAccountMnemonic).toHaveBeenCalledTimes(1);
        expect(mocks.resetEvoluBootstrapForRetry).not.toHaveBeenCalled();
        expect(mocks.reloadApp).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("auth.guest_restore_owner_switch_title");

        await wrapper.find("input[type='password']").setValue("device passphrase");
        await wrapper.find("form[autocomplete='on']").trigger("submit");
        await flushPromises();

        expect(mocks.unlockDeviceWithPassphrase).toHaveBeenCalledTimes(2);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledTimes(1);
        expect(mocks.resetEvoluBootstrapForRetry).toHaveBeenCalledTimes(1);
        expect(mocks.reloadApp).toHaveBeenCalledTimes(1);
    }, 15000);

    it("still shows the owner-switch warning when the previous session phrase is corrupted", async () => {
        mocks.isDeviceRemembered.mockResolvedValue(true);
        const wrapper = await mountProfile();
        // The unlock submit is the next caller of both mocks: hand it a corrupted
        // previous phrase and make re-storing it throw like the real service does.
        vi.mocked(getStoredAccountMnemonic).mockReturnValueOnce("corrupted junk");
        mocks.storeAccountMnemonic.mockImplementationOnce(() => {
            throw new Error("invalid_mnemonic");
        });
        await wrapper.find("input[type='password']").setValue("device passphrase");

        await wrapper.find("form[autocomplete='on']").trigger("submit");
        await flushPromises();

        // Best-effort restore: the corrupted previous phrase is dropped and the
        // confirmation step still shows instead of surfacing an unlock error.
        expect(mocks.storeAccountMnemonic).toHaveBeenCalledWith("corrupted junk");
        expect(mocks.clearSessionAccountMnemonic).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain("auth.guest_restore_owner_switch_title");
        expect(wrapper.text()).not.toContain("account.device_unlock_failed");
        expect(mocks.reloadApp).not.toHaveBeenCalled();
    }, 15000);

    it("requires confirmation before remembered-device passkey unlock re-links existing local invoicing data", async () => {
        mocks.isDeviceRemembered.mockResolvedValue(true);
        mocks.listDevicePasskeySlots.mockResolvedValue([
            {
                id: "slot-1",
                credentialIdB64: "credential-1",
                label: "Device passkey",
                createdAt: "2026-07-20T00:00:00Z",
                lastUsedAt: null,
            },
        ]);
        const wrapper = await mountProfile();

        await findButton(wrapper, "account.passkey_unlock_button").trigger("click");
        await flushPromises();

        expect(mocks.unlockDeviceWithPasskey).toHaveBeenCalledTimes(1);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledWith("device passkey phrase");
        expect(mocks.clearSessionAccountMnemonic).toHaveBeenCalledTimes(1);
        expect(mocks.resetEvoluBootstrapForRetry).not.toHaveBeenCalled();
        expect(mocks.reloadApp).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("auth.guest_restore_owner_switch_title");

        await findButton(wrapper, "auth.guest_restore_owner_switch_confirm").trigger("click");
        await flushPromises();

        expect(mocks.unlockDeviceWithPasskey).toHaveBeenCalledTimes(2);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledTimes(1);
        expect(mocks.resetEvoluBootstrapForRetry).toHaveBeenCalledTimes(1);
        expect(mocks.reloadApp).toHaveBeenCalledTimes(1);
    }, 15000);
});
