<?php

namespace Tests\Unit;

use App\Models\WalletConnection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WalletConnectionMaskedSecretTest extends TestCase
{
    #[Test]
    public function connection_string_keeps_type_and_lightning_address_and_masks_credentials(): void
    {
        $masked = WalletConnection::maskSecret(
            'type=blink;ln-address=satflux@blink.sv;api-key=blink_AbCdEfGhIjKlMnOpQrStUvWx;wallet-id=0f3c9e2a-11aa-4bbb-8ccc-1234567890ab',
        );

        $this->assertSame(
            'type=blink;ln-address=satflux@blink.sv;api-key=****UvWx;wallet-id=****90ab;',
            $masked,
        );
    }

    #[Test]
    public function lnaddress_connection_string_is_fully_readable(): void
    {
        $this->assertSame(
            'type=lnaddress;ln-address=satflux@coinos.io;',
            WalletConnection::maskSecret('type=lnaddress;ln-address=satflux@coinos.io;'),
        );
    }

    #[Test]
    public function short_credentials_are_fully_starred(): void
    {
        $this->assertSame(
            'type=blink;api-key=********;',
            WalletConnection::maskSecret('type=blink;api-key=abcd1234'),
        );
    }

    #[Test]
    public function bare_lightning_address_is_shown_as_is(): void
    {
        $this->assertSame('satflux@coinos.io', WalletConnection::maskSecret('satflux@coinos.io'));
    }

    #[Test]
    public function opaque_secrets_keep_the_six_plus_six_mask(): void
    {
        $uri = 'nostr+walletconnect://abcdef0123456789?relay=wss://relay.example&secret=0123456789abcdef';
        $this->assertSame(substr($uri, 0, 6).'...'.substr($uri, -6), WalletConnection::maskSecret($uri));
        $this->assertSame('********', WalletConnection::maskSecret('12345678'));
    }
}
