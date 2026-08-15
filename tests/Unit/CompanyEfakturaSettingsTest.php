<?php

namespace Tests\Unit;

use App\Enums\CompanyJurisdiction;
use App\Models\Company;
use App\Support\Invoicing\CompanyEfakturaSettings;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyEfakturaSettingsTest extends TestCase
{
    private function skCompany(array $attributes = []): Company
    {
        return new Company(array_merge([
            'legal_name' => 'Webium s.r.o.',
            'jurisdiction' => CompanyJurisdiction::EuSk,
            'registration_number' => '47615681',
            'tax_id' => '2023980035',
            'vat_payer' => true,
            'vat_status' => 'payer',
        ], $attributes));
    }

    #[Test]
    public function participant_id_derives_from_dic_when_no_explicit_value(): void
    {
        $settings = CompanyEfakturaSettings::fromCompany($this->skCompany());

        $this->assertNull($settings->explicitPeppolParticipantId());
        $this->assertSame('0245:2023980035', $settings->derivedPeppolParticipantId());
        $this->assertSame('0245:2023980035', $settings->peppolParticipantId());
    }

    #[Test]
    public function participant_id_falls_back_to_ico_without_dic(): void
    {
        $settings = CompanyEfakturaSettings::fromCompany($this->skCompany(['tax_id' => null]));

        $this->assertSame('0208:47615681', $settings->peppolParticipantId());
    }

    #[Test]
    public function explicit_participant_id_wins_over_the_derived_one(): void
    {
        $settings = CompanyEfakturaSettings::fromCompany($this->skCompany([
            'app_settings' => ['efaktura_peppol_participant_id' => '0245:9999999999'],
        ]));

        $this->assertSame('0245:9999999999', $settings->explicitPeppolParticipantId());
        $this->assertSame('0245:2023980035', $settings->derivedPeppolParticipantId());
        $this->assertSame('0245:9999999999', $settings->peppolParticipantId());
    }

    #[Test]
    public function configured_accepts_the_derived_participant_id(): void
    {
        // No explicit efaktura_peppol_participant_id - the DIČ derivation
        // must be enough, so merchants never type the scheme syntax.
        $settings = CompanyEfakturaSettings::fromCompany($this->skCompany([
            'app_settings' => [
                'efaktura_enabled' => true,
                'efaktura_sapi_base_url' => 'https://sapi.test',
                'efaktura_sapi_client_id' => 'client-test',
                'efaktura_sapi_client_secret_encrypted' => Crypt::encryptString('secret-test'),
            ],
        ]));

        $this->assertTrue($settings->configured());
    }

    #[Test]
    public function public_payload_separates_explicit_and_derived_ids(): void
    {
        $payload = CompanyEfakturaSettings::fromCompany($this->skCompany())->publicPayload();

        $this->assertNull($payload['efaktura_peppol_participant_id']);
        $this->assertSame('0245:2023980035', $payload['efaktura_peppol_participant_id_derived']);
        $this->assertArrayHasKey('efaktura_connection_tested_at', $payload);
    }
}
