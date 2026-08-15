<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Compliance\ComplianceGate;
use App\Services\GuestProvisioningService;
use App\Services\GuestRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GuestAuthController extends Controller
{
    public function __construct(
        protected GuestProvisioningService $guestProvisioningService,
        protected GuestRecoveryService $guestRecoveryService,
        protected ComplianceGate $complianceGate,
    ) {}

    /**
     * Create a fully provisioned guest account (local + BTCPay) and login immediately.
     */
    public function create(Request $request)
    {
        $validatedGuest = $request->validate([
            'recovery_public_key' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);
        $recoveryPkHex = isset($validatedGuest['recovery_public_key'])
            ? strtolower($validatedGuest['recovery_public_key'])
            : null;

        if ($request->user()) {
            $existingUser = $request->user();
            $existingStoreId = $this->guestProvisioningService->resolvePrimaryStoreId($existingUser);

            if ($recoveryPkHex
                && Schema::hasColumn('users', 'guest_recovery_public_key')
                && empty($existingUser->guest_recovery_public_key)) {
                $existingUser = $this->guestProvisioningService->attachRecoveryKey($existingUser, $recoveryPkHex);
            }

            return response()->json([
                'message' => 'Already authenticated.',
                'user' => $existingUser->makeVisible('role'),
                'store_id' => $existingStoreId,
            ]);
        }

        try {
            $guestEmail = $this->guestProvisioningService->generateGuestEmail();
            $this->complianceGate->assertRegistrationAllowed($request, $guestEmail, 'Guest');

            [$user, $store] = $this->guestProvisioningService->provisionGuest($recoveryPkHex, $guestEmail);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Guest account provisioning failed', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $payload = [
                'message' => 'Unable to start guest session right now. Please try again.',
                'code' => 'guest_provisioning_failed',
            ];
            if (config('app.debug')) {
                $payload['debug'] = [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                ];
            }

            return response()->json($payload, 503);
        }

        Auth::login($user);
        $this->complianceGate->linkLatestRegistrationScreening($user->email, $user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->update(['last_login_at' => now()]);

        return response()->json([
            'message' => 'Guest session started.',
            'user' => $user->makeVisible('role'),
            'store_id' => $store->id,
        ], 201);
    }

    /**
     * Start a guest recovery challenge (sign the returned message with the same key as enrollment).
     */
    public function recoveryChallenge(): JsonResponse
    {
        $challenge = $this->guestRecoveryService->createChallenge();

        return response()->json([
            'data' => [
                'challenge_id' => $challenge['challenge_id'],
                'nonce' => $challenge['nonce'],
            ],
        ]);
    }

    /**
     * Complete guest recovery: verify Ed25519 signature and start a session for the matching guest user.
     */
    public function recoveryRestore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'recovery_public_key' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'signature' => ['required', 'string', 'regex:/^[a-f0-9]{128}$/i'],
        ]);

        try {
            $result = $this->guestRecoveryService->restoreFromChallenge($validated);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'message' => 'Guest session restored.',
            'user' => $result['user']->makeVisible('role'),
            'store_id' => $result['store_id'],
        ]);
    }
}
