<?php

namespace Tests\Unit;

use App\Services\WalletSecurity\Bolt11;
use kornrunner\Secp256k1;
use Mdanter\Ecc\EccFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vectors from BOLT #11 (lightning/bolts, 11-payment-encoding.md, "Examples"):
 * all are signed by node 03e7156ae33b0a208d0744199163177e909e80176e55d97a2f221ede0f934dd9ad.
 */
class Bolt11Test extends TestCase
{
    public const SPEC_PAYEE = '03e7156ae33b0a208d0744199163177e909e80176e55d97a2f221ede0f934dd9ad';

    public const SPEC_DONATION = 'lnbc1pvjluezsp5zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zygspp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdpl2pkx2ctnv5sxxmmwwd5kgetjypeh2ursdae8g6twvus8g6rfwvs8qun0dfjkxaq9qrsgq357wnc5r2ueh7ck6q93dj32dlqnls087fxdwk8qakdyafkq3yap9us6v52vjjsrvywa6rt52cm9r9zqt8r2t7mlcwspyetp5h2tztugp9lfyql';

    public const SPEC_COFFEE = 'lnbc2500u1pvjluezsp5zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zygspp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqdq5xysxxatsyp3k7enxv4jsxqzpu9qrsgquk0rl77nj30yxdy8j9vdx85fkpmdla2087ne0xh8nhedh8w27kyke0lp53ut353s06fv3qfegext0eh0ymjpf39tuven09sam30g4vgpfna3rh';

    public const SPEC_FALLBACK = 'lnbc20m1pvjluezsp5zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zygshp58yjmdan79s6qqdhdzgynm4zwqd5d7xmw5fk98klysy043l2ahrqspp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypqfppj3a24vwu6r8ejrss3axul8rxldph2q7z99qrsgqz6qsgww34xlatfj6e3sngrwfy3ytkt29d2qttr8qz2mnedfqysuqypgqex4haa2h8fx3wnypranf3pdwyluftwe680jjcfp438u82xqphf75ym';

    /** "Please send 0.00967878534 BTC for a list of items within one week, amount in pico-BTC". */
    public const SPEC_PICO = 'lnbc9678785340p1pwmna7lpp5gc3xfm08u9qy06djf8dfflhugl6p7lgza6dsjxq454gxhj9t7a0sd8dgfkx7cmtwd68yetpd5s9xar0wfjn5gpc8qhrsdfq24f5ggrxdaezqsnvda3kkum5wfjkzmfqf3jkgem9wgsyuctwdus9xgrcyqcjcgpzgfskx6eqf9hzqnteypzxz7fzypfhg6trddjhygrcyqezcgpzfysywmm5ypxxjemgw3hxjmn8yptk7untd9hxwg3q2d6xjcmtv4ezq7pqxgsxzmnyyqcjqmt0wfjjq6t5v4khxsp5zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zyg3zygsxqyjw5qcqp2rzjq0gxwkzc8w6323m55m4jyxcjwmy7stt9hwkwe2qxmy8zpsgg7jcuwz87fcqqeuqqqyqqqqlgqqqqn3qq9q9qrsgqrvgkpnmps664wgkp43l22qsgdw4ve24aca4nymnxddlnp8vh9v2sdxlu5ywdxefsfvm0fq3sesf08uf6q9a2ke0hc9j6z6wlxg5z5kqpu2v9wz';

    /** A real mainnet invoice issued by a different node (fixture for mismatch tests). */
    public const OTHER_INVOICE = 'lnbc5470n1p4zd8y5pp5w5qfq3uxrufuqe5q6hue0w34ewfnkkpfpeaf6vtt7aljee962qxqdps2pskjepqw3hjq4r0wqs9xar0wfjjq2z0wfjx2u3qf9zr5gpfcqzpuxqrrw5sp5gc6jkzyqr3lpxr7fqqtuhg738f7jkg9m4mv7j54tas8jl80p46ms9qxpqysgq44hv2q2qt2zm0fu3fa3y2fn0c3hp6m6zrrzympd26f4e9cswpv6naut8ev6cth27cdxa7ufq50m6uvy7xxrcsks4qyl07jwchdue3wcqq5dljq';

    public const OTHER_PAYEE = '029fc6217803748cc8fb6b041acbe81e6e295a914487dcea7e5d7e141b64883826';

    #[Test]
    public function recovers_the_payee_from_the_spec_vectors(): void
    {
        foreach ([self::SPEC_DONATION, self::SPEC_COFFEE, self::SPEC_FALLBACK] as $invoice) {
            $decoded = Bolt11::decode($invoice);
            $this->assertSame(self::SPEC_PAYEE, $decoded['payee']);
            $this->assertSame('recovered', $decoded['payee_source']);
            $this->assertSame('bc', $decoded['network']);
        }
        $this->assertSame(self::OTHER_PAYEE, Bolt11::payee(self::OTHER_INVOICE));
    }

    #[Test]
    public function parses_amount_description_and_payment_hash(): void
    {
        $donation = Bolt11::decode(self::SPEC_DONATION);
        $this->assertNull($donation['amount_msat']);
        $this->assertSame('Please consider supporting this project', $donation['description']);
        $this->assertSame('0001020304050607080900010203040506070809000102030405060708090102', $donation['payment_hash']);

        $coffee = Bolt11::decode(self::SPEC_COFFEE);
        $this->assertSame(250_000_000, $coffee['amount_msat']);
        $this->assertSame(60, $coffee['expiry']);

        $this->assertSame(2_000_000_000, Bolt11::decode(self::SPEC_FALLBACK)['amount_msat']);
        // 9678785340 pico-BTC = 0.00967878534 BTC = 967 878 534 msat (p is 10^-12).
        $pico = Bolt11::decode(self::SPEC_PICO);
        $this->assertSame(967_878_534, $pico['amount_msat']);
        $this->assertSame(self::SPEC_PAYEE, $pico['payee']);
        $this->assertSame(547_000, Bolt11::decode(self::OTHER_INVOICE)['amount_msat']);
    }

