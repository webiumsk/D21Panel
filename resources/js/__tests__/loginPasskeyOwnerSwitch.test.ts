import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi, beforeEach } from "vitest";

const mocks = vi.hoisted(() => ({
    loginWithAccountPasskey: vi.fn(),
    restoreGuestFromMnemonic: vi.fn(),
    fetchStores: vi.fn(),
    flashError: vi.fn(),
    previewOwnerSwitchImpact: vi.fn(),
    routerReplace: vi.fn(),
    routerPush: vi.fn(),
    route: { query: {} as Record<string, string> },
}));

vi.mock("../services/deviceUnlock/provider", () => ({
    loginWithAccountPasskey: mocks.loginWithAccountPasskey,
}));

vi.mock("../services/deviceUnlock/passkeyPrf", () => ({
    PasskeyCancelledError: class PasskeyCancelledError extends Error {},
    PasskeyPrfUnsupportedError: class PasskeyPrfUnsupportedError extends Error {},
    PasskeyUnsupportedError: class PasskeyUnsupportedError extends Error {},
    isPasskeyPrfSupported: vi.fn(async () => true),
}));

vi.mock("../services/accountSeed", () => ({
    deriveRecoveryPublicKeyHex: vi.fn((phrase: string) => `pk:${phrase}`),
    previewOwnerSwitchImpact: mocks.previewOwnerSwitchImpact,
}));

vi.mock("../store/auth", () => ({
    useAuthStore: () => ({
        restoreGuestFromMnemonic: mocks.restoreGuestFromMnemonic,
    }),
}));

vi.mock("../store/stores", () => ({
    useStoresStore: () => ({
        fetchStores: mocks.fetchStores,
    }),
}));

vi.mock("../store/flash", () => ({
    useFlashStore: () => ({
        error: mocks.flashError,
    }),
}));

vi.mock("../config/auth", () => ({
    isSeedFirstRegistration: () => false,
}));

vi.mock("../utils/publicMarketingRoutes", () => ({
    isPublicMarketingPath: () => false,
    navigateToAppPath: vi.fn(),
}));

vi.mock("vue-router", () => ({
    useRoute: () => mocks.route,
    useRouter: () => ({
        replace: mocks.routerReplace,
        push: mocks.routerPush,
        currentRoute: { value: { query: {} } },
    }),
}));

function passkeyButton(wrapper: ReturnType<typeof mount>) {
    const button = wrapper.findAll("button").find((candidate) =>
        candidate.text().includes("auth.passkey_login_button")
        || candidate.text().includes("auth.guest_restore_owner_switch_confirm"),
    );
    if (!button) {
        throw new Error("passkey button not found");
    }
    return button;
}

describe("Login passkey owner switch guard", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.route.query = {};
        mocks.loginWithAccountPasskey.mockResolvedValue({ recoveryPhrase: "passkey phrase" });
        mocks.restoreGuestFromMnemonic.mockResolvedValue({});
        mocks.fetchStores.mockResolvedValue([]);
        mocks.previewOwnerSwitchImpact.mockResolvedValue({
            switches: true,
            companies: 1,
            contacts: 2,
            documents: 3,
        });
    });

    it("requires confirmation before passkey login re-links existing local invoicing data", async () => {
        const { default: Login } = await import("../pages/auth/Login.vue");
        const wrapper = mount(Login, {
            global: {
                stubs: {
                    RouterLink: { template: "<a><slot /></a>" },
                    GuestRestoreModal: true,
                    AuthSeedGuestPanel: true,
                },
            },
        });
        await flushPromises();

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.loginWithAccountPasskey).toHaveBeenCalledTimes(1);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledTimes(1);
        expect(mocks.restoreGuestFromMnemonic).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("auth.guest_restore_owner_switch_title");
        expect(passkeyButton(wrapper).text()).toContain("auth.guest_restore_owner_switch_confirm");

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.loginWithAccountPasskey).toHaveBeenCalledTimes(2);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledTimes(1);
        expect(mocks.restoreGuestFromMnemonic).toHaveBeenCalledWith("passkey phrase");
        expect(mocks.routerReplace).toHaveBeenCalledWith("/dashboard");
    });
});
