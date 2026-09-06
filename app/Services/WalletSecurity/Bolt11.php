<?php

namespace App\Services\WalletSecurity;

use Mdanter\Ecc\EccFactory;

/**
 * Minimal BOLT11 decoder for payee attestation: which node signed the
 * invoice a payment settled to. Parses the bech32 envelope, the tagged
 * fields we care about and recovers the payee public key from the
 * signature (or takes the explicit `n` field when present).
 */
final class Bolt11
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    private const GENERATOR = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3];

    /**
     * @return array{
     *   hrp: string,
     *   network: string,
     *   amount_msat: int|null,
     *   timestamp: int,
     *   payment_hash: string|null,
     *   description: string|null,
     *   expiry: int,
     *   payee: string,
     *   payee_source: 'field'|'recovered'
     * }
     *
     * @throws \InvalidArgumentException on malformed input
     */
    public static function decode(string $invoice): array
    {
        $invoice = trim($invoice);
        if (str_starts_with(strtolower($invoice), 'lightning:')) {
            $invoice = substr($invoice, 10);
        }
        [$hrp, $words] = self::bech32Decode($invoice);
        if (! str_starts_with($hrp, 'ln')) {
            throw new \InvalidArgumentException('Not a Lightning invoice');
        }
        if (count($words) < 7 + 104) {
            throw new \InvalidArgumentException('Invoice too short');
        }

        $sigWords = array_slice($words, -104);
        $data = array_slice($words, 0, -104);
        $sig = self::wordsToBytes($sigWords, false);
        if (strlen($sig) !== 65) {
            throw new \InvalidArgumentException('Malformed signature');
        }

        $timestamp = 0;
        for ($i = 0; $i < 7; $i++) {
            $timestamp = ($timestamp << 5) | $data[$i];
        }

        $fields = ['payment_hash' => null, 'description' => null, 'expiry' => 3600, 'payee_field' => null];
        $pos = 7;
        while ($pos + 3 <= count($data)) {
            $type = $data[$pos];
            $len = ($data[$pos + 1] << 5) | $data[$pos + 2];
            $body = array_slice($data, $pos + 3, $len);
            if (count($body) !== $len) {
                throw new \InvalidArgumentException('Truncated tagged field');
            }
            $pos += 3 + $len;
            switch ($type) {
                case 1: // p - payment hash
                    if ($len === 52) {
                        $fields['payment_hash'] = bin2hex(self::wordsToBytes($body, false));
                    }
                    break;
                case 13: // d - description
                    $fields['description'] = self::wordsToBytes($body, false);
                    break;
                case 6: // x - expiry
                    $fields['expiry'] = self::wordsToInt($body);
                    break;
                case 19: // n - payee node id
                    if ($len === 53) {
                        $fields['payee_field'] = bin2hex(self::wordsToBytes($body, false));
                    }
                    break;
            }
        }

        [$network, $amountMsat] = self::parseHrp($hrp);

        $preimage = $hrp.self::wordsToBytes($data, true);
        $hash = hash('sha256', $preimage, true);
        // The signer is always recovered from the signature; an explicit `n`
        // field is only accepted when it is that signer (a forged or tampered
        // `n` must never become the payee we attest against).
        $recovered = self::recoverPubKey($hash, substr($sig, 0, 32), substr($sig, 32, 32), ord($sig[64]));
        $payee = $recovered;
        $source = 'recovered';
        if ($fields['payee_field'] !== null) {
            if (! hash_equals($recovered, $fields['payee_field'])) {
                throw new \InvalidArgumentException('Payee field does not match the invoice signature');
            }
            $source = 'field';
        }

        return [
            'hrp' => $hrp,
            'network' => $network,
            'amount_msat' => $amountMsat,
            'timestamp' => $timestamp,
            'payment_hash' => $fields['payment_hash'],
            'description' => $fields['description'],
            'expiry' => $fields['expiry'],
            'payee' => $payee,
            'payee_source' => $source,
        ];
    }

    /** Payee node id (33-byte compressed, hex) or null when the string is not a valid invoice. */
    public static function payee(string $invoice): ?string
    {
        try {
            return self::decode($invoice)['payee'];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: string, 1: int|null} network, amount in msat */
    private static function parseHrp(string $hrp): array
    {
        if (preg_match('/^ln([a-z]+?)(\d+)?([munp])?$/', $hrp, $m) !== 1) {
            throw new \InvalidArgumentException('Malformed human-readable part');
        }
        $network = $m[1];
        $amount = $m[2] ?? '';
        if ($amount === '') {
            return [$network, null];
        }
        // amount is in BTC units scaled by the multiplier; convert to msat exactly.
        $msatPerBtc = '100000000000';
        $divisor = match ($m[3] ?? '') {
            '' => '1',
            'm' => '1000',
            'u' => '1000000',
            'n' => '1000000000',
            'p' => '1000000000000',
        };
        $msat = bcdiv(bcmul($amount, $msatPerBtc, 0), $divisor, 0);

        return [$network, (int) $msat];
    }

    /** @return array{0: string, 1: list<int>} */
    private static function bech32Decode(string $str): array
    {
        if ($str !== strtolower($str) && $str !== strtoupper($str)) {
            throw new \InvalidArgumentException('Mixed-case bech32');
        }
        $str = strtolower($str);
        $split = strrpos($str, '1');
        if ($split === false || $split < 1 || $split + 7 > strlen($str)) {
            throw new \InvalidArgumentException('Missing separator');
        }
        $hrp = substr($str, 0, $split);
        $words = [];
        for ($i = $split + 1, $n = strlen($str); $i < $n; $i++) {
            $v = strpos(self::CHARSET, $str[$i]);
            if ($v === false) {
                throw new \InvalidArgumentException('Invalid character');
            }
            $words[] = $v;
        }
        if (self::polymod(array_merge(self::hrpExpand($hrp), $words)) !== 1) {
            throw new \InvalidArgumentException('Bad checksum');
        }

        return [$hrp, array_slice($words, 0, -6)];
    }

    /** @return list<int> */
    private static function hrpExpand(string $hrp): array
    {
        $out = [];
        $len = strlen($hrp);
        for ($i = 0; $i < $len; $i++) {
            $out[] = ord($hrp[$i]) >> 5;
        }
        $out[] = 0;
        for ($i = 0; $i < $len; $i++) {
            $out[] = ord($hrp[$i]) & 31;
        }

        return $out;
    }

    /** @param list<int> $values */
    private static function polymod(array $values): int
    {
        $chk = 1;
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= self::GENERATOR[$i];
                }
            }
        }

        return $chk;
    }

    /**
     * 5-bit words to bytes. With $pad the trailing partial byte is zero-padded
     * (signature preimage rule); without it, leftover bits are dropped.
     *
     * @param  list<int>  $words
     */
    private static function wordsToBytes(array $words, bool $pad): string
    {
        $acc = 0;
        $bits = 0;
        $out = '';
        foreach ($words as $w) {
            $acc = (($acc << 5) | $w) & 0xFFFFFFFF;
            $bits += 5;
            while ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($acc >> $bits) & 0xFF);
            }
        }
        if ($pad && $bits > 0) {
            $out .= chr(($acc << (8 - $bits)) & 0xFF);
        }

        return $out;
    }

    /** @param list<int> $words */
    private static function wordsToInt(array $words): int
    {
        $n = 0;
        foreach ($words as $w) {
            $n = ($n << 5) | $w;
        }

        return $n;
    }

    /** ECDSA public key recovery on secp256k1 (compressed hex). */
    private static function recoverPubKey(string $hash, string $r, string $s, int $recId): string
    {
        if ($recId < 0 || $recId > 3) {
            throw new \InvalidArgumentException('Invalid recovery id');
        }
        $generator = EccFactory::getSecgCurves()->generator256k1();
        $curve = $generator->getCurve();
        $n = $generator->getOrder();
        $p = $curve->getPrime();

        $rN = gmp_init(bin2hex($r), 16);
        $sN = gmp_init(bin2hex($s), 16);
        $e = gmp_init(bin2hex($hash), 16);
        if (gmp_cmp($rN, 1) < 0 || gmp_cmp($rN, $n) >= 0 || gmp_cmp($sN, 1) < 0 || gmp_cmp($sN, $n) >= 0) {
            throw new \InvalidArgumentException('Signature out of range');
        }

        $x = gmp_add($rN, gmp_mul(gmp_init($recId >> 1), $n));
        if (gmp_cmp($x, $p) >= 0) {
            throw new \InvalidArgumentException('Recovery x out of range');
        }
        $y = $curve->recoverYfromX(($recId & 1) === 1, $x);
        $R = $curve->getPoint($x, $y, $n);

        // Q = r^-1 (s*R - e*G)
        $rInv = gmp_invert($rN, $n);
        $sR = $R->mul($sN);
        $eG = $generator->mul(gmp_mod($e, $n));
        $negEG = $curve->getPoint($eG->getX(), gmp_sub($p, $eG->getY()), $n);
        $Q = $sR->add($negEG)->mul($rInv);
        if ($Q->isInfinity()) {
            throw new \InvalidArgumentException('Recovered point at infinity');
        }

        $prefix = gmp_cmp(gmp_mod($Q->getY(), 2), 0) === 0 ? '02' : '03';

        return $prefix.str_pad(gmp_strval($Q->getX(), 16), 64, '0', STR_PAD_LEFT);
    }
}
