<?php

namespace App\Services\Invoicing;

use App\Enums\BankImportSource;
use App\Models\Company;
use App\Models\Store;
use App\Services\Invoicing\BankImport\BankNotificationParser;
use App\Services\Invoicing\BankImport\SlspBankEmailParser;
use App\Services\Invoicing\BankImport\TatraBankEmailParser;
use App\Services\SepaEmailConfirmationService;
use App\Support\Invoicing\ParsedBankTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BankInboundEmailService
{
    /** @var list<BankNotificationParser> */
    protected array $parsers;

    public function __construct(
        protected BankStatementImportService $importService,
        protected BusinessDocumentPaymentMatcher $matcher,
        protected BankInboundAddressService $addressService,
        protected SepaEmailConfirmationService $sepaConfirmation,
        ?array $parsers = null,
    ) {
        $this->parsers = $parsers ?? [
            new TatraBankEmailParser,
            new SlspBankEmailParser,
        ];
    }

    /**
     * @param  array{to: string, from: string, subject: string, body: string, headers?: string}  $payload
     * @return array{company_id: string, imported: int, auto_matched: int}|array{store_id: string, reported: int, outcome: string|null}
     */
    public function handle(array $payload): array
    {
        if (! config('bank_inbound.enabled', false)) {
            throw ValidationException::withMessages([
                'inbound' => ['Bank inbound email is disabled.'],
            ]);
        }

        if (config('bank_inbound.reject_forwarded', true) && $this->looksForwarded($payload['headers'] ?? '')) {
            throw ValidationException::withMessages([
                'email' => ['Forwarded bank notifications are not accepted. Configure the bank to send directly.'],
            ]);
        }

        $owner = $this->addressService->resolveOwner($payload['to']);
        $rows = $this->parseBody($payload['from'], $payload['subject'], $payload['body']);

        // Store-scoped address: SEPA payment confirmation channel - the
        // parsed credit is reported to the BTCPay plugin instead of the
        // invoicing bank-transaction pipeline.
        if ($owner instanceof Store) {
            return $this->sepaConfirmation->handle($owner, $payload, $rows);
        }

        $company = $owner;

        if ($rows === []) {
            Log::warning('Bank inbound: no transactions parsed', [
                'company_id' => $company->id,
                'from' => $payload['from'],
                'subject' => $payload['subject'],
            ]);

            return ['company_id' => $company->id, 'imported' => 0, 'auto_matched' => 0];
        }

        $result = $this->importService->persistRows(
            $company,
            null,
            $rows,
            BankImportSource::Email,
            'inbound-email',
            null,
        );

        return [
            'company_id' => $company->id,
            'imported' => $result['imported'],
            'auto_matched' => $result['auto_matched'],
        ];
    }

    protected function resolveCompany(string $to): Company
    {
        return $this->addressService->resolveCompany($to);
    }

    /**
     * @return list<ParsedBankTransaction>
     */
    protected function parseBody(string $from, string $subject, string $body): array
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($from, $subject, $body)) {
                return $parser->parse($from, $subject, $body);
            }
        }

        return [];
    }

    protected function looksForwarded(string $headers): bool
    {
        $h = strtolower($headers);

        return str_contains($h, 'x-forwarded-for')
            || str_contains($h, 'resent-from')
            || preg_match('/^fwd:/im', $headers) === 1;
    }
}
