<?php

namespace App\Services\Invoicing\Wise;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class WiseApiClient
{
    public function baseUrl(): string
    {
        return config('wise.use_sandbox', false)
            ? (string) config('wise.sandbox_url')
            : (string) config('wise.base_url');
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function profiles(string $token): array
    {
        $response = $this->request($token)->get($this->baseUrl().'/v1/profiles');
        $response->throw();

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws RequestException
     */
    public function balances(string $token, int $profileId): array
    {
        $response = $this->request($token)->get(
            $this->baseUrl().'/v4/profiles/'.$profileId.'/balances',
            ['types' => 'STANDARD'],
        );
        $response->throw();

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function statement(
        string $token,
        int $profileId,
        int $balanceId,
        string $intervalStart,
        string $intervalEnd,
    ): array {
        $response = $this->request($token)->get(
            $this->baseUrl().'/v1/profiles/'.$profileId.'/balance-statements/'.$balanceId.'/statement.json',
            [
                'intervalStart' => $intervalStart,
                'intervalEnd' => $intervalEnd,
            ],
        );
        $response->throw();

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    protected function request(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout(30);
    }
}
