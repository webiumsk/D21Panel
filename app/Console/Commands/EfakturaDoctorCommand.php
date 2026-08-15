<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\EfakturaCpdsProvider;
use App\Services\Invoicing\Efaktura\EfakturaConnectionTester;
use App\Services\Invoicing\Efaktura\SapiSkClient;
use App\Support\Invoicing\CompanyEfakturaEligibility;
use App\Support\Invoicing\CompanyEfakturaSettings;
use Illuminate\Console\Command;

/**
 * Ops mirror of the merchant readiness checklist: prints the global module
 * state, CPDS presets and per-company configuration (derived Peppol ID,
 * allowlist verdict for the base URL, credentials). With --live it performs
 * a real SAPI-SK authentication per configured company - the only part that
 * talks to the network besides DNS resolution in the URL check.
 */
class EfakturaDoctorCommand extends Command
{
    protected $signature = 'efaktura:doctor
        {--company= : Limit the check to one company UUID}
        {--live : Perform a real SAPI-SK authentication for configured companies}';

    protected $description = 'Diagnose the Slovak e-faktura setup: global config, CPDS presets and per-company readiness';

    public function handle(
        CompanyEfakturaEligibility $eligibility,
        SapiSkClient $client,
        EfakturaConnectionTester $tester,
    ): int {
        $enabled = (bool) config('efaktura.enabled');

        $this->info('Global configuration');
        $this->line('  EFAKTURA_ENABLED: '.($enabled ? 'true' : 'false'));
        $this->line('  provider: '.config('efaktura.default_provider'));

        $globalBase = (string) config('efaktura.providers.sapi_sk.base_url');
        $this->line('  global base URL fallback: '.($globalBase !== '' ? $globalBase : '(none)'));

        $envHosts = (array) config('efaktura.allowed_sapi_hosts', []);
        $this->line('  allowed hosts (env): '.($envHosts !== [] ? implode(', ', $envHosts) : '(none - any public HTTPS host)'));

        $presets = EfakturaCpdsProvider::activePresets();
        $this->line('  active CPDS presets: '.count($presets).(
            $presets !== [] ? ' ('.implode(', ', array_column($presets, 'name')).')' : ''
        ));

        $detailPath = (string) config('efaktura.providers.sapi_sk.send_detail_path', '');
        $this->line('  send detail path (global): '.($detailPath !== '' ? $detailPath : '(none)'));

        $this->line('  scheduler: '.($enabled
            ? 'efaktura:poll-inbound @15m + efaktura:sync-compliance-status @30m registered'
            : 'not registered (module off)'));

        if (! $enabled) {
            $this->warn('Module is globally OFF - the gateway is a noop and the merchant UI is hidden.');
        }

        $companyId = $this->option('company');
        if (is_string($companyId) && $companyId !== '') {
            $company = Company::query()->find($companyId);
            if (! $company) {
                $this->error("Company {$companyId} not found.");

                return self::FAILURE;
            }
            $companies = collect([$company]);
        } else {
            $companies = Company::query()->where('jurisdiction', 'eu_sk')->orderBy('created_at')->get();
        }

        if ($companies->isEmpty()) {
            $this->line('');
            $this->line('No Slovak companies found.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $this->line('');
            $this->info(sprintf('%s (%s)', $company->legal_name ?: $company->trade_name ?: '?', $company->id));

            if (! $eligibility->supportsCompany($company)) {
                $this->line('  eligible (outbound): no - only eu_sk full VAT payers issue e-invoices');
                // The statutory RECEIVING obligation covers all SK taxable
                // entities from 2027; the module's inbound is currently also
                // limited to full payers (pollAll/pollCompany gate on the
                // same eligibility) - documented in docs/SK_EFAKTURA.md.
                $this->line('  note: receiving readiness is not tracked for non-payers (module limitation)');

                continue;
            }
            $this->line('  eligible: yes');

            $settings = CompanyEfakturaSettings::fromCompany($company);
            $this->line('  efaktura_enabled (company): '.($settings->enabled() ? 'yes' : 'no'));

            $baseUrl = $settings->sapiBaseUrl();
            $baseUrlValid = false;
            if ($baseUrl === null) {
                $this->line('  base URL: (none)');
            } else {
                try {
                    $client->validateBaseUrl($baseUrl);
                    $baseUrlValid = true;
                    $this->line("  base URL: {$baseUrl} (host allowed)");
                } catch (\RuntimeException $exception) {
                    $this->warn("  base URL: {$baseUrl} - REJECTED: {$exception->getMessage()}");
                }
            }

            $this->line('  participant ID: '.($settings->peppolParticipantId() ?? '(none)').(
                $settings->explicitPeppolParticipantId() !== null
                    ? ' (explicit)'
                    : ($settings->derivedPeppolParticipantId() !== null ? ' (derived from DIČ/IČO)' : '')
            ));
            $this->line('  client ID set: '.($settings->sapiClientId() !== null ? 'yes' : 'no'));
            $this->line('  client secret decryptable: '.($settings->sapiClientSecret() !== null ? 'yes' : 'no'));
            $this->line('  connection tested at: '.((string) ($settings->values['efaktura_connection_tested_at'] ?? '') ?: '(never)'));
            $this->line('  auto-send: '.($settings->autoSend() ? 'yes' : 'no').', inbound: '.($settings->inboundEnabled() ? 'yes' : 'no'));

            // A rejected base URL means every real call would fail - it
            // downgrades the verdict and blocks the --live attempt.
            $configured = $settings->configured() && $baseUrlValid;
            if ($configured) {
                $this->line('  configured: yes');
            } elseif ($settings->configured() && ! $baseUrlValid) {
                $this->warn('  configured: NO (base URL rejected)');
            } else {
                $this->warn('  configured: NO');
            }

            if ($this->option('live') && $configured) {
                $result = $tester->test($baseUrl, $settings->sapiClientId(), $settings->sapiClientSecret());
                if ($result['ok']) {
                    $this->info('  live authentication: OK');
                } else {
                    $this->warn(sprintf(
                        '  live authentication: FAILED (%s%s)',
                        $result['code'] ?? 'error',
                        isset($result['message']) ? ' - '.$result['message'] : '',
                    ));
                }
            }
        }

        return self::SUCCESS;
    }
}
