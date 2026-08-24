<?php

namespace Tests\Feature;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class EphemeralAccountantExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Company}
     */
    private function proUserWithCompany(): array
    {
        $plan = SubscriptionPlan::create([
            'code' => 'pro',
            'name' => 'pro',
            'display_name' => 'Pro',
            'price_eur' => 99,
            'billing_period' => 'year',
            'max_stores' => 3,
            'max_api_keys' => 3,
            'max_ln_addresses' => null,
            'features' => ['business_invoicing'],
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
        ]);
        $company = Company::create([
            'user_id' => $user->id,
            'legal_name' => 'Bridge s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'default_currency' => 'EUR',
            'registration_number' => '47615681',
            'country' => 'SK',
            'vat_payer' => true,
            'vat_status' => 'payer',
        ]);

        return [$user, $company];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $document = [
            'contact' => ['name' => 'Client Ltd', 'registration_number' => '12345678', 'country' => 'SK'],
            'document' => [
                'type' => 'invoice',
                'status' => 'issued',
                'number' => 'LOCAL-2026-001',
                'issue_date' => '2026-03-05',
                'due_date' => '2026-03-19',
                'currency' => 'EUR',
                'pdf_locale' => 'sk',
            ],
            'lines' => [
                ['name' => 'Consulting', 'quantity' => 1, 'unit' => 'h', 'unit_price' => 100, 'tax_rate' => 23],
            ],
        ];

        return array_merge([
            'company' => [
                'legal_name' => 'Local Studio s.r.o.',
                'registration_number' => '47615681',
                'street' => 'Main 1',
                'city' => 'Bratislava',
                'postal_code' => '81101',
                'country' => 'SK',
                'default_currency' => 'EUR',
                'jurisdiction' => 'eu_sk',
                'vat_payer' => true,
                'vat_status' => 'payer',
            ],
            'documents' => [$document],
            'expenses' => [
                [
                    'internal_number' => 'EXP20260001',
                    'external_number' => 'IN-7788',
                    'supplier_name' => 'Supplier s.r.o.',
                    'issue_date' => '2026-03-02',
                    'total' => 88.5,
                    'currency' => 'EUR',
                    'status' => 'recorded',
                    'attachments' => [
                        ['filename' => 'faktura.pdf', 'mime' => 'application/pdf', 'content_base64' => base64_encode('%PDF-1.4 fake')],
                    ],
                ],
            ],
            'options' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function zipEntries(string $binary): array
    {
        $path = tempnam(sys_get_temp_dir(), 'acc-');
        file_put_contents($path, $binary);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);
        ksort($entries);

        return $entries;
    }

    #[Test]
    public function local_first_client_downloads_a_package_without_persisting_anything(): void
    {
        [$user] = $this->proUserWithCompany();

        $response = $this->actingAs($user)->postJson('/api/invoicing/ephemeral/accountant-export', $this->payload());

        $response->assertOk();
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('content-type'));

        $entries = $this->zipEntries($response->getContent());
        $this->assertSame([
            'csv/issued.csv',
            'csv/received.csv',
            'csv/vat_summary.csv',
            'issued/LOCAL-2026-001.isdoc',
            'issued/LOCAL-2026-001.pdf',
            'manifest.txt',
            'pohoda/invoices.xml',
            'received/EXP20260001/faktura.pdf',
        ], array_keys($entries));
        $this->assertSame('%PDF-1.4 fake', $entries['received/EXP20260001/faktura.pdf']);
        $this->assertStringContainsString('IN-7788', $entries['pohoda/invoices.xml']);

        $this->assertDatabaseCount('business_documents', 0);
        $this->assertDatabaseCount('business_expenses', 0);
        $this->assertDatabaseCount('business_expense_attachments', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'business_document.ephemeral_accountant_export']);
    }

    #[Test]
    public function company_scoped_variant_uses_the_route_company(): void
    {
        [$user, $company] = $this->proUserWithCompany();

        $response = $this->actingAs($user)->postJson(
            "/api/invoicing/companies/{$company->id}/documents/ephemeral/accountant-export",
            $this->payload(),
        );

        $response->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'business_document.ephemeral_accountant_export',
            'target_id' => $company->id,
        ]);
    }

    #[Test]
    public function expenses_only_period_is_allowed(): void
    {
        [$user] = $this->proUserWithCompany();

        $response = $this->actingAs($user)->postJson(
            '/api/invoicing/ephemeral/accountant-export',
            $this->payload(['documents' => []]),
        );

        $response->assertOk();
        $this->assertArrayHasKey('received/EXP20260001/faktura.pdf', $this->zipEntries($response->getContent()));
    }

    #[Test]
    public function invalid_attachment_mime_and_missing_options_are_rejected(): void
    {
        [$user] = $this->proUserWithCompany();
        $payload = $this->payload();
        $payload['expenses'][0]['attachments'][0]['mime'] = 'application/x-msdownload';

        $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/accountant-export', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expenses.0.attachments.0.mime']);

        $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/accountant-export', $this->payload(['options' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['options.from']);
    }

    #[Test]
    public function oversized_attachment_budget_returns_413(): void
    {
        [$user] = $this->proUserWithCompany();
        config(['invoicing.accountant_export_max_attachment_bytes' => 1_000]);
        $payload = $this->payload();
        $payload['expenses'][0]['attachments'] = [
            ['filename' => 'big.pdf', 'mime' => 'application/pdf', 'content_base64' => base64_encode(str_repeat('x', 2_000))],
        ];

        $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/accountant-export', $payload)
            ->assertStatus(413);
    }

    #[Test]
    public function row_cap_is_enforced_on_the_ephemeral_payload(): void
    {
        [$user] = $this->proUserWithCompany();
        config(['invoicing.accountant_export_max_rows' => 1]);
        $payload = $this->payload();
        $payload['expenses'][] = array_merge($payload['expenses'][0], ['internal_number' => 'EXP20260002', 'attachments' => []]);

        $this->actingAs($user)
            ->postJson('/api/invoicing/ephemeral/accountant-export', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expenses']);
    }

    #[Test]
    public function unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/invoicing/ephemeral/accountant-export', $this->payload())
            ->assertStatus(401);
    }
}
