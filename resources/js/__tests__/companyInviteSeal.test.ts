import { describe, expect, it } from "vitest";
import { ed25519 } from "@noble/curves/ed25519.js";
import { inviteFingerprint, openCompanyInviteSecret, sealCompanyInviteSecret } from "@/services/companyInviteSeal";

function recipient() {
    const seed = ed25519.utils.randomSecretKey();
    const pubHex = Array.from(ed25519.getPublicKey(seed), (b) => b.toString(16).padStart(2, "0")).join("");
    return { seed, pubHex };
}

describe("companyInviteSeal", () => {
    it("round-trips a secret to the recipient's Ed25519 key", async () => {
        const { seed, pubHex } = recipient();
        const secret = "cs.AbCdEf0123456789xyzSharedOwnerSecret==";
        const sealed = await sealCompanyInviteSecret(pubHex, secret);
        expect(sealed?.v).toBe(1);
        const opened = await openCompanyInviteSecret(seed, sealed!);
        expect(opened).toBe(secret);
    });

    it("produces a fresh ephemeral key per seal (no deterministic reuse)", async () => {
        const { pubHex } = recipient();
        const a = await sealCompanyInviteSecret(pubHex, "same-secret");
        const b = await sealCompanyInviteSecret(pubHex, "same-secret");
        expect(a!.epkB64).not.toBe(b!.epkB64);
        expect(a!.ctB64).not.toBe(b!.ctB64);
    });

    it("cannot be opened with a different recipient key", async () => {
        const { pubHex } = recipient();
        const other = recipient();
        const sealed = await sealCompanyInviteSecret(pubHex, "top-secret");
        expect(await openCompanyInviteSecret(other.seed, sealed!)).toBeNull();
    });

    it("fails to open a tampered ciphertext", async () => {
        const { seed, pubHex } = recipient();
        const sealed = await sealCompanyInviteSecret(pubHex, "top-secret");
        const flipped = sealed!.ctB64[0] === "A" ? "B" : "A";
        const tampered = { ...sealed!, ctB64: flipped + sealed!.ctB64.slice(1) };
        expect(await openCompanyInviteSecret(seed, tampered)).toBeNull();
    });

    it("rejects an invalid recipient public key", async () => {
        expect(await sealCompanyInviteSecret("nothex", "x")).toBeNull();
        expect(await sealCompanyInviteSecret("ab", "x")).toBeNull();
        expect(inviteFingerprint("nothex")).toBeNull();
    });

    it("fingerprint is stable, grouped, and key-specific", async () => {
        const { pubHex } = recipient();
        const other = recipient();
        const fp = inviteFingerprint(pubHex)!;
        expect(fp).toBe(inviteFingerprint(pubHex));
        expect(fp).not.toBe(inviteFingerprint(other.pubHex));
        expect(fp).toMatch(/^([A-Z2-7]{4}-){5}[A-Z2-7]{4}$/);
    });
});
