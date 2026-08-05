<?php

namespace App\Services;

use App\Models\WalletConnection;
use Illuminate\Support\Facades\Log;

class WalletConnectionValidator
{
    /**
     * Parse Blink connection string.
     *
     * Custodial (api-key): type=blink;server=https://api.blink.sv/graphql;api-key=blink_xxx;wallet-id=xxx
     * Non-custodial (EU):  type=blink;ln-address=yourname@blink.sv;
     *
     * @param  string  $connectionString  Connection string to parse
     * @return array Parsed values with keys: 'type', 'server', 'api_key', 'wallet_id', 'ln_address', 'variant', 'errors'
     */
    public function parseBlinkConnectionString(string $connectionString): array
    {
        $connectionString = trim($connectionString);
        $result = [
            'type' => null,
            'server' => null,
            'api_key' => null,
            'wallet_id' => null,
            'ln_address' => null,
            'variant' => null,
            'errors' => [],
        ];

        // Check if it's a connection string format (contains semicolons)
        if (strpos($connectionString, ';') !== false) {
            // Parse key=value pairs
            $parts = explode(';', $connectionString);
            foreach ($parts as $part) {
                $part = trim($part);
                if (empty($part)) {
                    continue;
                }

                if (strpos($part, '=') === false) {
                    $result['errors'][] = "Invalid part format: {$part}";

                    continue;
                }

                [$key, $value] = explode('=', $part, 2);
                $key = trim(strtolower($key));
                $value = trim($value);

                switch ($key) {
                    case 'type':
                        $result['type'] = $value;
                        break;
                    case 'server':
                        $result['server'] = $value;
                        break;
                    case 'api-key':
                    case 'apikey':
                        $result['api_key'] = $value;
                        break;
                    case 'wallet-id':
                    case 'walletid':
                        $result['wallet_id'] = $value;
                        break;
                    case 'ln-address':
                    case 'lnaddress':
                    case 'username':
                        $result['ln_address'] = $value;
                        break;
                }
            }

            // Validate required fields
            if ($result['type'] !== 'blink') {
                $result['errors'][] = "Type must be 'blink'";
            }

            if ($result['ln_address'] !== null) {
                // Non-custodial variant: ln-address only, no API key needed
                $result['variant'] = 'ln_address';
                $address = $this->normalizeBlinkLnAddress($result['ln_address']);
                if (! $this->isBareBlinkLightningAddress($address)) {
                    $result['errors'][] = "Invalid ln-address. Must be a blink.sv Lightning address like 'yourname@blink.sv'";
                } else {
                    $result['ln_address'] = $address;
                }
            } else {
                $result['variant'] = 'api_key';
                if (empty($result['server'])) {
                    $result['errors'][] = 'Server is required';
                } elseif (! filter_var($result['server'], FILTER_VALIDATE_URL)) {
                    $result['errors'][] = 'Server must be a valid URL';
                }
                if (empty($result['api_key'])) {
                    $result['errors'][] = 'API key is required';
                }
                if (empty($result['wallet_id'])) {
                    $result['errors'][] = 'Wallet ID is required';
                }
            }
        } elseif ($this->isBareBlinkLightningAddress($connectionString)) {
            // Non-custodial shorthand: merchant pasted just their Blink Lightning address
            $result['type'] = 'blink';
            $result['variant'] = 'ln_address';
            $result['ln_address'] = $connectionString;
        } else {
            // Legacy format: just a URL or token (allow for backward compatibility)
            if (filter_var($connectionString, FILTER_VALIDATE_URL) !== false) {
                // Valid URL format
                $result['server'] = $connectionString;
            } else {
                $result['errors'][] = 'Invalid connection string format. Expected: type=blink;ln-address=yourname@blink.sv; or type=blink;server=...;api-key=...;wallet-id=...';
            }
        }

        return $result;
    }

