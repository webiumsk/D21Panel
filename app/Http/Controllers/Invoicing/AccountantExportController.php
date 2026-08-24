<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\BusinessDocumentStatus;
use App\Enums\BusinessExpenseStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\AccountantExportRequest;
use App\Http\Requests\Invoicing\EphemeralAccountantExportRequest;
use App\Models\AuditLog;
use App\Models\BusinessDocument;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseAttachment;
use App\Models\Company;
use App\Models\User;
use App\Services\Invoicing\Accounting\AccountantPackageBuilder;
use App\Services\Invoicing\Accounting\AccountantPackageOptions;
use App\Services\Invoicing\EphemeralDocumentFactory;
use App\Support\Invoicing\Accounting\ReceivedExpenseAttachment;
use App\Support\Invoicing\Accounting\ReceivedExpenseItem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Balík pre účtovníka" download - server mode reads the company's documents
 * and expenses from the database, the ephemeral variants take the same data
 * as a transient payload from the local-first client. Neither persists
 * anything; only an audit row with counts is written.
 */
class AccountantExportController extends Controller
{
    public function __construct(
        protected AccountantPackageBuilder $builder,
        protected EphemeralDocumentFactory $factory,
    ) {}

    public function download(AccountantExportRequest $request, Company $company): Response
    {
        $options = $request->options();

        $documents = BusinessDocument::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', BusinessDocumentStatus::Draft->value)
            ->whereNotNull('number')
            ->whereBetween('issue_date', [$options->from?->format('Y-m-d'), $options->to?->format('Y-m-d')])
            ->orderBy('issue_date')
            ->orderBy('number')
            ->with(['contact', 'lines'])
            ->limit(EphemeralAccountantExportRequest::maxRows() + 1)
            ->get();

        $expenseRows = BusinessExpense::query()
            ->where('company_id', $company->id)
            ->where('status', '!=', BusinessExpenseStatus::Cancelled->value)
            ->whereBetween('issue_date', [$options->from?->format('Y-m-d'), $options->to?->format('Y-m-d')])
            ->orderBy('issue_date')
            ->orderBy('internal_number')
            ->with('attachments')
            ->limit(EphemeralAccountantExportRequest::maxRows() + 1)
            ->get();

        $maxRows = EphemeralAccountantExportRequest::maxRows();
        if ($documents->count() > $maxRows || $expenseRows->count() > $maxRows) {
            abort(413, 'Too many documents for one package - narrow the period.');
        }

        $expenses = $expenseRows
            ->map(fn (BusinessExpense $expense) => ReceivedExpenseItem::fromModel(
                $expense,
                $options->includeExpenseAttachments ? $this->loadAttachments($expense) : [],
            ))
            ->all();

        $binary = $this->buildOrFail($company, $documents->all(), $expenses, $options);

        AuditLog::log('company.accountant_export', 'company', $company->id, [
            'from' => $options->from?->format('Y-m-d'),
            'to' => $options->to?->format('Y-m-d'),
            'documents' => $documents->count(),
            'expenses' => count($expenses),
            'formats' => $options->formats,
        ]);

        return $this->zipResponse($binary, $company, $options);
    }

    public function ephemeral(EphemeralAccountantExportRequest $request, Company $company): Response
    {
        $snapshotCompany = $this->factory->snapshotCompany($company, (array) ($request->validated()['company'] ?? []));

        return $this->respondEphemeral($request, $snapshotCompany, $company);
    }

    public function ephemeralWithoutCompany(EphemeralAccountantExportRequest $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $snapshotCompany = $this->factory->resolveCompany($user, (array) ($request->validated()['company'] ?? []));

        return $this->respondEphemeral($request, $snapshotCompany);
    }

    protected function respondEphemeral(
        EphemeralAccountantExportRequest $request,
        Company $snapshotCompany,
        ?Company $auditCompany = null,
    ): Response {
        $validated = $request->validated();
        $options = $request->options();

        $expensePayloads = array_values((array) ($validated['expenses'] ?? []));
        $this->assertAttachmentBudget($expensePayloads);

        $documents = $this->factory->documentsFromBulk($snapshotCompany, $validated)
            ->filter(fn (BusinessDocument $document) => $document->status !== BusinessDocumentStatus::Draft)
            ->values();
        $expenses = array_map(
            static fn (array $payload) => ReceivedExpenseItem::fromArray(
                $options->includeExpenseAttachments ? $payload : array_merge($payload, ['attachments' => []]),
            ),
            $expensePayloads,
        );

        $binary = $this->buildOrFail($snapshotCompany, $documents->all(), $expenses, $options);

        $companyId = $auditCompany->id ?? $snapshotCompany->id;
        [$auditType, $auditId] = Str::isUuid($companyId) ? ['company', $companyId] : [null, null];
        AuditLog::log('business_document.ephemeral_accountant_export', $auditType, $auditId, [
            'from' => $options->from?->format('Y-m-d'),
            'to' => $options->to?->format('Y-m-d'),
            'documents' => $documents->count(),
            'expenses' => count($expenses),
            'formats' => $options->formats,
            'company_less' => $auditCompany === null,
        ], $request->user()?->id);

        return $this->zipResponse($binary, $snapshotCompany, $options);
    }

    /**
     * @param  list<BusinessDocument>  $documents
     * @param  list<ReceivedExpenseItem>  $expenses
     */
    protected function buildOrFail(Company $company, array $documents, array $expenses, AccountantPackageOptions $options): string
    {
        try {
            return $this->builder->build($company, $documents, $expenses, $options);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['period' => [$exception->getMessage()]]);
        }
    }

    /**
     * @return list<ReceivedExpenseAttachment>
     */
    protected function loadAttachments(BusinessExpense $expense): array
    {
        $items = [];
        foreach ($expense->attachments as $attachment) {
            /** @var BusinessExpenseAttachment $attachment */
            $disk = Storage::disk($attachment->disk);
            if (! $disk->exists($attachment->path)) {
                continue; // stale row - the package must not fail on one missing file
            }
            $items[] = new ReceivedExpenseAttachment(
                filename: (string) ($attachment->original_filename ?: basename($attachment->path)),
                mime: (string) ($attachment->mime ?: 'application/octet-stream'),
                bytes: (string) $disk->get($attachment->path),
            );
        }

        return $items;
    }

    /**
     * Base64 inflates by ~4/3: reject early when the decoded attachments would
     * exceed the per-request budget instead of assembling a huge ZIP.
     *
     * @param  list<array<string, mixed>>  $expensePayloads
     */
    protected function assertAttachmentBudget(array $expensePayloads): void
    {
        $encoded = 0;
        foreach ($expensePayloads as $payload) {
            foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
                $encoded += strlen((string) (is_array($attachment) ? ($attachment['content_base64'] ?? '') : ''));
            }
        }

        if ((int) floor($encoded * 3 / 4) > EphemeralAccountantExportRequest::maxTotalAttachmentBytes()) {
            abort(413, 'Attachments exceed the package size limit - narrow the period.');
        }
    }

    protected function zipResponse(string $binary, Company $company, AccountantPackageOptions $options): Response
    {
        $slug = Str::slug((string) ($company->trade_name ?: $company->legal_name ?: 'company')) ?: 'company';
        $period = ($options->from?->format('Y-m-d') ?? 'all').'_'.($options->to?->format('Y-m-d') ?? 'all');

        return response($binary, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => "attachment; filename=\"accountant-{$slug}-{$period}.zip\"",
            'Content-Length' => (string) strlen($binary),
        ]);
    }
}
