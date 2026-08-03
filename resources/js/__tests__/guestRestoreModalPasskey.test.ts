import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi, beforeEach } from "vitest";
import { DeviceUnlockError } from "../services/deviceUnlock/envelope";

const mocks = vi.hoisted(() => ({
    loginWithAccountPasskey: vi.fn(),
    addAccountPasskeyFromSession: vi.fn(),
    rememberDeviceWithPassphrase: vi.fn(),
    restoreGuestFromMnemonic: vi.fn(),
    storeGuestMnemonic: vi.fn(),
    flashWarning: vi.fn(),
    previewOwnerSwitchImpact: vi.fn(),
    shouldOfferPasskeyEnrollment: vi.fn(),
}));

vi.mock("../services/passkeyEnrollOffer", () => ({
    shouldOfferPasskeyEnrollment: mocks.shouldOfferPasskeyEnrollment,
    snoozePasskeyOffer: vi.fn(),
}));

vi.mock("../services/deviceUnlock/provider", () => ({
    loginWithAccountPasskey: mocks.loginWithAccountPasskey,
    addAccountPasskeyFromSession: mocks.addAccountPasskeyFromSession,
    rememberDeviceWithPassphrase: mocks.rememberDeviceWithPassphrase,
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

vi.mock("../services/guestRecovery", () => ({
    storeGuestMnemonic: mocks.storeGuestMnemonic,
    validateGuestMnemonic: vi.fn(() => false),
}));

vi.mock("../store/auth", () => ({
    useAuthStore: () => ({
        restoreGuestFromMnemonic: mocks.restoreGuestFromMnemonic,
    }),
}));

vi.mock("../store/flash", () => ({
    useFlashStore: () => ({
        warning: mocks.flashWarning,
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

async function mountModal() {
    const { default: GuestRestoreModal } = await import("../components/auth/GuestRestoreModal.vue");
    const wrapper = mount(GuestRestoreModal, { props: { open: true } });
    await flushPromises();
    return wrapper;
}

describe("GuestRestoreModal passkey restore", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.loginWithAccountPasskey.mockResolvedValue({ recoveryPhrase: "passkey phrase" });
        mocks.restoreGuestFromMnemonic.mockResolvedValue({ store_id: "store-1" });
        mocks.previewOwnerSwitchImpact.mockResolvedValue({ switches: false });
        mocks.shouldOfferPasskeyEnrollment.mockResolvedValue(false);
    });

    it("restores the session with one passkey gesture and emits success", async () => {
        const wrapper = await mountModal();

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.loginWithAccountPasskey).toHaveBeenCalledTimes(1);
        expect(mocks.restoreGuestFromMnemonic).toHaveBeenCalledWith("passkey phrase");
        expect(mocks.storeGuestMnemonic).toHaveBeenCalledWith("passkey phrase");
        expect(mocks.rememberDeviceWithPassphrase).not.toHaveBeenCalled();
        expect(wrapper.emitted("success")).toEqual([[{ store_id: "store-1" }]]);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("requires the owner-switch confirmation before re-linking local data", async () => {
        mocks.previewOwnerSwitchImpact.mockResolvedValue({
            switches: true,
            companies: 1,
            contacts: 2,
            documents: 3,
        });
        const wrapper = await mountModal();

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.restoreGuestFromMnemonic).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("auth.guest_restore_owner_switch_title");
        expect(passkeyButton(wrapper).text()).toContain("auth.guest_restore_owner_switch_confirm");

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.loginWithAccountPasskey).toHaveBeenCalledTimes(2);
        expect(mocks.previewOwnerSwitchImpact).toHaveBeenCalledTimes(1);
        expect(mocks.restoreGuestFromMnemonic).toHaveBeenCalledWith("passkey phrase");
        expect(wrapper.emitted("success")).toHaveLength(1);
    });

    it("applies the opt-in remember-device envelope on the passkey path", async () => {
        mocks.rememberDeviceWithPassphrase.mockResolvedValue(undefined);
        const wrapper = await mountModal();

        await wrapper.find("input[type=checkbox]").setValue(true);
        await wrapper.find("input[type=password]").setValue("a strong device passphrase");

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.rememberDeviceWithPassphrase).toHaveBeenCalledWith(
            "passkey phrase",
            "a strong device passphrase",
        );
        expect(wrapper.emitted("success")).toHaveLength(1);
    });

    it("rejects a weak device passphrase before any passkey prompt", async () => {
        const wrapper = await mountModal();

        await wrapper.find("input[type=checkbox]").setValue(true);
        await wrapper.find("input[type=password]").setValue("short");

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.loginWithAccountPasskey).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("account.device_passphrase_too_weak");
    });

    it("shows the no-envelope hint when the server has no envelope for the passkey", async () => {
        mocks.loginWithAccountPasskey.mockRejectedValue(new DeviceUnlockError());
        const wrapper = await mountModal();

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.restoreGuestFromMnemonic).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("auth.passkey_no_envelope_hint");
        expect(wrapper.emitted("success")).toBeUndefined();
    });

    it("defers success behind the enrollment offer after a typed-seed restore", async () => {
        mocks.shouldOfferPasskeyEnrollment.mockResolvedValue(true);
        const wrapper = await mountModal();

        await wrapper.find("textarea").setValue("typed phrase words");
        const submit = wrapper.findAll("button").find((candidate) =>
            candidate.text().includes("auth.guest_restore_submit"),
        );
        await submit!.trigger("click");
        await flushPromises();

        expect(mocks.restoreGuestFromMnemonic).toHaveBeenCalledWith("typed phrase words");
        expect(wrapper.emitted("success")).toBeUndefined();
        expect(wrapper.text()).toContain("auth.passkey_offer_title");

        const skip = wrapper.findAll("button").find((candidate) =>
            candidate.text().includes("auth.passkey_offer_skip"),
        );
        await skip!.trigger("click");
        await flushPromises();

        expect(wrapper.emitted("success")).toEqual([[{ store_id: "store-1" }]]);
        expect(wrapper.emitted("close")).toHaveLength(1);
    });

    it("never offers enrollment after a passkey restore", async () => {
        mocks.shouldOfferPasskeyEnrollment.mockResolvedValue(true);
        const wrapper = await mountModal();

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(mocks.shouldOfferPasskeyEnrollment).not.toHaveBeenCalled();
        expect(wrapper.emitted("success")).toHaveLength(1);
    });

    it("stays silent when the user cancels the passkey prompt", async () => {
        const { PasskeyCancelledError } = await import("../services/deviceUnlock/passkeyPrf");
        mocks.loginWithAccountPasskey.mockRejectedValue(new PasskeyCancelledError());
        const wrapper = await mountModal();

        await passkeyButton(wrapper).trigger("click");
        await flushPromises();

        expect(wrapper.find(".text-red-400").exists()).toBe(false);
        expect(wrapper.emitted("success")).toBeUndefined();
    });
});