    /**
     * The plugin defaults a bare username to the blink.sv domain - mirror that.
     */
    public function normalizeBlinkLnAddress(string $value): string
    {
        $value = trim($value);

        return str_contains($value, '@') ? $value : $value.'@blink.sv';
    }

    /**
     * Lightning address at the blink.sv domain. Also gates bare pastes - other
     * Lightning addresses stay ambiguous (Cashu uses the same shape).
     */
    public function isBareBlinkLightningAddress(string $value): bool
    {
        return (bool) preg_match('/^[^@\s;=]+@blink\.sv$/i', trim($value));
    }

    /**
     * Which Blink format a stored secret uses.
     *
     * @return 'api_key'|'ln_address'|null Null when the secret is not a parseable Blink string
     */
    public function blinkVariant(string $secret): ?string
    {
        $parsed = $this->parseBlinkConnectionString($secret);

        return empty($parsed['errors']) ? $parsed['variant'] : null;
    }

    /**
     * Canonical BTCPay connection string for a Blink secret (expands the bare
     * ln-address shorthand; api-key strings pass through unchanged).
     */
    public function formatBtcpayBlinkConnectionString(string $secret): string
    {
        $parsed = $this->parseBlinkConnectionString($secret);
        if (empty($parsed['errors']) && $parsed['variant'] === 'ln_address' && $parsed['ln_address']) {
            return 'type=blink;ln-address='.$parsed['ln_address'].';';
        }

        return trim($secret);
    }

    /**
     * The Blitz plugin defaults a bare username to the blitzwalletapp.com domain - mirror that.
     */
    public function normalizeBlitzLnAddress(string $value): string
    {
        $value = trim($value);

        return str_contains($value, '@') ? $value : $value.'@blitzwalletapp.com';
    }

    /**
     * Lightning address at the blitzwalletapp.com domain. Gates bare pastes the same
     * way blink.sv does - other Lightning addresses stay ambiguous (Cashu shape).
     */
    public function isBareBlitzLightningAddress(string $value): bool
    {
        return (bool) preg_match('/^[^@\s;=]+@blitzwalletapp\.com$/i', trim($value));
    }

    /**
     * Parse a Blitz connection secret: a bare you@blitzwalletapp.com address or
     * type=blitz;ln-address=<user[@domain]>; (aliases: lnaddress, username - the
     * plugin reads only ln-address, receive-only, no api key).
     *
     * @return array{type: string|null, ln_address: string|null, errors: array<int, string>}
     */
    public function parseBlitzConnectionString(string $connectionString): array
    {
        $trimmed = trim($connectionString);
        $result = ['type' => null, 'ln_address' => null, 'errors' => []];

        if ($trimmed === '') {
            $result['errors'][] = 'Empty Blitz connection string.';

            return $result;
        }

        if ($this->isBareBlitzLightningAddress($trimmed)) {
            $result['type'] = 'blitz';
            $result['ln_address'] = $trimmed;

            return $result;
        }

        if (! str_contains(strtolower($trimmed), 'type=blitz')) {
            $result['errors'][] = 'Not a Blitz connection string.';

            return $result;
        }

        $kv = [];
        foreach (array_filter(array_map('trim', explode(';', $trimmed))) as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $kv[strtolower(trim($key))] = trim($value);
        }

        $result['type'] = $kv['type'] ?? null;
        if ($result['type'] !== 'blitz') {
            $result['errors'][] = 'Connection string type must be blitz.';

            return $result;
        }

        $lnAddress = $kv['ln-address'] ?? $kv['lnaddress'] ?? $kv['username'] ?? null;
        if ($lnAddress === null || $lnAddress === '') {
            $result['errors'][] = "The key 'ln-address' (your Blitz Wallet Lightning address or username) is required for blitz connection strings.";

            return $result;
        }

        $result['ln_address'] = $this->normalizeBlitzLnAddress($lnAddress);

        return $result;
    }

