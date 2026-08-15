import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { generateMnemonic } from "@scure/bip39";
import { wordlist } from "@scure/bip39/wordlists/english.js";
import SeedPhraseInput from "../components/auth/SeedPhraseInput.vue";

function mountInput(modelValue = "") {
    return mount(SeedPhraseInput, { props: { modelValue } });
}

describe("SeedPhraseInput", () => {
    it("tokenizes messy whitespace and casing without flagging valid words", () => {
        const wrapper = mountInput("  Abandon   ability\nABLE ");
        expect(wrapper.text()).toContain("auth.seed_word_count");
        expect(wrapper.text()).not.toContain("auth.seed_word_invalid");
        expect(wrapper.findAll("button")).toHaveLength(0);
    });

    it("flags completed words that are not in the BIP39 wordlist", () => {
        const wrapper = mountInput("abandon recieve able ");
        expect(wrapper.text()).toContain("auth.seed_word_invalid");
    });

    it("does not flag the word still being typed and offers prefix suggestions", () => {
        const wrapper = mountInput("abandon abil");
        expect(wrapper.text()).not.toContain("auth.seed_word_invalid");
        const suggestions = wrapper.findAll("button").map((b) => b.text());
        expect(suggestions).toContain("ability");
        expect(suggestions.length).toBeLessThanOrEqual(5);
    });

    it("completes the current word when a suggestion is clicked", async () => {
        const wrapper = mountInput("abandon abil");
        const suggestion = wrapper
            .findAll("button")
            .find((candidate) => candidate.text() === "ability");
        await suggestion!.trigger("click");
        const emitted = wrapper.emitted("update:modelValue");
        expect(emitted?.at(-1)).toEqual(["abandon ability "]);
    });

    it("offers no suggestions for a complete valid word", () => {
        // "ability" is itself a wordlist word - nothing left to suggest.
        const wrapper = mountInput("abandon ability");
        expect(wrapper.findAll("button")).toHaveLength(0);
    });

    it("reports a checksum failure only for a full phrase of valid words", () => {
        const words = Array.from({ length: 24 }, () => "abandon").join(" ");
        const wrapper = mountInput(`${words} `);
        expect(wrapper.text()).toContain("auth.seed_checksum_invalid");
    });

    it("accepts a genuinely valid 24-word phrase without any warnings", () => {
        const phrase = generateMnemonic(wordlist, 256);
        const wrapper = mountInput(`${phrase} `);
        expect(wrapper.text()).not.toContain("auth.seed_checksum_invalid");
        expect(wrapper.text()).not.toContain("auth.seed_word_invalid");
    });

    it("shows no checksum warning while the phrase is incomplete", () => {
        const wrapper = mountInput("abandon ability able ");
        expect(wrapper.text()).not.toContain("auth.seed_checksum_invalid");
    });
});
