<?php

namespace App\Services\BtcPay;

use App\Services\BtcPay\Exceptions\BtcPayException;

/**
 * SEPA Instant QR plugin management (BTCPay plugin
 * BTCPayServer.Plugins.SepaInstantQr >= 0.4.0 Greenfield API).
 *
 * Certificate material only travels towards BTCPay - responses contain the
 * nopCertSet flag and the parsed identity, never secrets.
 */
class SepaService
{
    public function __construct(protected BtcPayClient $client) {}

    private function base(string $storeId): string
    {
        return "/api/v1/stores/{$storeId}/plugins/sepa-instant-qr";
    }

    /**
     * Probe whether the SEPA Instant QR plugin is installed on the server.
     */
    public function probe(string $storeId, ?string $userApiKey = null): bool
    {
        try {
            $this->getSettings($storeId, $userApiKey);

            return true;
        } catch (BtcPayException $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }
    }

    public function getSettings(string $storeId, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId) {
            return $this->client->get($this->base($storeId).'/settings');
        });
    }

    /**
     * @param  array  $data  camelCase plugin payload (enabled, countryProfile,
     *                       iban, beneficiary, bic, message, confirmationBackend,
     *                       skQrVariant, amountTolerance, nopEnvironment)
     */
    public function updateSettings(string $storeId, array $data, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId, $data) {
            return $this->client->put($this->base($storeId).'/settings', $data);
        });
    }

    /**
     * @param  array  $data  { pfxBase64?, pfxPassword?, certPem?, keyPem?, nopEnvironment? }
     */
    public function uploadCertificate(string $storeId, array $data, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId, $data) {
            return $this->client->post($this->base($storeId).'/certificate', $data);
        });
    }

    public function deleteCertificate(string $storeId, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId) {
            return $this->client->delete($this->base($storeId).'/certificate');
        });
    }

    /**
     * @param  array  $data  { token }
     */
    public function setFioToken(string $storeId, array $data, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId, $data) {
            return $this->client->post($this->base($storeId).'/fio-token', $data);
        });
    }

    public function clearFioToken(string $storeId, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId) {
            return $this->client->delete($this->base($storeId).'/fio-token');
        });
    }

    /**
     * @return array { ok, message }
     */
    public function testBackend(string $storeId, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId) {
            return $this->client->post($this->base($storeId).'/test', []);
        });
    }

    /**
     * @param  string|null  $state  pending | review | null (both)
     */
    public function listPaymentRequests(string $storeId, ?string $state = null, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId, $state) {
            return $this->client->get(
                $this->base($storeId).'/payment-requests',
                $state !== null ? ['state' => $state] : []
            );
        });
    }

    /**
     * @return array { outcome }
     */
    public function confirmPaymentRequest(string $storeId, string $reference, ?string $userApiKey = null): array
    {
        return $this->client->withUserKey($userApiKey, function () use ($storeId, $reference) {
            return $this->client->post($this->base($storeId).'/payment-requests/'.rawurlencode($reference).'/confirm', []);
        });
    }
}