    /**
     * Canonical BTCPay connection string for a Blitz secret (expands the bare
     * address and username shorthands).
     */
    public function formatBtcpayBlitzConnectionString(string $secret): string
    {
        $parsed = $this->parseBlitzConnectionString($secret);
        if (empty($parsed['errors']) && $parsed['ln_address']) {
            return 'type=blitz;ln-address='.$parsed['ln_address'].';';
        }

        return trim($secret);
    }

    /**
     * Parse Boltz connection string.
     *
     * Note: Boltz/Aqua typically uses watch-only descriptors, not connection strings.
     * This method is for future compatibility if Boltz uses connection strings.
     *
     * @param  string  $connectionString  Connection string to parse
     * @return array Parsed values with keys: 'type', 'server', 'macaroon', 'wallet_id', 'errors'
     */
    public function parseBoltzConnectionString(string $connectionString): array
    {
        // For now, Boltz uses descriptors, not connection strings
        // This is a placeholder for future compatibility
        return [
            'type' => null,
            'server' => null,
            'macaroon' => null,
            'wallet_id' => null,
            'errors' => ['Boltz connection strings are not yet supported. Use watch-only descriptor instead.'],
        ];
    }

    /**
     * Validate Blink connection string/token.
     *
     * @param  string  $token  Connection string or token
     * @return bool True if valid
     */
    public function validateBlinkToken(string $token): bool
    {
        // Try parsing as connection string first
        $parsed = $this->parseBlinkConnectionString($token);
        if (empty($parsed['errors'])) {
            return true;
        }

        // Fallback: legacy format validation (URL or simple token)
        $token = trim($token);

        if (empty($token) || strlen($token) < 10) {
            return false;
        }

        // Check if it's a valid URL
        if (filter_var($token, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        // Allow other connection string patterns (e.g., token-based)
        // Should not contain whitespace in the middle
        if (preg_match('/\s/', $token)) {
            return false;
        }

        return true;
    }

    /**
     * Strip optional descriptor checksum suffix (#...) after the closing paren.
     */
    public function stripDescriptorChecksum(string $descriptor): string
    {
        $descriptor = trim($descriptor);
        $hashIdx = strrpos($descriptor, '#');
        if ($hashIdx === false) {
            return $descriptor;
        }
        $lastClose = strrpos($descriptor, ')');
        if ($lastClose !== false && $hashIdx > $lastClose) {
            return rtrim(substr($descriptor, 0, $hashIdx));
        }

        return $descriptor;
    }

    /**
     * Validate Aqua/Bull (Boltz) watch-only output descriptor.
     *
     * Supports Aqua: ct(slip77(...),elsh(wpkh(...))) and Bull: ct(slip77(...),elwpkh(...))
     *
     * @param  string  $descriptor  Watch-only output descriptor
     * @return bool True if valid
     */
    public function validateAquaDescriptor(string $descriptor): bool
    {
        $descriptor = $this->stripDescriptorChecksum($descriptor);

        if ($descriptor === '') {
            return false;
        }

        if (preg_match('/(xprv|yprv|zprv)/i', $descriptor)) {
            return false;
        }

        if (! preg_match('/^ct\s*\(\s*slip77\s*\(/i', $descriptor)) {
            return false;
        }

        $hasSecondBranch = preg_match('/,\s*elsh\s*\(\s*wpkh\s*\(/i', $descriptor)
            || preg_match('/,\s*elwpkh\s*\(/i', $descriptor);
        if (! $hasSecondBranch) {
            return false;
        }

        $openParens = substr_count($descriptor, '(');
        $closeParens = substr_count($descriptor, ')');
        if ($openParens !== $closeParens || $openParens === 0) {
            return false;
        }

        $lastClose = strrpos($descriptor, ')');
        if ($lastClose === false || trim(substr($descriptor, $lastClose + 1)) !== '') {
            return false;
        }

        if (! preg_match('/(xpub|ypub|zpub|tpub|upub|vpub)/i', $descriptor)) {
            return false;
        }

        return true;
    }

    /**
     * Normalize merchant NWC URI (strip type=nwc; prefix, unify scheme).
     */
    public function normalizeNwcUri(string $value): string
    {
        $value = trim($value);
        if (str_starts_with(strtolower($value), 'type=nwc;')) {
            $value = preg_replace('/^type=nwc;key=/i', '', $value) ?? $value;
        }

        return str_replace('nostr+walletconnect://', 'nostr+walletconnect:', $value);
    }

    /**
     * NWC export from Cashu/ecash wallets (Minibits, Coinos, …) - not valid for BTCPay store Lightning.
     */
    public function isCashuWalletNwcUri(string $value): bool
    {
        $uri = strtolower($this->normalizeNwcUri($value));

        if (! str_starts_with($uri, 'nostr+walletconnect:')) {
            return false;
        }

        $markers = ['minibits', 'coinos.io', 'coinos.', 'cashu.space', 'mint.coinos'];
        foreach ($markers as $marker) {
            if (str_contains($uri, $marker)) {
                return true;
            }
        }

        if (preg_match('/[?&]lud16=([^&]+)/i', $uri, $matches)) {
            $lud16 = urldecode($matches[1]);
            $domain = strtolower((string) str($lud16)->after('@'));
            foreach (['minibits.cash', 'coinos.io'] as $cashuDomain) {
                if ($domain === $cashuDomain || str_ends_with($domain, '.'.$cashuDomain)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function extractNwcLud16(string $value): ?string
    {
        $uri = $this->normalizeNwcUri($value);
        if (! preg_match('/[?&]lud16=([^&]+)/i', $uri, $matches)) {
            return null;
        }

        $decoded = urldecode($matches[1]);

        return trim($decoded) !== '' ? trim($decoded) : null;
    }

    /**
     * Format stored NWC URI for BTCPay Lightning connection string field.
     */
    public function formatBtcpayNwcConnectionString(string $value): string
    {
        $trimmed = trim($value);
        if (str_starts_with(strtolower($trimmed), 'type=nwc;')) {
            return $trimmed;
        }

        return 'type=nwc;key='.$this->normalizeNwcUri($trimmed);
    }

    /**
     * Validate Nostr Wallet Connect URI.
     */
    public function validateNwcUri(string $value): bool
    {
        if ($this->isCashuWalletNwcUri($value)) {
            return false;
        }

        $uri = $this->normalizeNwcUri($value);
        $lower = strtolower($uri);

        if (! str_starts_with($lower, 'nostr+walletconnect:')) {
            return false;
        }
        if (strlen($uri) < 80) {
            return false;
        }
        if (! str_contains($lower, 'relay=') || ! str_contains($lower, 'secret=')) {
            return false;
        }

        return ! preg_match('/\s/', $uri);
    }

    /**
     * Validate wallet connection based on type.
     *
     * @param  string  $type  Connection type ('blink', 'aqua_descriptor', or 'nwc')
     * @param  string  $value  Secret value to validate
     * @return array ['valid' => bool, 'type' => string|null, 'errors' => array, 'error' => string|null]
     */
    public function validate(string $type, string $value): array
    {
        Log::info('WalletConnectionValidator::validate called', [
            'type' => $type,
            'value_length' => strlen($value),
            'value_preview' => substr($value, 0, 100).'...',
        ]);

        $errors = [];
        $returnType = null;

        if ($type === 'blink') {
            $returnType = 'blink';
            Log::info('Validating Blink connection string', [
                'type' => $type,
            ]);

            // Validate Blink connection string format
            $parsed = $this->parseBlinkConnectionString($value);
            Log::info('Blink connection string parsed', [
                'type' => $type,
                'parsed_errors' => $parsed['errors'] ?? [],
                'parsed_type' => $parsed['type'] ?? 'NULL',
                'parsed_server' => $parsed['server'] ?? 'NULL',
            ]);

            if (! empty($parsed['errors'])) {
                $errors = array_merge($errors, $parsed['errors']);
            } elseif (! $this->validateBlinkToken($value)) {
                $errors[] = 'Invalid Blink connection string format. Expected: type=blink;ln-address=yourname@blink.sv; or type=blink;server=https://...;api-key=...;wallet-id=...';
            }
        } elseif ($type === 'aqua_descriptor') {
            $returnType = 'aqua_descriptor';
            Log::info('Validating Aqua descriptor', [
                'type' => $type,
            ]);

            $isValid = $this->validateAquaDescriptor($value);
            Log::info('Aqua descriptor validation result', [
                'type' => $type,
                'is_valid' => $isValid,
            ]);

            if (! $isValid) {
                $errors[] = 'Invalid descriptor format. Must be a valid Aqua/Bull (Boltz) watch-only descriptor: ct(slip77(...),elsh(wpkh(...))) or ct(slip77(...),elwpkh(...)). Must not contain private keys.';
            }
        } elseif ($type === 'nwc') {
            $returnType = 'nwc';
            if (! $this->validateNwcUri($value)) {
                $errors[] = 'Invalid NWC connection. Must start with nostr+walletconnect: and include relay= and secret= parameters.';
            }
        } elseif ($type === 'blitz') {
            $returnType = 'blitz';
            $parsed = $this->parseBlitzConnectionString($value);
            if (! empty($parsed['errors'])) {
                $errors[] = 'Invalid Blitz connection. Expected your Blitz Wallet Lightning address (you@blitzwalletapp.com) or type=blitz;ln-address=you;';
            }
        } else {
            Log::error('Unsupported wallet connection type', [
                'type' => $type,
            ]);
            throw new \InvalidArgumentException("Unsupported wallet connection type: {$type}");
        }

        $result = [
            'valid' => empty($errors),
            'type' => $returnType,
            'errors' => $errors,
            'error' => ! empty($errors) ? implode('; ', $errors) : null,
        ];

        Log::info('WalletConnectionValidator::validate result', [
            'type' => $type,
            'valid' => $result['valid'],
            'errors_count' => count($errors),
            'errors' => $errors,
        ]);

        return $result;
    }

    /**
     * Lightning Address for Cashu: local@domain.tld with at least 2 chars after the last dot.
     */
    public function validateCashuLightningAddress(string $value): bool
    {
        return (bool) preg_match('/^[^@\s]+@[^@\s]*\.[^@\s]{2,}$/', trim($value));
    }

    /**
     * Detect Aqua vs Bull Bitcoin from a watch-only descriptor body.
     *
     * @return 'aqua'|'bull'|null
     */
    public function detectAquaBrandFromDescriptor(string $descriptor): ?string
    {
        $body = $this->stripDescriptorChecksum($descriptor);
        if ($body === '') {
            return null;
        }
        if (preg_match('/,\s*elwpkh\s*\(/i', $body)) {
            return 'bull';
        }
        if (preg_match('/,\s*elsh\s*\(\s*wpkh\s*\(/i', $body)) {
            return 'aqua';
        }

        return null;
    }

    /**
     * Resolve wallet brand for aqua_boltz stores from the stored connection.
     *
     * @return 'aqua'|'bull'|null
     */
    public function resolveAquaBoltzBrand(?WalletConnection $connection): ?string
    {
        if (! $connection) {
            return null;
        }

        if ($connection->configuration_source === 'samrock') {
            return 'aqua';
        }

        if ($connection->type !== 'aqua_descriptor') {
            return null;
        }

        try {
            return $this->detectAquaBrandFromDescriptor($connection->reveal()) ?? 'aqua';
        } catch (\Throwable) {
            return 'aqua';
        }
    }
}
