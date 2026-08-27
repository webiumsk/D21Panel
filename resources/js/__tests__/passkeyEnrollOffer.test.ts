import { enableAutoUnmount, flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// The modal teleports to <body> and this project does not auto-unmount
// wrappers; unmount each after its test so nothing lingers between tests.
enableAutoUnmount(afterEach);

const mocks = vi.hoisted(() => ({
    addAccountPasskeyFromSession: vi.fn(),
    upgradeAccountPasskey: vi.fn(),
    listAccountEnvelopes: vi.fn(),
    isPasskeyPrfSupported: vi.fn(),
    flashSuccess: vi.fn(),
}));

vi.mock("../services/deviceUnlock/provider", () => {
    class PasskeyEnvelopeUploadError extends Error {
        readonly credentialIdB64: string;

        constructor(credentialIdB64: string) {
            super("account envelope upload failed");
            this.credentialIdB64 = credentialIdB64;
        }
    }
    return {
        addAccountPasskeyFromSession: mocks.addAccountPasskeyFromSession,
        upgradeAccountPasskey: mocks.upgradeAccountPasskey,
        PasskeyEnvelopeUploadError,
    };
});

vi.mock("../services/deviceUnlock/accountPasskeyEnvelope", () => ({
    listAccountEnvelopes: mocks.listAccountEnvelopes,
}));

vi.mock("../services/deviceUnlock/passkeyPrf", () => ({
    PasskeyCancelledError: class PasskeyCancelledError extends Error {},
    PasskeyPrfUnsupportedError: class PasskeyPrfUnsupportedError extends Error {},
    PasskeyUnsupportedError: class PasskeyUnsupportedError extends Error {},
    isPasskeyPrfSupported: mocks.isPasskeyPrfSupported,
}));

vi.mock("../store/flash", () => ({
    useFlashStore: () => ({
        success: mocks.flashSuccess,
    }),
}));

beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    mocks.isPasskeyPrfSupported.mockResolvedValue(true);
    mocks.listAccountEnvelopes.mockResolvedValue([]);
    mocks.addAccountPasskeyFromSession.mockResolvedValue(undefined);
});

afterEach(() => {
    localStorage.clear();
});

describe("shouldOfferPasskeyEnrollment", () => {
    async function subject() {
        const { shouldOfferPasskeyEnrollment } = await import("../services/passkeyEnrollOffer");
        return shouldOfferPasskeyEnrollment();
    }

    it("offers when supported, not snoozed and no envelope exists", async () => {
        await expect(subject()).resolves.toBe(true);
    });

    it("does not offer when the account already has an envelope", async () => {
        mocks.listAccountEnvelopes.mockResolvedValue([{ credential_id: "abc" }]);
        await expect(subject()).resolves.toBe(false);
    });

    it("does not offer on unsupported platforms", async () => {
        mocks.isPasskeyPrfSupported.mockResolvedValue(false);
        await expect(subject()).resolves.toBe(false);
        expect(mocks.listAccountEnvelopes).not.toHaveBeenCalled();
    });

    it("does not offer while snoozed and offers again after the snooze expires", async () => {
        const { snoozePasskeyOffer, isPasskeyOfferSnoozed, shouldOfferPasskeyEnrollment } =
            await import("../services/passkeyEnrollOffer");
        snoozePasskeyOffer(1_000);
        expect(isPasskeyOfferSnoozed(1_000 + 24 * 60 * 60 * 1000)).toBe(true);
        expect(isPasskeyOfferSnoozed(1_000 + 31 * 24 * 60 * 60 * 1000)).toBe(false);

        vi.useFakeTimers();
        try {
            vi.setSystemTime(1_000 + 24 * 60 * 60 * 1000);
            await expect(shouldOfferPasskeyEnrollment()).resolves.toBe(false);
            vi.setSystemTime(1_000 + 31 * 24 * 60 * 60 * 1000);
            await expect(shouldOfferPasskeyEnrollment()).resolves.toBe(true);
        } finally {
            vi.useRealTimers();
        }
    });

    it("does not offer when the envelope list fails", async () => {
        mocks.listAccountEnvelopes.mockRejectedValue(new Error("network"));
        await expect(subject()).resolves.toBe(false);
    });
});

