<?php

namespace App\Support\Invoicing;

use App\Models\Company;
use App\Support\Invoicing\Efaktura\PeppolParticipantId;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-company Slovak e-faktura / SAPI-SK credentials (stored in app_settings).
 */
final class CompanyEfakturaSettings
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values = [],
        protected ?string $derivedPeppolParticipantId = null,
    ) {}

    public static function fromCompany(Company $company): self
    {
        return new self(
            CompanyAppSettings::from($company->app_settings)->toArray(),
            PeppolParticipantId::fromCompany($company),
        );
    }

    public function enabled(): bool
    {
        return (bool) ($this->values['efaktura_enabled'] ?? false);
    }

    public function autoSend(): bool
    {
        return (bool) ($this->values['efaktura_auto_send'] ?? false);
    }

    public function inboundEnabled(): bool
    {
        return (bool) ($this->values['efaktura_inbound_enabled'] ?? false);
    }

    public function provider(): string
    {
        return (string) ($this->values['efaktura_provider'] ?? 'sapi_sk');
    }

    /** The explicitly stored participant ID only (settings form value). */
    public function explicitPeppolParticipantId(): ?string
    {
        $id = trim((string) ($this->values['efaktura_peppol_participant_id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    /** Auto-derived from the company DIČ (0245) or IČO (0208) - see SkUblProfile. */
    public function derivedPeppolParticipantId(): ?string
    {
        return $this->derivedPeppolParticipantId;
    }

    /**
     * Effective sender ID: the explicit override wins, otherwise the value
     * derived from the company registration data - merchants never have to
     * learn the scheme syntax when their DIČ/IČO is filled in.
     */
    public function peppolParticipantId(): ?string
    {
        return $this->explicitPeppolParticipantId() ?? $this->derivedPeppolParticipantId;
    }

    public function sapiBaseUrl(): ?string
    {
        $url = trim((string) ($this->values['efaktura_sapi_base_url'] ?? ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        $fallback = rtrim((string) config('efaktura.providers.sapi_sk.base_url'), '/');

        return $fallback !== '' ? $fallback : null;
    }

    public function sapiClientId(): ?string
    {
        $id = trim((string) ($this->values['efaktura_sapi_client_id'] ?? ''));

        return $id !== '' ? $id : null;
    }

    public function sapiClientSecret(): ?string
    {
        $plain = $this->values['efaktura_sapi_client_secret'] ?? null;
        if (is_string($plain) && $plain !== '') {
            return $plain;
        }

        $encrypted = $this->values['efaktura_sapi_client_secret_encrypted'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function configured(): bool
    {
        return $this->enabled()
            && $this->peppolParticipantId() !== null
            && $this->sapiBaseUrl() !== null
            && $this->sapiClientId() !== null
            && $this->sapiClientSecret() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        return [
            'efaktura_enabled' => $this->enabled(),
            'efaktura_auto_send' => $this->autoSend(),
            'efaktura_inbound_enabled' => $this->inboundEnabled(),
            'efaktura_provider' => $this->provider(),
            'efaktura_sapi_base_url' => $this->sapiBaseUrl(),
            // The form shows only the explicit override; the derived default
            // travels separately so the UI can hint "we will use 0245:...".
            'efaktura_peppol_participant_id' => $this->explicitPeppolParticipantId(),
            'efaktura_peppol_participant_id_derived' => $this->derivedPeppolParticipantId,
            'efaktura_sapi_client_id' => $this->sapiClientId(),
            'efaktura_sapi_client_secret_set' => $this->sapiClientSecret() !== null,
            'efaktura_connection_tested_at' => $this->values['efaktura_connection_tested_at'] ?? null,
            'efaktura_inbound_last_poll_at' => $this->values['efaktura_inbound_last_poll_at'] ?? null,
            'efaktura_inbound_last_poll_stats' => $this->values['efaktura_inbound_last_poll_stats'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeIncoming(array $current, array $incoming): array
    {
        $merged = $current;

        foreach ([
            'efaktura_enabled',
            'efaktura_auto_send',
            'efaktura_inbound_enabled',
            'efaktura_provider',
            'efaktura_peppol_participant_id',
            'efaktura_sapi_client_id',
        ] as $key) {
            if (array_key_exists($key, $incoming)) {
                $merged[$key] = $incoming[$key];
            }
        }

        if (array_key_exists('efaktura_sapi_base_url', $incoming)) {
            $url = trim((string) $incoming['efaktura_sapi_base_url']);
            $merged['efaktura_sapi_base_url'] = $url !== '' ? rtrim($url, '/') : null;
        }

        if (array_key_exists('efaktura_sapi_client_secret', $incoming)) {
            $secret = is_string($incoming['efaktura_sapi_client_secret'])
                ? trim($incoming['efaktura_sapi_client_secret'])
                : $incoming['efaktura_sapi_client_secret'];
            if ($secret === null || $secret === '') {
                $merged['efaktura_sapi_client_secret_encrypted'] = null;
            } else {
                $merged['efaktura_sapi_client_secret_encrypted'] = Crypt::encryptString((string) $secret);
            }
        }

        return $merged;
    }
}
