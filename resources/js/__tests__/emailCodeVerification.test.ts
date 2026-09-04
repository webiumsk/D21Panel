import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import EmailCodeVerification from "../components/account/EmailCodeVerification.vue";
import type { EmailChallengeSummary } from "../store/auth";

const i18n = createI18n({
    legacy: false,
    locale: "en",
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            common: { loading: "Loading" },
            account: {
                email_code_sent_to: "Code sent to",
                email_code_instructions: "Type the code",
                email_code_input_aria: "code",
                email_code_confirm: "Confirm code",
                email_code_resend: "Send a new code",
                email_code_change_email: "Use a different email address",
                email_code_resent: "A new code was sent.",
                email_code_confirm_failed: "Could not verify",
                email_code_resend_failed: "Could not resend",
                pending_email_resend_wait: "Resend in {seconds}s",
            },
        },
    },
});

function challenge(overrides: Partial<EmailChallengeSummary> = {}): EmailChallengeSummary {
    return {
        purpose: "guest_upgrade",
        email: "merchant@example.com",
        expires_at: new Date(Date.now() + 600_000).toISOString(),
        resend_available_at: new Date(Date.now() + 30_000).toISOString(),
        attempts_left: 5,
        sends_left: 4,
        ...overrides,
    };
}

function mountComponent(opts: {
    confirm?: (code: string) => Promise<void>;
    resend?: () => Promise<EmailChallengeSummary | null>;
    challenge?: EmailChallengeSummary;
} = {}) {
    return mount(EmailCodeVerification, {
        props: {
            challenge: opts.challenge ?? challenge(),
            confirm: opts.confirm ?? vi.fn().mockResolvedValue(undefined),
            resend: opts.resend ?? vi.fn().mockResolvedValue(challenge()),
            autofocus: false,
        },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

async function typeCode(wrapper: ReturnType<typeof mountComponent>, code: string) {
    const inputs = wrapper.findAll("input");
    for (let i = 0; i < code.length; i++) {
        await inputs[i].setValue(code[i]);
    }
    await flushPromises();
}

describe("EmailCodeVerification", () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = "";
    });

    it("renders the target address and auto-submits once six digits are typed", async () => {
        const confirm = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountComponent({ confirm });

        expect(wrapper.text()).toContain("merchant@example.com");
        await typeCode(wrapper, "123456");

        expect(confirm).toHaveBeenCalledTimes(1);
        expect(confirm).toHaveBeenCalledWith("123456");
    });

    it("fills all boxes from a pasted code (with noise stripped)", async () => {
        const confirm = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountComponent({ confirm });
        const first = wrapper.findAll("input")[0];

        await first.trigger("paste", {
            clipboardData: { getData: () => "code: 98 76-54" },
        });
        await flushPromises();

        expect(confirm).toHaveBeenCalledWith("987654");
    });

    it("shows the server message and attempts on a mismatch, locks on 423", async () => {
        const confirm = vi
            .fn()
            .mockRejectedValueOnce({
                response: { status: 422, data: { message: "Wrong code. 4 attempt(s) left.", code: "code_mismatch", attempts_left: 4 } },
            })
            .mockRejectedValueOnce({
                response: { status: 423, data: { message: "Too many wrong attempts.", code: "challenge_locked" } },
            });
        const wrapper = mountComponent({ confirm });

        await typeCode(wrapper, "111111");
        expect(wrapper.find('[role="alert"]').text()).toContain("4 attempt(s) left");

        await typeCode(wrapper, "222222");
        expect(wrapper.find('[role="alert"]').text()).toContain("Too many wrong attempts");
        expect(wrapper.emitted("locked")).toHaveLength(1);
        expect(wrapper.findAll("input")[0].attributes("disabled")).toBeDefined();
    });

    it("drives the resend countdown from the server timestamp and resends afterwards", async () => {
        const resend = vi.fn().mockImplementation(async () =>
            challenge({ resend_available_at: new Date(Date.now() + 90_000).toISOString(), sends_left: 3 }),
        );
        const wrapper = mountComponent({ resend });
        await flushPromises();
        const resendButton = wrapper.findAll("button").find((b) => b.text().includes("Resend in"))!;

        expect(resendButton.text()).toMatch(/Resend in (29|30)s/);
        expect(resendButton.attributes("disabled")).toBeDefined();

        await vi.advanceTimersByTimeAsync(31_000);
        await flushPromises();
        const enabled = wrapper.findAll("button").find((b) => b.text() === "Send a new code")!;
        expect(enabled.attributes("disabled")).toBeUndefined();

        await enabled.trigger("click");
        await flushPromises();

        expect(resend).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[role="status"]').text()).toContain("A new code was sent.");
        expect(wrapper.text()).toMatch(/Resend in (89|90)s/);
    });

    it("maps a 429 cooldown response into the countdown", async () => {
        const resend = vi.fn().mockRejectedValue({
            response: { status: 429, data: { message: "Please wait", code: "resend_cooldown", retry_after: 42 } },
        });
        const wrapper = mountComponent({
            resend,
            challenge: challenge({ resend_available_at: new Date(Date.now() - 1000).toISOString() }),
        });

        const button = wrapper.findAll("button").find((b) => b.text() === "Send a new code")!;
        await button.trigger("click");
        await flushPromises();

        expect(wrapper.text()).toMatch(/Resend in (41|42)s/);
    });

    it("emits change-email from the secondary action", async () => {
        const wrapper = mountComponent();
        await wrapper.findAll("button").find((b) => b.text().includes("different email"))!.trigger("click");
        expect(wrapper.emitted("change-email")).toHaveLength(1);
    });
});