describe("PasskeyEnrollOfferModal", () => {
    async function mountModal(context: "register" | "restore" = "register") {
        const { default: PasskeyEnrollOfferModal } = await import(
            "../components/auth/PasskeyEnrollOfferModal.vue"
        );
        const wrapper = mount(PasskeyEnrollOfferModal, {
            props: { open: true, context },
            // The modal teleports to <body>; stub teleport so its content stays
            // inside the wrapper for querying.
            global: { stubs: { teleport: true } },
        });
        await flushPromises();
        return wrapper;
    }

    function button(wrapper: Awaited<ReturnType<typeof mountModal>>, key: string) {
        const found = wrapper.findAll("button").find((candidate) => candidate.text().includes(key));
        if (!found) {
            throw new Error(`button ${key} not found`);
        }
        return found;
    }

    it("creates the passkey with the default label and emits done", async () => {
        const wrapper = await mountModal();

        await button(wrapper, "auth.passkey_offer_create").trigger("click");
        await flushPromises();

        expect(mocks.addAccountPasskeyFromSession).toHaveBeenCalledWith(
            "account.passkey_default_label",
        );
        expect(mocks.flashSuccess).toHaveBeenCalled();
        expect(wrapper.emitted("done")).toHaveLength(1);
    });

    it("stays open and silent when the platform prompt is cancelled", async () => {
        const { PasskeyCancelledError } = await import("../services/deviceUnlock/passkeyPrf");
        mocks.addAccountPasskeyFromSession.mockRejectedValue(new PasskeyCancelledError());
        const wrapper = await mountModal();

        await button(wrapper, "auth.passkey_offer_create").trigger("click");
        await flushPromises();

        expect(wrapper.emitted("done")).toBeUndefined();
        expect(wrapper.emitted("skip")).toBeUndefined();
        expect(wrapper.find(".text-red-400").exists()).toBe(false);
    });

    it("shows an inline error on failure and keeps the skip escape", async () => {
        mocks.addAccountPasskeyFromSession.mockRejectedValue(new Error("network"));
        const wrapper = await mountModal();

        await button(wrapper, "auth.passkey_offer_create").trigger("click");
        await flushPromises();

        expect(wrapper.text()).toContain("auth.passkey_offer_error");
        expect(wrapper.emitted("done")).toBeUndefined();

        await button(wrapper, "auth.passkey_offer_skip").trigger("click");
        expect(wrapper.emitted("skip")).toHaveLength(1);
    });

    it("retries a failed envelope upload against the same credential", async () => {
        const { PasskeyEnvelopeUploadError } = await import("../services/deviceUnlock/provider");
        mocks.addAccountPasskeyFromSession.mockRejectedValue(
            new PasskeyEnvelopeUploadError("cred-1"),
        );
        mocks.upgradeAccountPasskey.mockResolvedValue(undefined);
        const wrapper = await mountModal();

        await button(wrapper, "auth.passkey_offer_create").trigger("click");
        await flushPromises();
        expect(wrapper.text()).toContain("auth.passkey_offer_error");

        await button(wrapper, "auth.passkey_offer_create").trigger("click");
        await flushPromises();

        expect(mocks.addAccountPasskeyFromSession).toHaveBeenCalledTimes(1);
        expect(mocks.upgradeAccountPasskey).toHaveBeenCalledWith(
            "cred-1",
            "account.passkey_default_label",
        );
        expect(wrapper.emitted("done")).toHaveLength(1);
    });

    it("blocks further create attempts when the authenticator lacks PRF", async () => {
        const { PasskeyPrfUnsupportedError } = await import("../services/deviceUnlock/passkeyPrf");
        mocks.addAccountPasskeyFromSession.mockRejectedValue(new PasskeyPrfUnsupportedError());
        const wrapper = await mountModal();

        const create = button(wrapper, "auth.passkey_offer_create");
        await create.trigger("click");
        await flushPromises();

        expect(wrapper.text()).toContain("auth.passkey_browser_no_prf");
        // Re-query: under the teleport stub the button node is re-created on the
        // prfBlocked re-render, so the pre-click reference goes stale.
        expect(button(wrapper, "auth.passkey_offer_create").attributes("disabled")).toBeDefined();
    });

    it("snoozes only when skipped in the restore context", async () => {
        const { isPasskeyOfferSnoozed } = await import("../services/passkeyEnrollOffer");

        const registerWrapper = await mountModal("register");
        await button(registerWrapper, "auth.passkey_offer_skip").trigger("click");
        expect(isPasskeyOfferSnoozed()).toBe(false);

        const restoreWrapper = await mountModal("restore");
        await button(restoreWrapper, "auth.passkey_offer_skip").trigger("click");
        expect(isPasskeyOfferSnoozed()).toBe(true);
    });
});
