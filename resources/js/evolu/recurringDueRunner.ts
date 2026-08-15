import type { Evolu } from "@evolu/common/local-first";
import {
    allCompaniesDetailQuery,
    allContactsQuery,
    allDocumentLinesQuery,
    allDocumentsQuery,
    allNumberSeriesQuery,
    allRecurringProfileLinesQuery,
    allRecurringProfilesQuery,
} from "./client";
import type { EvoluCompanyRow } from "./companyMap";
import type { EvoluContactRow } from "./contactMap";
import { taxOptionsForProfile } from "./recurringVatPolicy";
import type { EvoluDocumentLineRow, EvoluDocumentRow } from "./documentMap";
import type { EvoluNumberSeriesRow } from "./numberSeriesMap";
import { generateLocalRecurringDocument } from "./recurringCrud";
import type { EvoluRecurringProfileLineRow, EvoluRecurringProfileRow } from "./recurringMap";
import { listDueRecurringProfiles } from "./recurringNextDate";
import type { InvoicingLocalSchema, RecurringProfileId } from "./schema";
import { toAppRows } from "./queryLoad";

export type RecurringDueGeneration = {
    profileId: string;
    companyId: string;
    documentId: string;
    number: string;
    title: string | null;
};

export type RecurringDueRunResult = {
    generated: RecurringDueGeneration[];
    errors: number;
};

const MAX_CATCHUP_ROUNDS = 48;

type RunnerSnapshot = {
    companies: EvoluCompanyRow[];
    contacts: EvoluContactRow[];
    profiles: EvoluRecurringProfileRow[];
    profileLines: EvoluRecurringProfileLineRow[];
    documents: EvoluDocumentRow[];
    documentLines: EvoluDocumentLineRow[];
    series: EvoluNumberSeriesRow[];
};

async function loadRunnerSnapshot(evolu: Evolu<InvoicingLocalSchema>): Promise<RunnerSnapshot> {
    const [companies, contacts, profiles, profileLines, documents, documentLines, series] = await Promise.all([
        evolu.loadQuery(allCompaniesDetailQuery),
        evolu.loadQuery(allContactsQuery),
        evolu.loadQuery(allRecurringProfilesQuery),
        evolu.loadQuery(allRecurringProfileLinesQuery),
        evolu.loadQuery(allDocumentsQuery),
        evolu.loadQuery(allDocumentLinesQuery),
        evolu.loadQuery(allNumberSeriesQuery),
    ]);

    return {
        companies: toAppRows<EvoluCompanyRow>(companies),
        contacts: toAppRows<EvoluContactRow>(contacts),
        profiles: toAppRows<EvoluRecurringProfileRow>(profiles),
        profileLines: toAppRows<EvoluRecurringProfileLineRow>(profileLines),
        documents: toAppRows<EvoluDocumentRow>(documents),
        documentLines: toAppRows<EvoluDocumentLineRow>(documentLines),
        series: toAppRows<EvoluNumberSeriesRow>(series),
    };
}

let runnerInFlight: Promise<RecurringDueRunResult> | null = null;

export async function processDueLocalRecurringProfiles(
    evolu: Evolu<InvoicingLocalSchema>,
    today = new Date().toISOString().slice(0, 10),
): Promise<RecurringDueRunResult> {
    if (runnerInFlight) {
        return runnerInFlight;
    }

    runnerInFlight = (async () => {
        const generated: RecurringDueGeneration[] = [];
        let errors = 0;

        for (let round = 0; round < MAX_CATCHUP_ROUNDS; round += 1) {
            const snapshot = await loadRunnerSnapshot(evolu);
            const dueProfiles = listDueRecurringProfiles(snapshot.profiles, today);
            if (dueProfiles.length === 0) {
                break;
            }

            let issuedThisRound = 0;
            for (const profile of dueProfiles) {
                // Resolve the profile, company and contact from the same
                // fresh snapshot the generation uses - earlier iterations of
                // this loop (or a concurrent edit) may have changed them
                // since the round's due-list snapshot.
                const fresh = await loadRunnerSnapshot(evolu);
                const freshProfile = fresh.profiles.find((row) => row.id === profile.id) ?? profile;
                const company = fresh.companies.find((row) => row.id === freshProfile.companyId);
                if (!company) {
                    errors += 1;
                    continue;
                }

                const contact = freshProfile.contactId
                    ? fresh.contacts.find((row) => row.id === freshProfile.contactId) ?? null
                    : null;

                const result = await generateLocalRecurringDocument(
                    evolu,
                    profile.id as RecurringProfileId,
                    company,
                    fresh.profiles,
                    fresh.profileLines,
                    fresh.documents,
                    fresh.documentLines,
                    fresh.series,
                    taxOptionsForProfile(company, contact),
                );

                if (!result.ok) {
                    errors += 1;
                    continue;
                }

                generated.push({
                    profileId: profile.id,
                    companyId: freshProfile.companyId,
                    documentId: result.value.documentId,
                    number: result.value.number,
                    title: freshProfile.title,
                });
                issuedThisRound += 1;
            }

            if (issuedThisRound === 0) {
                break;
            }
        }

        return { generated, errors };
    })();

    try {
        return await runnerInFlight;
    } finally {
        runnerInFlight = null;
    }
}
