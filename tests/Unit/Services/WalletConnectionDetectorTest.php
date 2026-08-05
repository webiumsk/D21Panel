<?php

namespace Tests\Unit\Services;

use App\Services\WalletConnectionDetector;
use App\Services\WalletConnectionValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WalletConnectionDetectorTest extends TestCase
{
    protected WalletConnectionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new WalletConnectionDetector(new WalletConnectionValidator);
    }

    #[Test]
    public function it_detects_nwc_uri(): void
    {
        $input = 'nostr+walletconnect://abc1234567890123456789012345678901234567890123456789012345678901234?relay=wss%3A%2F%2Frelay.example.com&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

        $result = $this->detector->detect($input);

        $this->assertSame('nwc', $result['kind']);
        $this->assertSame('nwc', $result['connection_type']);
        $this->assertSame('high', $result['confidence']);
    }

    #[Test]
    public function it_detects_blink_connection_string(): void
    {
        $input = 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_test;wallet-id=wallet-1';

        $result = $this->detector->detect($input);

        $this->assertSame('blink', $result['kind']);
        $this->assertSame('blink', $result['connection_type']);
    }

    #[Test]
    public function it_detects_blink_ln_address_connection_string(): void
    {
        $result = $this->detector->detect('type=blink;ln-address=satoshi@blink.sv;');

        $this->assertSame('blink', $result['kind']);
        $this->assertSame('blink', $result['connection_type']);
    }

    #[Test]
    public function it_detects_bare_blink_address_as_blink_not_cashu(): void
    {
        $result = $this->detector->detect('satoshi@blink.sv');

        $this->assertSame('blink', $result['kind']);
        $this->assertSame('type=blink;ln-address=satoshi@blink.sv;', $result['normalized_secret']);
        $this->assertNull($result['cashu_lightning_address']);
    }

    #[Test]
    public function it_detects_bare_blitz_address_as_blitz_not_cashu(): void
    {
        $result = $this->detector->detect('satoshi@blitzwalletapp.com');

        $this->assertSame('blitz', $result['kind']);
        $this->assertSame('blitz', $result['connection_type']);
        $this->assertSame('blitz', $result['store_wallet_type']);
        $this->assertSame('type=blitz;ln-address=satoshi@blitzwalletapp.com;', $result['normalized_secret']);
        $this->assertNull($result['cashu_lightning_address']);
    }

    #[Test]
    public function it_detects_blitz_connection_string_with_bare_username(): void
    {
        $result = $this->detector->detect('type=blitz;ln-address=satoshi');

        $this->assertSame('blitz', $result['kind']);
        $this->assertSame('type=blitz;ln-address=satoshi@blitzwalletapp.com;', $result['normalized_secret']);
    }

    #[Test]
    public function it_routes_other_lightning_addresses_to_cashu_not_blitz(): void
    {
        $result = $this->detector->detect('satoshi@getalby.com');

        $this->assertSame('cashu', $result['kind']);
        $this->assertSame('satoshi@getalby.com', $result['cashu_lightning_address']);
    }

    #[Test]
    public function it_detects_bull_descriptor(): void
    {
        $input = 'ct(slip77(5bd88956b5c0782248ad31f92d24712cff8c4cd761759dd629c08e2b60c9e6a7),elwpkh([0eb9c7d5/84h/1776h/0h]xpub6CE9h9pKdmMzM11sbeuRA1AAnmL3k6PWNzPDNw2gAGHMthvbVChXbhAADsKanndLJ7neMMBeC3oEA4uqadycLz8xYQbCdMF2NoMVZjJU7rB/<0;1>/*))';

        $result = $this->detector->detect($input);

        $this->assertSame('aqua_descriptor', $result['kind']);
        $this->assertSame('bull', $result['brand']);
    }

    #[Test]
    public function it_detects_cashu_mint_url(): void
    {
        $result = $this->detector->detect("https://mint.example.com\nuser@example.com");

        $this->assertSame('cashu', $result['kind']);
        $this->assertSame('https://mint.example.com', $result['cashu_mint_url']);
        $this->assertSame('user@example.com', $result['cashu_lightning_address']);
    }

    #[Test]
    public function it_detects_cashu_lightning_address_only(): void
    {
        $result = $this->detector->detect('merchant@coinos.io');

        $this->assertSame('cashu', $result['kind']);
        $this->assertNull($result['cashu_mint_url']);
        $this->assertSame('merchant@coinos.io', $result['cashu_lightning_address']);
    }

    #[Test]
    public function it_does_not_detect_incomplete_lightning_address_as_cashu(): void
    {
        $result = $this->detector->detect('satflux@c');

        $this->assertSame('unknown', $result['kind']);
    }

    #[Test]
    public function it_detects_cashu_wallet_nwc_from_minibits_as_incompatible(): void
    {
        $input = 'nostr+walletconnect://abc1234567890123456789012345678901234567890123456789012345678901234'
            .'?relay=wss%3A%2F%2Frelay.minibits.cash&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
            .'&lud16=merchant%40minibits.cash';

        $result = $this->detector->detect($input);

        $this->assertSame('cashu_wallet_nwc', $result['kind']);
        $this->assertNull($result['connection_type']);
        $this->assertSame('merchant@minibits.cash', $result['cashu_lightning_address']);
    }

    #[Test]
    public function it_rejects_cashu_wallet_nwc_in_validator(): void
    {
        $validator = new WalletConnectionValidator;
        $input = 'nostr+walletconnect://abc1234567890123456789012345678901234567890123456789012345678901234'
            .'?relay=wss%3A%2F%2Frelay.minibits.cash&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

        $this->assertFalse($validator->validateNwcUri($input));
    }
}
