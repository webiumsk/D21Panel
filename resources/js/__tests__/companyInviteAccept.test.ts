import { afterEach, describe, expect, it } from "vitest";
import { deriveRecoveryPublicKeyHex, generateAccountMnemonic24, storeAccountMnemonic, clearSessionAccountMnemonic } from "@/services/accountSeed";
import { sealCompanyInviteSecret } from "@/services/companyInviteSeal";
import { decryptSealedInvite } from "@/evolu/companyInviteAccept";

afterEach(() => clearSessionAccountMnemonic());

describe("decryptSealedInvite", () => {
    it("opens an invite sealed to this account's recovery key", async () => {
        const mnemonic = generateAccountMnemonic24();
        const pub = deriveRecoveryPublicKeyHex(mnemonic);
        const secret = "cs.SharedOwnerSecretEncodedString1234==";
        const sealed = await sealCompanyInviteSecret(pub, secret);
        storeAccountMnemonic(mnemonic);
        expect(await decryptSealedInvite(sealed!)).toBe(secret);
    });

    it("returns null when the invite was sealed to a different account", async () => {
        const other = deriveRecoveryPublicKeyHex(generateAccountMnemonic24());
        const sealed = await sealCompanyInviteSecret(other, "secret");
        storeAccountMnemonic(generateAccountMnemonic24());
        expect(await decryptSealedInvite(sealed!)).toBeNull();
    });

    it("returns null when no recovery phrase is present this session", async () => {
        const sealed = await sealCompanyInviteSecret(deriveRecoveryPublicKeyHex(generateAccountMnemonic24()), "x");
        expect(await decryptSealedInvite(sealed!)).toBeNull();
    });
});
