import { describe, expect, it, vi, beforeEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { ref } from "vue";

const apiMock = vi.hoisted(() => ({
    get: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
}));

vi.mock("../services/api", () => ({ default: apiMock }));
vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key: string) => key, locale: { value: "en" } }),
}));
vi.mock("../composables/useStorePageShell", () => ({
    useStorePageShell: () => ({
        storeId: ref("store-1"),
        store: ref({ id: "store-1", name: "Test store" }),
        error: ref(""),
        loadStore: vi.fn(),
        goSettings: vi.fn(),
        goSection: vi.fn(),
    }),
}));
vi.mock("../store/apps", () => ({ useAppsStore: () => ({ apps: [] }) }));
vi.mock("../store/flash", () => ({
    useFlashStore: () => ({ success: vi.fn(), error: vi.fn() }),
}));

const settings = {
    configured: true,
    enabled: true,
    countryProfile: "SK",
    iban: "SK6807200002891987426353",
    beneficiary: "My Company s.r.o.",
    bic: null,
    message: null,
    confirmationBackend: "manual",
    skQrVariant: "bysquare",
    amountTolerance: 0,
    nopEnvironment: "INT",
    nopCertSet: true,
    fioTokenSet: false,
    checkoutConfirmEnabled: true,
    nopVatsk: "VATSK-1234567890",
    nopPokladnica: "88812345678900001",
};

function primeApi({ available = true } = {}) {
    apiMock.get.mockImplementation((url: string) => {
        if (url.includes("/sepa/status")) {
            return Promise.resolve({ data: { data: { available } } });
        }
        if (url.includes("/sepa/settings")) {
            return Promise.resolve({ data: { data: settings } });
        }
        if (url.includes("/sepa/payment-requests")) {
            return Promise.resolve({
                data: {
                    data: [
                        {
                            reference: "QR-ab29e346f1d841c8a95a63d857490818",
                            invoiceId: "inv-1",
                            state: "PENDING",
                            amountDue: 12.5,
                            currency: "EUR",
                            createdAt: "2026-07-31T10:00:00+00:00",
                            reviewReason: null,
                        },
                    ],
                },
            });
        }
        return Promise.reject(new Error(`unexpected GET ${url}`));
    });
}

async function mountPage() {
    const { default: Sepa } = await import("../pages/stores/Sepa.vue");
    const wrapper = mount(Sepa, {
        global: {
            stubs: {
                RafflesPageLayout: { template: "<div><slot /></div>" },
            },
        },
    });
    await flushPromises();
    return wrapper;
}

describe("Sepa store page", () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it("loads settings and maps them into the form", async () => {
        primeApi();
        const wrapper = await mountPage();

        const iban = wrapper.find<HTMLInputElement>("#sepa-iban");
        expect(iban.element.value).toBe("SK6807200002891987426353");
        const variant = wrapper.find<HTMLSelectElement>("#sepa-variant");
        expect(variant.element.value).toBe("bysquare");
        expect(wrapper.text()).toContain("VATSK-1234567890");
        const checkoutToggle = wrapper.find<HTMLInputElement>('input[type="checkbox"]');
        expect(wrapper.text()).toContain("sepa.checkout_confirm_label");
        expect(wrapper.text()).toContain("sepa.fio_title");
        expect(checkoutToggle.exists()).toBe(true);
        expect(wrapper.text()).toContain("POKLADNICA-88812345678900001");
    });

    it("renders pending payment requests with a confirm action", async () => {
        primeApi();
        const wrapper = await mountPage();

        expect(wrapper.text()).toContain("QR-ab29e346f1d841c8a95a63d857490818");
        expect(wrapper.text()).toContain("sepa.mark_paid");
    });

    it("shows the plugin-unavailable notice when the probe fails", async () => {
        primeApi({ available: false });
        const wrapper = await mountPage();

        expect(wrapper.text()).toContain("sepa.plugin_unavailable");
        expect(apiMock.get).toHaveBeenCalledTimes(1);
    });
});
