<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\UserMessage;
use App\Models\WalletConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Close payee-attestation incidents raised by mistake (e.g. the first
 * reconcile after deploy judged historical payments against today's wallet)
 * and remove the in-app security messages they produced. E-mails that were
 * already sent cannot be recalled.
 */
class ResetPayeeIncidents extends Command
{
    protected $signature = 'wallet-connections:reset-payee-incidents
                            {--since= : Only incidents/messages created at or after this time (e.g. "2026-09-07 04:40")}
                            {--purge-messages : Also delete the merchant/admin security messages of those incidents}
                            {--dry-run : Report what would change without changing anything}';

    protected $description = 'Clear payee mismatch incidents (and optionally their security messages) - for false positives';

    public function handle(): int
    {
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : null;
        $dry = (bool) $this->option('dry-run');

        $rows = WalletConnection::query()
            ->whereNotNull('payee_mismatch_at')
            ->when($since, fn ($q) => $q->where('payee_mismatch_at', '>=', $since))
            ->get();

        foreach ($rows as $connection) {
            $this->line(sprintf('incident store %s since %s node %s%s', $connection->store_id, $connection->payee_mismatch_at, $connection->payee_mismatch_details['pubkey'] ?? '?', $dry ? ' (dry-run)' : ''));
            if ($dry) {
                continue;
            }
            $details = $connection->payee_mismatch_details;
            $connection->forceFill(['payee_mismatch_at' => null, 'payee_mismatch_details' => null])->save();
            AuditLog::log('wallet_connection.payee_incident_reset', 'wallet_connection', $connection->id, [
                'store_id' => $connection->store_id,
                'reset_details' => $details,
            ], null);
        }

        $deleted = 0;
        if ($this->option('purge-messages')) {
            // Only the messages of the incidents reset above. Messages written
            // before the wallet_connection_id column existed carry no id and are
            // matched by the time window instead.
            $connectionIds = $rows->pluck('id')->all();
            $messages = UserMessage::query()
                ->where('type', 'security')
                ->where(function ($q) {
                    $q->where('title', 'like', 'Payment received by an unknown wallet%')
                        ->orWhere('title', 'like', 'Payee mismatch:%');
                })
                ->where(function ($q) use ($connectionIds, $since) {
                    $q->whereIn('wallet_connection_id', $connectionIds ?: ['00000000-0000-0000-0000-000000000000'])
                        ->orWhere(function ($legacy) use ($since) {
                            $legacy->whereNull('wallet_connection_id')
                                ->when($since, fn ($qq) => $qq->where('created_at', '>=', $since));
                        });
                });
            $deleted = $dry ? $messages->count() : $messages->delete();
        }

        $this->info(sprintf('%s %d incident(s), %d security message(s)%s', $dry ? 'Would reset' : 'Reset', $rows->count(), $deleted, $dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
