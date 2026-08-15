<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Invoicing\Efaktura\EfakturaInboundService;
use Illuminate\Console\Command;

class PollEfakturaInboundCommand extends Command
{
    protected $signature = 'efaktura:poll-inbound {--company= : Limit polling to one company UUID}';

    protected $description = 'Poll SAPI-SK for inbound Peppol documents and import them as business expenses';

    public function handle(EfakturaInboundService $inboundService): int
    {
        if (! config('efaktura.enabled')) {
            $this->warn('EFAKTURA_ENABLED is false; skipping.');

            return self::SUCCESS;
        }

        $companyId = $this->option('company');
        if (is_string($companyId) && $companyId !== '') {
            $company = Company::query()->find($companyId);
            if (! $company) {
                $this->error("Company {$companyId} not found.");

                return self::FAILURE;
            }

            $stats = $inboundService->pollCompany($company);
        } else {
            $stats = $inboundService->pollAll();
        }

        $this->info(sprintf(
            'Inbound poll complete: imported=%d acknowledged=%d skipped=%d failed=%d',
            $stats['imported'],
            $stats['acknowledged'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
