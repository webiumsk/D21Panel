import { describe, expect, it } from "vitest";
import { buildIssuedSnapshotContentV1 } from "@/evolu/documentSnapshotCrud";

const document = { type: "invoice", title: "Invoice", number: "RE20260001", currency: "EUR", subtotal: "100", tax_total: "0", discount_percent: "0", total: "100" };
const lines = [{ name: "Item", quantity: "1", unit_price: "100", line_discount_percent: "0", tax_rate: "0", line_total: "100" }];

describe("DE corporate footer fields in the issued snapshot", () => {
    it("freezes register court, number, directors and board chair", () => {
        const result = buildIssuedSnapshotContentV1({
            company: {
                legal_name: "Muster GmbH",
                city: "Berlin",
                register_court: "Amtsgericht Charlottenburg",
                register_number: "HRB 12345",
                managing_directors: "Max Mustermann, Erika Musterfrau",
                supervisory_board_chair: "Dr. A. Vorsitz",
            },
            contact: null,
            document,
            lines,
        });

        expect(result.ok).toBe(true);
        if (result.ok) {
            expect(result.value.company.register_court).toBe("Amtsgericht Charlottenburg");
            expect(result.value.company.register_number).toBe("HRB 12345");
            expect(result.value.company.managing_directors).toBe("Max Mustermann, Erika Musterfrau");
            expect(result.value.company.supervisory_board_chair).toBe("Dr. A. Vorsitz");
        }
    });

    it("older payloads without the fields freeze them as null", () => {
        const result = buildIssuedSnapshotContentV1({
            company: { legal_name: "ACME s.r.o." },
            contact: null,
            document,
            lines,
        });

        expect(result.ok).toBe(true);
        if (result.ok) {
            expect(result.value.company.register_court).toBeNull();
            expect(result.value.company.register_number).toBeNull();
            expect(result.value.company.managing_directors).toBeNull();
            expect(result.value.company.supervisory_board_chair).toBeNull();
        }
    });
});
