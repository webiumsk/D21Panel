<?php

namespace App\Services\Invoicing;

use App\Models\Company;
use App\Models\Store;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BankInboundAddressService
{
    public function ensureToken(Company $company): string
    {
        if ($company->bank_inbound_token) {
            return $company->bank_inbound_token;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($company): string {
                    $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

                    if ($locked->bank_inbound_token) {
                        $company->bank_inbound_token = $locked->bank_inbound_token;

                        return $locked->bank_inbound_token;
                    }

                    $token = $this->generateUniqueToken();
                    $locked->update(['bank_inbound_token' => $token]);
                    $company->bank_inbound_token = $token;

                    return $token;
                });
            } catch (UniqueConstraintViolationException) {
                $company->refresh();
                if ($company->bank_inbound_token) {
                    return $company->bank_inbound_token;
                }
            }
        }

        return $this->ensureToken($company->refresh());
    }

    public function buildAddress(Company $company): string
    {
        $token = $this->ensureToken($company);
        $address = $this->prefix().$token.'@'.$this->domain();

        if (strlen($address) > $this->maxAddressLength()) {
            throw new InvalidArgumentException(
                'Bank inbound address exceeds max length ('.$this->maxAddressLength().' chars). Shorten BANK_INBOUND_DOMAIN or BANK_INBOUND_ADDRESS_PREFIX.'
            );
        }

        return $address;
    }

    public function resolveCompany(string $to): Company
    {
        $to = strtolower(trim($to));
        $domain = preg_quote($this->domain(), '/');
        $prefix = preg_quote($this->prefix(), '/');
        $tokenLength = $this->tokenLength();

        if (! preg_match('/^'.$prefix.'([a-z0-9]{'.$tokenLength.'})@'.$domain.'$/', $to, $matches)) {
            throw ValidationException::withMessages([
                'to' => ['Unknown inbound bank address.'],
            ]);
        }

        return Company::query()->where('bank_inbound_token', $matches[1])->firstOrFail();
    }

    /**
     * Store-scoped variant of the inbound address (SEPA payment
     * confirmations): distinct prefix, token stored on stores.
     */
    public function ensureStoreToken(Store $store): string
    {
        if ($store->bank_inbound_token) {
            return $store->bank_inbound_token;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($store): string {
                    $locked = Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();

                    if ($locked->bank_inbound_token) {
                        $store->bank_inbound_token = $locked->bank_inbound_token;

                        return $locked->bank_inbound_token;
                    }

                    $token = $this->generateUniqueStoreToken();
                    $locked->forceFill(['bank_inbound_token' => $token])->save();
                    $store->bank_inbound_token = $token;

                    return $token;
                });
            } catch (UniqueConstraintViolationException) {
                $store->refresh();
                if ($store->bank_inbound_token) {
                    return $store->bank_inbound_token;
                }
            }
        }

        return $this->ensureStoreToken($store->refresh());
    }

    public function buildStoreAddress(Store $store): string
    {
        $token = $this->ensureStoreToken($store);
        $address = $this->storePrefix().$token.'@'.$this->domain();

        if (strlen($address) > $this->maxAddressLength()) {
            throw new InvalidArgumentException(
                'Bank inbound address exceeds max length ('.$this->maxAddressLength().' chars). Shorten BANK_INBOUND_DOMAIN or BANK_INBOUND_STORE_ADDRESS_PREFIX.'
            );
        }

        return $address;
    }

    /**
     * Resolves any inbound address to its owner: a Company (invoicing
     * bank matching) or a Store (SEPA payment confirmation).
     */
    public function resolveOwner(string $to): Company|Store
    {
        $normalized = strtolower(trim($to));
        $domain = preg_quote($this->domain(), '/');
        $storePrefix = preg_quote($this->storePrefix(), '/');
        $storeTokenLength = $this->storeTokenLength();

        if (preg_match('/^'.$storePrefix.'([a-z0-9]{'.$storeTokenLength.'})@'.$domain.'$/', $normalized, $matches)) {
            $store = Store::query()->where('bank_inbound_token', $matches[1])->first();
            if ($store !== null) {
                return $store;
            }
        }

        return $this->resolveCompany($to);
    }

    public function generateUniqueStoreToken(): string
    {
        $length = $this->storeTokenLength();

        do {
            $token = Str::lower(Str::random($length));
        } while (! preg_match('/^[a-z0-9]{'.$length.'}$/', $token)
            || Store::query()->where('bank_inbound_token', $token)->exists());

        return $token;
    }

    public function storeTokenLength(): int
    {
        $maxLocal = $this->maxAddressLength() - strlen('@'.$this->domain());
        $available = $maxLocal - strlen($this->storePrefix());

        if ($available < 8) {
            throw new InvalidArgumentException(
                'Bank inbound store prefix/domain leave too little room for token (need at least 8 chars).'
            );
        }

        return min(12, $available);
    }

    protected function storePrefix(): string
    {
        return strtolower((string) config('bank_inbound.store_address_prefix', 'ps'));
    }

    public function generateUniqueToken(): string
    {
        $length = $this->tokenLength();

        do {
            $token = Str::lower(Str::random($length));
        } while (! preg_match('/^[a-z0-9]{'.$length.'}$/', $token)
            || Company::query()->where('bank_inbound_token', $token)->exists());

        return $token;
    }

    public function tokenLength(): int
    {
        $maxLocal = $this->maxAddressLength() - strlen('@'.$this->domain());
        $available = $maxLocal - strlen($this->prefix());

        if ($available < 8) {
            throw new InvalidArgumentException(
                'Bank inbound address prefix/domain leave too little room for token (need at least 8 chars).'
            );
        }

        return min(12, $available);
    }

    public function maxAddressLength(): int
    {
        return max(20, (int) config('bank_inbound.max_address_length', 50));
    }

    protected function domain(): string
    {
        return strtolower((string) config('bank_inbound.domain', 'payments.satflux.io'));
    }

    protected function prefix(): string
    {
        return strtolower((string) config('bank_inbound.address_prefix', 'pay'));
    }
}
