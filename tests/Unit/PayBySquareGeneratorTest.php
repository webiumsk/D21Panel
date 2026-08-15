<?php

namespace Tests\Unit;

use App\Enums\BusinessDocumentStatus;
use App\Enums\CompanyJurisdiction;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Services\Invoicing\PayBySquareGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PayBySquareGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_lzma_encoded_pay_by_square_payload(): void
    {
        if (! is_executable('/usr/bin/xz')) {
            $this->markTestSkipped('xz binary required for Pay by square encoding');
        }

        $company = Company::create([
            'user_id' => User::factory()->create()->id,
            'legal_name' => 'Acme s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'iban' => 'SK31 1200 0000 1987 4754 7509',
            'default_currency' => 'EUR',
        ]);

        $document = BusinessDocument::create([
            'company_id' => $company->id,
            'type' => 'invoice',
            'status' => BusinessDocumentStatus::Issued,
            'number' => '20260001',
            'variable_symbol' => '20260001',
            'total' => 125.50,
            'currency' => 'EUR',
            'payment_bank_enabled' => true,
            'issue_date' => now()->subDays(3),
            'due_date' => now()->addDays(14),
        ]);

        $service = app(PayBySquareGenerator::class);
        $this->assertTrue($service->canGenerate($company, $document));

        $payload = $service->generatePayload($company, $document);
        $this->assertNotEmpty($payload);
        $this->assertMatchesRegularExpression('/^[0-9A-V]+$/', $payload);
        $this->assertGreaterThan(40, strlen($payload));

        // Without chillerlan installed the renderer falls back to the
        // qrserver.com API - fake it so the test never leaves the machine
        // (the old file_get_contents fallback used to make a real request).
        Http::fake([
            'api.qrserver.com/*' => Http::response('png-bytes'),
        ]);

        $qr = $service->generateQrDataUri($company, $document);
        $this->assertNotNull($qr);
        $this->assertStringStartsWith('data:image/png;base64,', $qr);
    }
}
