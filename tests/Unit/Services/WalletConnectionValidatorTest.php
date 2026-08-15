<?php

namespace Tests\Unit\Services;

use App\Services\WalletConnectionValidator;
use Tests\TestCase;

class WalletConnectionValidatorTest extends TestCase
{
    protected WalletConnectionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WalletConnectionValidator;
    }

    public function test_valid_blink_connection_string(): void
    {
        // Valid Blink connection string format
        $connectionString = 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456';

        $result = $this->validator->validate('blink', $connectionString);

        $this->assertTrue($result['valid']);
        $this->assertEquals('blink', $result['type']);
    }

    public function test_valid_blink_ln_address_connection_string(): void
    {
        $result = $this->validator->validate('blink', 'type=blink;ln-address=satoshi@blink.sv;');

        $this->assertTrue($result['valid']);
        $this->assertEquals('blink', $result['type']);
    }

    public function test_blink_username_key_defaults_to_blink_sv_domain(): void
    {
        $parsed = $this->validator->parseBlinkConnectionString('type=blink;username=satoshi;');

        $this->assertEmpty($parsed['errors']);
        $this->assertSame('ln_address', $parsed['variant']);
        $this->assertSame('satoshi@blink.sv', $parsed['ln_address']);
    }

    public function test_bare_blink_lightning_address_is_valid_blink_secret(): void
    {
        $result = $this->validator->validate('blink', 'satoshi@blink.sv');

        $this->assertTrue($result['valid']);
    }

    public function test_bare_lightning_address_at_other_domain_is_rejected(): void
    {
        $result = $this->validator->validate('blink', 'satoshi@example.com');

        $this->assertFalse($result['valid']);
    }

    public function test_blink_ln_address_at_other_domain_is_rejected(): void
    {
        $result = $this->validator->validate('blink', 'type=blink;ln-address=satoshi@example.com;');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_blink_ln_address_with_empty_local_part_is_rejected(): void
    {
        $result = $this->validator->validate('blink', 'type=blink;ln-address=@blink.sv;');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_blink_variant_detection(): void
    {
        $this->assertSame(
            'api_key',
            $this->validator->blinkVariant('type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456')
        );
        $this->assertSame('ln_address', $this->validator->blinkVariant('type=blink;ln-address=satoshi@blink.sv;'));
        $this->assertSame('ln_address', $this->validator->blinkVariant('satoshi@blink.sv'));
        $this->assertNull($this->validator->blinkVariant('not a connection string'));
    }

    public function test_formats_btcpay_blink_connection_string(): void
    {
        $this->assertSame(
            'type=blink;ln-address=satoshi@blink.sv;',
            $this->validator->formatBtcpayBlinkConnectionString('satoshi@blink.sv')
        );
        $this->assertSame(
            'type=blink;ln-address=satoshi@blink.sv;',
            $this->validator->formatBtcpayBlinkConnectionString('type=blink;username=satoshi;')
        );

        $apiKeyString = 'type=blink;server=https://api.blink.sv/graphql;api-key=blink_test123;wallet-id=wallet456';
        $this->assertSame($apiKeyString, $this->validator->formatBtcpayBlinkConnectionString($apiKeyString));
    }

    public function test_invalid_blink_connection_string_missing_parts(): void
    {
        // Missing required parts
        $connectionString = 'type=blink;server=https://api.blink.sv/graphql';

        $result = $this->validator->validate('blink', $connectionString);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_invalid_blink_connection_string_wrong_format(): void
    {
        $connectionString = 'invalid:format';

        $result = $this->validator->validate('blink', $connectionString);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_valid_aqua_boltz_descriptor(): void
    {
        $descriptor = 'ct(slip77(xpub6D4BDPcP2GT577Vvch3Reb8P8CH),elsh(wpkh(xpub6E8...)))';

        $result = $this->validator->validate('aqua_descriptor', $descriptor);

        $this->assertTrue($result['valid']);
        $this->assertEquals('aqua_descriptor', $result['type']);
    }

    public function test_valid_bull_bitcoin_descriptor_with_checksum(): void
    {
        $descriptor = 'ct(slip77(5bd88956b5c0782248ad31f92d24712cff8c4cd761759dd629c08e2b60c9e6a7),elwpkh([0eb9c7d5/84h/1776h/0h]xpub6CE9h9pKdmMzM11sbeuRA1AAnmL3k6PWNzPDNw2gAGHMthvbVChXbhAADsKanndLJ7neMMBeC3oEA4uqadycLz8xYQbCdMF2NoMVZjJU7rB/<0;1>/*))#hw28w0rx';

        $result = $this->validator->validate('aqua_descriptor', $descriptor);

        $this->assertTrue($result['valid']);
    }

    public function test_valid_nwc_uri(): void
    {
        $uri = 'nostr+walletconnect://abc1234567890123456789012345678901234567890123456789012345678901234?relay=wss%3A%2F%2Frelay.example.com&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

        $result = $this->validator->validate('nwc', $uri);

        $this->assertTrue($result['valid']);
        $this->assertSame('nwc', $result['type']);
    }

    public function test_formats_btcpay_nwc_connection_string(): void
    {
        $uri = 'nostr+walletconnect:abc1234567890123456789012345678901234567890123456789012345678901234?relay=wss%3A%2F%2Frelay.example.com&secret=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

        $formatted = $this->validator->formatBtcpayNwcConnectionString($uri);

        $this->assertStringStartsWith('type=nwc;key=nostr+walletconnect:', $formatted);
    }

    public function test_rejects_descriptor_without_ct_slip77(): void
    {
        $descriptor = 'wpkh(xpub6D4BDPcP2GT577Vvch3Reb8P8CH)';

        $this->assertFalse($this->validator->validateAquaDescriptor($descriptor));
    }

    public function test_rejects_descriptor_with_trailing_garbage(): void
    {
        $descriptor = 'ct(slip77(xpub6D4BDPcP2GT577Vvch3Reb8P8CH),elsh(wpkh(xpub6E8...))))extra';

        $this->assertFalse($this->validator->validateAquaDescriptor($descriptor));
    }

    public function test_valid_samrock_placeholder_shape(): void
    {
        $seed = hash('sha256', 'samrock:019e6827-e61c-7000-8000-000000000001');
        $slip77 = substr($seed, 0, 64);
        $fp = substr($seed, 0, 8);
        $xpubBody = 'xpub'.str_pad(substr($seed, 8, 100), 100, '0');
        $descriptor = "ct(slip77({$slip77}),elsh(wpkh([{$fp}/84h/0h/0h]{$xpubBody}/0/*)))";

        $this->assertTrue($this->validator->validateAquaDescriptor($descriptor));
    }

    public function test_validation_rejects_empty_string(): void
    {
        $result = $this->validator->validate('blink', '');

        $this->assertFalse($result['valid']);
    }

    public function test_validation_rejects_unsupported_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->validator->validate('unsupported_type', 'test');
    }

    public function test_detect_aqua_brand_from_descriptor(): void
    {
        $descriptor = 'ct(slip77(xpub6D4BDPcP2GT577Vvch3Reb8P8CH),elsh(wpkh(xpub6E8...)))';

        $this->assertSame('aqua', $this->validator->detectAquaBrandFromDescriptor($descriptor));
    }

    public function test_detect_bull_brand_from_descriptor(): void
    {
        $descriptor = 'ct(slip77(5bd88956b5c0782248ad31f92d24712cff8c4cd761759dd629c08e2b60c9e6a7),elwpkh([0eb9c7d5/84h/1776h/0h]xpub6CE9h9pKdmMzM11sbeuRA1AAnmL3k6PWNzPDNw2gAGHMthvbVChXbhAADsKanndLJ7neMMBeC3oEA4uqadycLz8xYQbCdMF2NoMVZjJU7rB/<0;1>/*))#hw28w0rx';

        $this->assertSame('bull', $this->validator->detectAquaBrandFromDescriptor($descriptor));
    }

    public function test_bare_blitz_lightning_address_detection(): void
    {
        $this->assertTrue($this->validator->isBareBlitzLightningAddress('satoshi@blitzwalletapp.com'));
        $this->assertTrue($this->validator->isBareBlitzLightningAddress('Satoshi@BlitzWalletApp.com'));
        $this->assertFalse($this->validator->isBareBlitzLightningAddress('satoshi@blink.sv'));
        $this->assertFalse($this->validator->isBareBlitzLightningAddress('satoshi@getalby.com'));
    }

    public function test_blitz_connection_string_parsing_and_formatting(): void
    {
        $this->assertSame(
            'type=blitz;ln-address=satoshi@blitzwalletapp.com;',
            $this->validator->formatBtcpayBlitzConnectionString('satoshi@blitzwalletapp.com')
        );
        // Bare username defaults to the blitzwalletapp.com domain - mirrors the plugin.
        $this->assertSame(
            'type=blitz;ln-address=satoshi@blitzwalletapp.com;',
            $this->validator->formatBtcpayBlitzConnectionString('type=blitz;ln-address=satoshi')
        );
        $this->assertSame(
            'type=blitz;ln-address=satoshi@blitzwalletapp.com;',
            $this->validator->formatBtcpayBlitzConnectionString('type=blitz;username=satoshi@blitzwalletapp.com;')
        );
    }

    public function test_bare_flash_lightning_address_detection(): void
    {
        $this->assertTrue($this->validator->isBareFlashLightningAddress('satoshi@flashapp.me'));
        $this->assertTrue($this->validator->isBareFlashLightningAddress('Satoshi@FlashApp.me'));
        $this->assertFalse($this->validator->isBareFlashLightningAddress('satoshi@blitzwalletapp.com'));
        $this->assertFalse($this->validator->isBareFlashLightningAddress('satoshi@getalby.com'));
    }

    public function test_flash_connection_string_parsing_and_formatting(): void
    {
        $this->assertSame(
            'type=flash;ln-address=satoshi@flashapp.me;',
            $this->validator->formatBtcpayFlashConnectionString('satoshi@flashapp.me')
        );
        $this->assertSame(
            'type=flash;ln-address=satoshi@flashapp.me;',
            $this->validator->formatBtcpayFlashConnectionString('type=flash;ln-address=satoshi')
        );
    }

    public function test_flash_validation(): void
    {
        $this->assertTrue($this->validator->validate('flash', 'satoshi@flashapp.me')['valid']);
        $this->assertTrue($this->validator->validate('flash', 'type=FLASH;ln-address=satoshi;')['valid']);
        $this->assertFalse($this->validator->validate('flash', 'type=flash;server=https://x;')['valid']);
        $this->assertFalse($this->validator->validate('flash', 'satoshi@getalby.com')['valid']);
        $this->assertFalse($this->validator->validate('flash', 'type=flash;ln-address=user@otherwallet.example;')['valid']);
    }

    public function test_lnaddress_parsing_accepts_any_full_address_and_rejects_bare_usernames(): void
    {
        $parsed = $this->validator->parseLnAddressConnectionString('merchant@anywallet.example');
        $this->assertEmpty($parsed['errors']);
        $this->assertSame('merchant@anywallet.example', $parsed['ln_address']);

        $parsed = $this->validator->parseLnAddressConnectionString('type=lnaddress;ln-address=merchant@coinos.io;');
        $this->assertEmpty($parsed['errors']);
        $this->assertSame('merchant@coinos.io', $parsed['ln_address']);

        // No default domain for the universal type - a bare username is an error.
        $parsed = $this->validator->parseLnAddressConnectionString('type=lnaddress;ln-address=merchant;');
        $this->assertNotEmpty($parsed['errors']);
    }

    public function test_lnaddress_formatting_expands_bare_addresses(): void
    {
        $this->assertSame(
            'type=lnaddress;ln-address=merchant@coinos.io;',
            $this->validator->formatBtcpayLnAddressConnectionString('merchant@coinos.io')
        );
        $this->assertSame(
            'type=lnaddress;ln-address=merchant@coinos.io;',
            $this->validator->formatBtcpayLnAddressConnectionString('type=lnaddress;ln-address=merchant@coinos.io;')
        );
    }

    public function test_lnaddress_validation(): void
    {
        $this->assertTrue($this->validator->validate('lnaddress', 'merchant@anywallet.example')['valid']);
        $this->assertTrue($this->validator->validate('lnaddress', 'type=LNADDRESS;ln-address=merchant@coinos.io;')['valid']);
        $this->assertFalse($this->validator->validate('lnaddress', 'type=lnaddress;ln-address=merchant;')['valid']);
        $this->assertFalse($this->validator->validate('lnaddress', 'type=blitz;ln-address=merchant;')['valid']);
    }

    public function test_lnaddress_brand_mapping_is_curated_and_case_insensitive(): void
    {
        $this->assertSame('blitz', $this->validator->lnAddressBrandForAddress('a@blitzwalletapp.com'));
        $this->assertSame('flash', $this->validator->lnAddressBrandForAddress('a@FlashApp.ME'));
        $this->assertSame('coinos', $this->validator->lnAddressBrandForAddress('a@coinos.io'));
        $this->assertNull($this->validator->lnAddressBrandForAddress('a@getalby.com'));
        $this->assertNull($this->validator->lnAddressBrandForAddress('not-an-address'));
    }

    public function test_lnaddress_secret_derives_the_cashu_fallback_address(): void
    {
        $this->assertSame(
            'merchant@anywallet.example',
            $this->validator->deriveLightningAddressFromSecret('lnaddress', 'type=lnaddress;ln-address=merchant@anywallet.example;')
        );
        $this->assertNull($this->validator->deriveLightningAddressFromSecret('lnaddress', 'type=lnaddress;ln-address=merchant;'));
    }

    public function test_derive_lightning_address_from_secret(): void
    {
        $this->assertSame('satoshi@blink.sv',
            $this->validator->deriveLightningAddressFromSecret('blink', 'type=blink;ln-address=satoshi@blink.sv;'));
        $this->assertSame('satoshi@blitzwalletapp.com',
            $this->validator->deriveLightningAddressFromSecret('blitz', 'satoshi@blitzwalletapp.com'));
        $this->assertSame('satoshi@flashapp.me',
            $this->validator->deriveLightningAddressFromSecret('flash', 'type=flash;ln-address=satoshi;'));
        $this->assertNull($this->validator->deriveLightningAddressFromSecret('blink',
            'type=blink;server=https://api.blink.sv/graphql;api-key=k;wallet-id=w'));
        $this->assertNull($this->validator->deriveLightningAddressFromSecret('nwc', 'nostr+walletconnect:abc'));
    }

    public function test_blitz_validation(): void
    {
        $this->assertTrue($this->validator->validate('blitz', 'satoshi@blitzwalletapp.com')['valid']);
        $this->assertTrue($this->validator->validate('blitz', 'type=blitz;ln-address=satoshi;')['valid']);
        $this->assertTrue($this->validator->validate('blitz', 'type=BLITZ;ln-address=satoshi;')['valid']);
        $this->assertFalse($this->validator->validate('blitz', 'type=blitz;server=https://x;')['valid']);
        $this->assertFalse($this->validator->validate('blitz', 'satoshi@getalby.com')['valid']);
        // Other domains are not Blitz secrets - they belong to the Cashu routing path.
        $this->assertFalse($this->validator->validate('blitz', 'type=blitz;ln-address=user@otherwallet.example;')['valid']);
    }
}
