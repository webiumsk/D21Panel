<?php

namespace Tests\Unit;

use App\Services\WalletSecurity\Bolt11;
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
        $this->assertSame(547_000, Bolt11::decode(self::OTHER_INVOICE)['amount_msat']);
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
}
