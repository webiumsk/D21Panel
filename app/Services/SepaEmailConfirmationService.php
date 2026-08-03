<?php

namespace App\Services;

use App\Enums\BankTransactionDirection;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\SepaService;
use App\Support\Invoicing\ParsedBankTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Confirms SEPA payment requests from bank notification emails (b-mail):
 * a store-scoped inbound address receives the bank's credit notification,
 * the shared bank parsers extract amount/currency/symbols, and the payment
 * is reported to the BTCPay plugin's amount-verified endpoint - mismatches
 * land in the plugin's manual review, never auto-settle.
 */
class SepaEmailConfirmationService
{
    public function __construct(protected SepaService $sepaService) {}

    /**
     * @param  list<ParsedBankTransaction>  $rows
     * @return array{store_id: string, reported: int, outcome: ?string}
     */
    public function handle(Store $store, array $payload, array $rows): array
    {
        $credits = array_values(array_filter(
            $rows,
            fn (ParsedBankTransaction $row) => $row->direction === BankTransactionDirection::Credit
        ));

        if ($credits === []) {
            Log::info('SEPA b-mail: no credit transaction parsed', [
                'store_id' => $store->id,
                'from' => $payload['from'] ?? '',
                'subject' => $payload['subject'] ?? '',
            ]);

            return ['store_id' => $store->id, 'reported' => 0, 'outcome' => null];
        }

        /** @var User $owner */
        $owner = $store->user;
        $apiKey = $owner->getBtcPayApiKeyOrFail();
        $dedupKey = substr(hash('sha256', ($payload['to'] ?? '').'|'.($payload['body'] ?? '')), 0, 40);

        $reported = 0;
        $finalOutcome = null;
        foreach ($credits as $row) {
            $outcome = $this->reportRow($store, $row, (string) ($payload['body'] ?? ''), $dedupKey, $apiKey);
            if ($outcome !== null) {
                $reported++;
                $finalOutcome = $outcome;
            }
        }

        AuditLog::log('sepa.bmail_processed', 'store', $store->id, [
            'reported' => $reported,
            'outcome' => $finalOutcome,
        ], $owner->id);

        return ['store_id' => $store->id, 'reported' => $reported, 'outcome' => $finalOutcome];
    }

    /**
     * Tries the reference candidates in confidence order until the plugin
     * recognizes one (outcome other than "unknown").
     */
    protected function reportRow(Store $store, ParsedBankTransaction $row, string $body, string $dedupKey, string $apiKey): ?string
    {
        $outcome = null;
        foreach ($this->referenceCandidates($row, $body) as $reference) {
            try {
                $result = $this->sepaService->reportPayment($store->btcpay_store_id, [
                    'reference' => $reference,
                    'amount' => abs($row->amount),
                    'currency' => strtoupper($row->currency),
                    'dedupKey' => $dedupKey,
                ], $apiKey);
            } catch (BtcPayException $e) {
                Log::warning('SEPA b-mail: report failed', [
                    'store_id' => $store->id,
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $outcome = $result['outcome'] ?? null;
            if ($outcome !== null && $outcome !== 'unknown') {
                return $outcome;
            }
        }

        return $outcome;
    }

    /**
     * @return list<string>
     */
    protected function referenceCandidates(ParsedBankTransaction $row, string $body): array
    {
        $candidates = [];

        // The plugin reference travels as the SEPA end-to-end id and lands
        // in the notification body (Tatra: "Referencia platitela").
        if (preg_match_all('/QR-[0-9a-fA-F]{32}/', $body, $matches)) {
            foreach ($matches[0] as $match) {
                $candidates[] = $match;
            }
        }

        // CZ profile / bysquare flows correlate by the variable symbol.
        if ($row->variableSymbol !== null && $row->variableSymbol !== '') {
            $candidates[] = $row->variableSymbol;
        }

        return array_values(array_unique($candidates));
    }
}
