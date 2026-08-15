<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\ResetCompanyDataRequest;
use App\Http\Requests\Invoicing\StoreCompanyRequest;
use App\Http\Requests\Invoicing\UpdateCompanyAppSettingsRequest;
use App\Http\Requests\Invoicing\UpdateCompanyRequest;
use App\Http\Requests\Invoicing\UpdateCompanyStoresRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Store;
use App\Services\Invoicing\BankInboundAddressService;
use App\Services\Invoicing\CompanyBrandingService;
use App\Services\Invoicing\CompanyDataResetService;
use App\Services\Invoicing\DocumentSequenceService;
use App\Support\Invoicing\CompanyAppSettings;
use App\Support\Invoicing\CompanyEfakturaEligibility;
use App\Support\Invoicing\CompanyEfakturaSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index(Request $request, CompanyBrandingService $brandingService): JsonResponse
    {
        $companies = Company::query()
            ->where('user_id', $request->user()->id)
            ->withCount(['contacts', 'documents'])
            ->orderBy('legal_name')
            ->get();

        $data = $companies->map(function (Company $company) use ($brandingService) {
            return array_merge(
                [
                    'id' => $company->id,
                    'legal_name' => $company->legal_name,
                    'trade_name' => $company->trade_name,
                    'registration_number' => $company->registration_number,
                    'documents_count' => (int) $company->documents_count,
                ],
                $brandingService->brandingMeta($company),
            );
        });

        return response()->json(['data' => $data]);
    }

    public function store(
        StoreCompanyRequest $request,
        DocumentSequenceService $sequenceService,
        BankInboundAddressService $inboundAddressService,
    ): JsonResponse {
        $validated = $request->validated();
        $storeId = $validated['store_id'] ?? null;
        unset($validated['store_id']);

        $vatStatus = $validated['vat_status'] ?? (($validated['vat_payer'] ?? false) ? 'payer' : 'none');

        $company = Company::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'country' => $request->input('country', 'SK'),
            'default_currency' => $request->input('default_currency', 'EUR'),
            'vat_status' => $vatStatus,
            'vat_payer' => in_array($vatStatus, ['payer', 'partial'], true),
            'bank_inbound_token' => $inboundAddressService->generateUniqueToken(),
        ]);

        if ($storeId) {
            Store::query()
                ->where('user_id', $request->user()->id)
                ->where('id', $storeId)
                ->update(['company_id' => $company->id]);
        }

        $sequenceService->seedDefaultsForCompany($company, app()->getLocale());

        AuditLog::log('company.created', 'company', $company->id);

        $company->load('stores:id,name,company_id,default_currency');

        return response()->json(['data' => $company], 201);
    }

    public function show(Company $company, CompanyBrandingService $brandingService): JsonResponse
    {
        $company->load(['stores:id,name,company_id,default_currency', 'contacts' => fn ($q) => $q->where('is_active', true)]);

        return response()->json([
            'data' => $this->companyPayload($company, $brandingService),
        ]);
    }

    public function summary(Company $company): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $company->id,
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'has_bank_account' => $company->hasBankAccount(),
                'bank_account_label' => $company->maskedBankAccountLabel(),
                'default_currency' => $company->default_currency,
            ],
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company, CompanyBrandingService $brandingService): JsonResponse
    {
        $validated = $request->validated();
        if (array_key_exists('vat_status', $validated)) {
            $validated['vat_payer'] = in_array($validated['vat_status'], ['payer', 'partial'], true);
        } elseif (array_key_exists('vat_payer', $validated) && ! array_key_exists('vat_status', $validated)) {
            $validated['vat_status'] = $validated['vat_payer'] ? 'payer' : 'none';
        }

        $company->update($validated);
        $fresh = $company->fresh();

        return response()->json([
            'data' => $this->companyPayload($fresh, $brandingService),
        ]);
    }

    public function updateAppSettings(
        UpdateCompanyAppSettingsRequest $request,
        Company $company,
        CompanyBrandingService $brandingService,
    ): JsonResponse {
        $incoming = $this->normalizeWriteOnlySettings($request->validatedSettings());
        $eligibility = app(CompanyEfakturaEligibility::class);
        if ($this->hasMeaningfulEfakturaSettings($incoming)) {
            if (! $eligibility->supportsCompany($company)) {
                throw ValidationException::withMessages([
                    'efaktura' => ['E-faktura settings are available only for full VAT payers.'],
                ]);
            }
        }
        $current = CompanyAppSettings::from($company->app_settings)->toArray();
        $merged = CompanyEfakturaSettings::mergeIncoming(
            array_merge($current, $incoming),
            $incoming,
        );
        unset($merged['efaktura_sapi_client_secret']);
        $company->update([
            'app_settings' => $merged,
        ]);

        AuditLog::log('company.app_settings_updated', 'company', $company->id);

        return response()->json([
            'data' => $this->companyPayload($company->fresh(), $brandingService),
        ]);
    }

    public function resetData(
        ResetCompanyDataRequest $request,
        Company $company,
        CompanyDataResetService $resetService,
    ): JsonResponse {
        $stats = $resetService->reset($company);

        return response()->json([
            'message' => 'Company operational data reset.',
            'data' => $stats,
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        Store::query()
            ->where('company_id', $company->id)
            ->update(['company_id' => null]);

        $company->delete();

        return response()->json(['message' => 'Company deleted']);
    }

    public function updateStores(UpdateCompanyStoresRequest $request, Company $company): JsonResponse
    {
        $storeIds = $request->input('store_ids', []);
        $owned = Store::query()
            ->where('user_id', $company->user_id)
            ->whereIn('id', $storeIds)
            ->pluck('id')
            ->all();

        Store::query()
            ->where('user_id', $company->user_id)
            ->where('company_id', $company->id)
            ->whereNotIn('id', $owned)
            ->update(['company_id' => null]);

        Store::query()
            ->whereIn('id', $owned)
            ->update(['company_id' => $company->id]);

        return response()->json([
            'data' => $company->fresh()->load('stores:id,name,company_id,default_currency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function companyPayload(Company $company, CompanyBrandingService $brandingService): array
    {
        return array_merge(
            $company->toArray(),
            ['app_settings' => $company->resolvedAppSettings()],
            ['email_settings' => $company->resolvedEmailSettings()],
            $brandingService->brandingMeta($company),
        );
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    protected function normalizeWriteOnlySettings(array $incoming): array
    {
        if (array_key_exists('stripe_tax_secret_key', $incoming)) {
            $secret = is_string($incoming['stripe_tax_secret_key'])
                ? trim($incoming['stripe_tax_secret_key'])
                : $incoming['stripe_tax_secret_key'];

            if ($secret === null || $secret === '') {
                unset($incoming['stripe_tax_secret_key']);
            } else {
                $incoming['stripe_tax_secret_key'] = (string) $secret;
            }
        }

        return $incoming;
    }

    /**
     * @param  array<string, mixed>  $incoming
     */
    protected function hasMeaningfulEfakturaSettings(array $incoming): bool
    {
        foreach ([
            'efaktura_enabled',
            'efaktura_auto_send',
            'efaktura_inbound_enabled',
        ] as $key) {
            if (($incoming[$key] ?? false) === true) {
                return true;
            }
        }

        foreach ([
            'efaktura_peppol_participant_id',
            'efaktura_sapi_client_id',
            'efaktura_sapi_client_secret',
        ] as $key) {
            if (array_key_exists($key, $incoming) && trim((string) $incoming[$key]) !== '') {
                return true;
            }
        }

        return false;
    }
}
