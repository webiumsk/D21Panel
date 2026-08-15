<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BtcPay\Exceptions\BtcPayException;
use App\Services\BtcPay\UserService;
use App\Support\LogSanitizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailVerificationController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Send verification email to the user.
     */
    public function sendVerificationEmail(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['We could not find a user with that email address.'],
            ]);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $e) {
            Log::warning('Failed to send verification email', [
                'email' => LogSanitizer::email($user->email),
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'email' => [__('messages.verification_email_failed')],
            ]);
        }

        return response()->json([
            'message' => 'Verification email sent. Please check your inbox.',
        ]);
    }

    /**
     * Verify the email address.
     */
    public function verify(Request $request, $id, $hash)
    {
        // Ensure we always return JSON, never redirect
        if (! $request->expectsJson() && ! $request->wantsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        // Validate required query parameters for signed URL
        $request->validate([
            'expires' => ['required'],
            'signature' => ['required'],
        ]);

        // id and hash come from route parameters
        if (! $id || ! $hash) {
            return response()->json([
                'message' => 'Invalid verification link. Missing id or hash.',
            ], 400);
        }

        try {
            $user = User::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        // The email link uses /auth/verify-email/ but Laravel signed it for /api/auth/verify-email/
        // Frontend calls /api/auth/verify-email/, so we validate the signature on the current request
        // But we need to account for the fact that the signature was generated for /api/auth/verify-email/
        // while the email link shows /auth/verify-email/

        // Log time information for debugging
        $expires = $request->query('expires');
        $currentTimestamp = now()->timestamp;
        $currentTime = now()->format('Y-m-d H:i:s T');
        $expiresTime = $expires ? date('Y-m-d H:i:s T', $expires) : null;
        $timeDiff = $expires ? ($expires - $currentTimestamp) : null;

        Log::info('Email verification signature validation attempt', [
            'user_id' => $user->id,
            'email' => LogSanitizer::email($user->email),
            'current_timestamp' => $currentTimestamp,
            'current_time' => $currentTime,
            'expires_timestamp' => $expires,
            'expires_time' => $expiresTime,
            'time_diff_seconds' => $timeDiff,
            'app_timezone' => config('app.timezone'),
            'php_timezone' => date_default_timezone_get(),
        ]);

        // Check if expired first (before signature validation)
        if ($expires && $currentTimestamp > $expires) {
            Log::warning('Email verification link expired', [
                'user_id' => $user->id,
                'email' => LogSanitizer::email($user->email),
                'expires_timestamp' => $expires,
                'expires_time' => $expiresTime,
                'current_timestamp' => $currentTimestamp,
                'current_time' => $currentTime,
                'expired_by_seconds' => $currentTimestamp - $expires,
            ]);

            return response()->json([
                'message' => 'Verification link has expired.',
            ], 403);
        }

        // Use Laravel's built-in signature validation
        // The issue is that $request->fullUrl() may have incorrect host, so we need to fix the request
        // First, try to use the request as-is, but fix the URL if needed

        // Get the correct base URL
        $baseUrl = rtrim(config('app.url'), '/');
        $correctUrl = $baseUrl.'/api/auth/verify-email/'.$id.'/'.$hash;

        // Get query parameters for signature validation
        // Password is now stored in cache, not in URL query params
        $queryParams = $request->query();

        // Build query string for signature validation
        $queryStringForValidation = http_build_query($queryParams);

        // Create a proper request with correct URL for validation (without password)
        $validationRequest = Request::create($correctUrl.'?'.$queryStringForValidation, 'GET');

        // Set server variables to match Laravel's expectations
        $parsedUrl = parse_url($baseUrl);
        $validationRequest->server->set('HTTP_HOST', $parsedUrl['host'].(isset($parsedUrl['port']) ? ':'.$parsedUrl['port'] : ''));
        $validationRequest->server->set('SERVER_NAME', $parsedUrl['host']);
        $validationRequest->server->set('QUERY_STRING', $queryStringForValidation);

        // Signed URLs are generated with absolute=false (relative /api/... path); validate the same way.
        if (! URL::hasValidSignature($validationRequest, false)) {
            Log::warning('Email verification signature validation failed', [
                'user_id' => $user->id,
                'email' => LogSanitizer::email($user->email),
                'correct_url' => $correctUrl,
                'query_string_for_validation' => $queryStringForValidation,
                'original_query_string' => $request->server->get('QUERY_STRING', ''),
                'app_url' => config('app.url'),
                'signature_correct' => URL::hasCorrectSignature($validationRequest, false),
                'signature_not_expired' => URL::signatureHasNotExpired($validationRequest),
            ]);

            return response()->json([
                'message' => 'Invalid verification link. The link may have expired or been tampered with.',
            ], 403);
        }

        // Check if already verified (idempotent)
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
                'verified' => true,
            ]);
        }

        // Verify hash matches email (hash comes from route parameter, not request)
        if (! $user->email || ! hash_equals((string) $hash, sha1($user->email))) {
            return response()->json([
                'message' => 'Invalid verification link.',
            ], 400);
        }

        // Generate random password for BTCPay user
        // Merchant never needs BTCPay UI access, so we use a random password
        // This eliminates TTL/resend/caching complications
        $btcpayRandomPassword = Str::random(32);

        return DB::transaction(function () use ($request, $user, $btcpayRandomPassword) {
            // Mark email as verified
            $user->markEmailAsVerified();

            Log::info('Email verified', [
                'user_id' => $user->id,
                'email' => LogSanitizer::email($user->email),
            ]);

            // Create or link BTCPay user
            // When password is provided, user will be created as active (not "Pending Invitation")
            if (! $user->btcpay_user_id && $user->email) {
                try {
                    // First check if user exists on BTCPay Server
                    $existingBtcpayUser = $this->userService->getUserByEmail($user->email);

                    if ($existingBtcpayUser) {
                        // Link existing BTCPay user to local user
                        $btcpayUserId = $existingBtcpayUser['id'] ?? $existingBtcpayUser['userId'] ?? null;
                        $user->update([
                            'btcpay_user_id' => $btcpayUserId,
                        ]);

                        Log::info('Linked existing BTCPay user to local user', [
                            'user_id' => $user->id,
                            'btcpay_user_id' => $btcpayUserId,
                        ]);
                    } else {
                        // Create new BTCPay user with random password
                        // Merchant never needs BTCPay UI access, so random password is sufficient
                        $btcpayUser = $this->userService->createUser([
                            'email' => $user->email,
                            'password' => $btcpayRandomPassword,
                            'isAdministrator' => false,
                            'sendInvitationEmail' => false,
                        ]);

                        $btcpayUserId = $btcpayUser['id'] ?? $btcpayUser['userId'] ?? null;
                        $user->update([
                            'btcpay_user_id' => $btcpayUserId,
                        ]);

                        $logData = [
                            'user_id' => $user->id,
                            'btcpay_user_id' => $btcpayUserId,
                            'email_confirmed_btcpay' => $btcpayUser['emailConfirmed'] ?? false,
                            'has_invitation_url' => ! empty($btcpayUser['invitationUrl'] ?? null),
                        ];

                        // Even when password is provided, BTCPay may still return invitationUrl
                        // In this case, we need to accept the invitation to activate the user
                        if (! empty($btcpayUser['invitationUrl'] ?? null)) {
                            Log::info('Created new BTCPay user but invitation URL present, attempting to accept invitation', $logData);

                            // Try to accept invitation programmatically
                            $invitationAccepted = $this->userService->acceptInvitation($btcpayUser['invitationUrl']);
                            if ($invitationAccepted) {
                                Log::info('BTCPay invitation accepted successfully', [
                                    'user_id' => $user->id,
                                    'btcpay_user_id' => $btcpayUserId,
                                ]);
                            } else {
                                Log::warning('Failed to accept BTCPay invitation automatically - user may need to accept manually', [
                                    'user_id' => $user->id,
                                    'btcpay_user_id' => $btcpayUserId,
                                    'invitation_url' => $btcpayUser['invitationUrl'],
                                ]);
                            }
                        } else {
                            Log::info('Created new BTCPay user with random password (active)', $logData);
                        }

                        // Clear password from memory as soon as possible for security
                        $btcpayRandomPassword = null;
                    }

                    // Create user-level API key for BTCPay user (if not already exists)
                    // User should be active now since password was provided, so API key creation should work
                    if ($user->btcpay_user_id && ! $user->btcpay_api_key) {
                        try {
                            // Check user status first
                            $btcpayUser = $this->userService->getUser($user->btcpay_user_id);
                            $userApproved = $btcpayUser['approved'] ?? false;
                            $emailConfirmed = $btcpayUser['emailConfirmed'] ?? false;

                            Log::info('Attempting to create BTCPay API key', [
                                'user_id' => $user->id,
                                'btcpay_user_id' => $user->btcpay_user_id,
                                'user_approved' => $userApproved,
                                'email_confirmed' => $emailConfirmed,
                            ]);

                            // Create API key with permissions from config/btcpay_merchant_permissions.php
                            $apiKeyData = $this->userService->createApiKey(
                                $user->btcpay_user_id,
                                [], // Empty = use config('btcpay_merchant_permissions.merchant_api_key')
                                [], // Allow access to all user's stores (empty = no restriction)
                                'satflux.io API Key - '.$user->email
                            );

                            $apiKey = $apiKeyData['apiKey'] ?? null;
                            if ($apiKey) {
                                $user->update([
                                    'btcpay_api_key' => $apiKey,
                                ]);

                                Log::info('Created BTCPay API key for user', [
                                    'user_id' => $user->id,
                                    'btcpay_user_id' => $user->btcpay_user_id,
                                ]);
                            }
                        } catch (BtcPayException $e) {
                            // Log full error details for debugging
                            $errorMessage = $e->getMessage();
                            Log::warning('BTCPay API key creation failed', [
                                'user_id' => $user->id,
                                'btcpay_user_id' => $user->btcpay_user_id,
                                'error' => $errorMessage,
                                'error_code' => $e->getCode(),
                                'note' => 'User can still use server-level API key. API key can be created manually via BTCPay UI if needed.',
                            ]);

                            // If 422 (validation error), it might be due to pending invitation
                            // We'll continue - user can use server-level API key for now
                        }
                    }
                } catch (BtcPayException $e) {
                    Log::error('BTCPay user creation/linking failed during email verification', [
                        'user_id' => $user->id,
                        'email' => LogSanitizer::email($user->email),
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Continue even if BTCPay user creation fails
                    // User can still use satflux.io, BTCPay user can be created later
                }
            }

            // Automatically log in the user after successful verification
            Auth::login($user);
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
            $user->update(['last_login_at' => now()]);

            return response()->json([
                'message' => 'Email verified successfully. You are now logged in.',
                'verified' => true,
                'user' => $user->makeVisible('role'),
            ]);
        });
    }
}