    #[Test]
    public function an_explicit_payee_field_is_only_accepted_when_it_is_the_signer(): void
    {
        $privateKey = str_repeat('11', 32);
        $pubkey = self::compressedPubkey($privateKey);

        $signed = self::buildInvoice($privateKey, $pubkey);
        $decoded = Bolt11::decode($signed);
        $this->assertSame($pubkey, $decoded['payee']);
        $this->assertSame('field', $decoded['payee_source']);
        $this->assertSame(self::compressedPubkey($privateKey), Bolt11::decode(self::buildInvoice($privateKey, null))['payee']);

        // Valid checksum, but `n` names a node that did not sign the invoice.
        $forged = self::buildInvoice($privateKey, self::SPEC_PAYEE);
        $this->assertNull(Bolt11::payee($forged));
        $this->expectException(\InvalidArgumentException::class);
        Bolt11::decode($forged);
    }

    #[Test]
    public function accepts_uppercase_and_lightning_prefix_and_rejects_garbage(): void
    {
        $this->assertSame(self::SPEC_PAYEE, Bolt11::payee(strtoupper(self::SPEC_COFFEE)));
        $this->assertSame(self::SPEC_PAYEE, Bolt11::payee('lightning:'.self::SPEC_COFFEE));

        $this->assertNull(Bolt11::payee('bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'));
        $this->assertNull(Bolt11::payee(substr(self::SPEC_COFFEE, 0, -1).'a'), 'checksum failure');
        $this->assertNull(Bolt11::payee(''));
        $this->assertNull(Bolt11::payee('lnbc1'));
    }

    /**
     * Minimal BOLT11 encoder for fixtures: lnbc, amount-less, one payment hash,
     * optional `n`, signed by $privateKey (hex) with a recoverable signature.
     */
    private static function buildInvoice(string $privateKey, ?string $nPubkey): string
    {
        $hrp = 'lnbc';
        $words = self::intToWords(1_700_000_000, 7);
        $paymentHash = str_repeat("\x42", 32);
        $words = [...$words, 1, 1, 20, ...self::bytesToWords($paymentHash)]; // p, len 52
        if ($nPubkey !== null) {
            $words = [...$words, 19, 1, 21, ...self::bytesToWords(hex2bin($nPubkey))]; // n, len 53
        }
        $preimage = $hrp.self::wordsToBytesPadded($words);
        $hash = hash('sha256', $preimage);
        $signature = (new Secp256k1)->sign($hash, $privateKey);
        $r = str_pad(gmp_strval($signature->getR(), 16), 64, '0', STR_PAD_LEFT);
        $s = str_pad(gmp_strval($signature->getS(), 16), 64, '0', STR_PAD_LEFT);
        $sigBytes = hex2bin($r.$s).chr($signature->getRecoveryParam());
        $words = [...$words, ...self::bytesToWords($sigBytes)]; // 65 bytes = 104 words exactly

        return self::bech32Encode($hrp, $words);
    }

    private static function compressedPubkey(string $privateKey): string
    {
        $generator = EccFactory::getSecgCurves()->generator256k1();
        $point = $generator->mul(gmp_init($privateKey, 16));

        return (gmp_cmp(gmp_mod($point->getY(), 2), 0) === 0 ? '02' : '03').str_pad(gmp_strval($point->getX(), 16), 64, '0', STR_PAD_LEFT);
    }

    /** @return list<int> */
    private static function intToWords(int $value, int $count): array
    {
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $out[] = ($value >> (5 * $i)) & 31;
        }

        return $out;
    }

    /** @return list<int> */
    private static function bytesToWords(string $bytes): array
    {
        $acc = 0;
        $bits = 0;
        $out = [];
        foreach (str_split($bytes) as $byte) {
            $acc = (($acc << 8) | ord($byte)) & 0xFFFFFFFF;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out[] = ($acc >> $bits) & 31;
            }
        }
        if ($bits > 0) {
            $out[] = ($acc << (5 - $bits)) & 31;
        }

        return $out;
    }

    /** @param list<int> $words */
    private static function wordsToBytesPadded(array $words): string
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
        if ($bits > 0) {
            $out .= chr(($acc << (8 - $bits)) & 0xFF);
        }

        return $out;
    }

    /** @param list<int> $words */
    private static function bech32Encode(string $hrp, array $words): string
    {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $expand = [];
        foreach (str_split($hrp) as $c) {
            $expand[] = ord($c) >> 5;
        }
        $expand[] = 0;
        foreach (str_split($hrp) as $c) {
            $expand[] = ord($c) & 31;
        }
        $values = [...$expand, ...$words, 0, 0, 0, 0, 0, 0];
        $chk = 1;
        $gen = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3];
        foreach ($values as $v) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= $gen[$i];
                }
            }
        }
        $polymod = $chk ^ 1;
        $checksum = [];
        for ($i = 0; $i < 6; $i++) {
            $checksum[] = ($polymod >> (5 * (5 - $i))) & 31;
        }
        $out = $hrp.'1';
        foreach ([...$words, ...$checksum] as $w) {
            $out .= $charset[$w];
        }

        return $out;
    }
}
