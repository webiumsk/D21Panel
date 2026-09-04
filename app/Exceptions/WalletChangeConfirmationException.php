<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A write that would replace a connected wallet was attempted without the
 * email-code grant (409) or by a guest account that cannot receive codes (403).
 */
class WalletChangeConfirmationException extends RuntimeException
{
    public const CODE_CONFIRMATION_REQUIRED = 'wallet_change_confirmation_required';

    public const CODE_GUEST_UPGRADE_REQUIRED = 'guest_upgrade_required';

    public function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function confirmationRequired(): self
    {
        return new self(self::CODE_CONFIRMATION_REQUIRED, 409, __('auth.wallet_change_confirmation_required'));
    }

    public static function guestUpgradeRequired(): self
    {
        return new self(self::CODE_GUEST_UPGRADE_REQUIRED, 403, __('auth.wallet_change_guest_upgrade_required'));
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
