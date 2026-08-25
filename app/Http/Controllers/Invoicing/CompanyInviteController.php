<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\CompanyMemberRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyInvite;
use App\Models\CompanyMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Company invites (docs/COMPANY_SHARING.md, "C4").
 *
 * Owner-only endpoints create / list / revoke invites; the two recipient
 * endpoints (preview, accept) are reachable by any authenticated user because
 * the invitee is not yet a member. The server never sees the shared secret:
 * for sealed invites it stores an opaque ECIES blob, for link invites nothing.
 */
class CompanyInviteController extends Controller
{
    /** How long a fresh invite stays acceptable. */
    private const TTL_DAYS = 14;

    /**
     * Owner: look up a candidate recipient by e-mail so the client can seal
     * the secret to their current recovery key. Returns the key (public by
     * design - it is a verification anchor, shown as a fingerprint).
     */
    public function recipient(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        /** @var User $owner */
        $owner = $request->user();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null) {
            return response()->json(['found' => false, 'public_key' => null, 'has_recovery' => false, 'already_member' => false]);
        }

        return response()->json([
            'found' => true,
            'public_key' => $user->guest_recovery_public_key,
            'has_recovery' => ! empty($user->guest_recovery_public_key),
            'is_self' => $user->id === $owner->id,
            'already_member' => $company->roleFor($user) !== null,
        ]);
    }

    /** Owner: list pending invites for the company (no secrets). */
    public function index(Company $company): JsonResponse
    {
        $invites = $company->invites()
            ->pending()
            ->with('creator:id,name,email')
            ->latest()
            ->get()
            ->map(fn (CompanyInvite $invite): array => $this->summarize($invite));

        return response()->json(['data' => $invites]);
    }

    /** Owner: create an invite; returns the one-time token exactly once. */
    public function store(Request $request, Company $company): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->user();

        $data = $request->validate([
            'role' => ['required', Rule::in([CompanyMemberRole::Accountant->value, CompanyMemberRole::Member->value])],
            'mode' => ['required', Rule::in(['sealed', 'link'])],
            'invited_email' => ['nullable', 'email', 'max:255'],
            'invitee_public_key' => ['required_if:mode,sealed', 'nullable', 'regex:/^[a-f0-9]{64}$/i'],
            'sealed_secret' => ['required_if:mode,sealed', 'nullable', 'array'],
            'sealed_secret.v' => ['required_with:sealed_secret', 'integer'],
            'sealed_secret.epkB64' => ['required_with:sealed_secret', 'string', 'max:128'],
            'sealed_secret.ivB64' => ['required_with:sealed_secret', 'string', 'max:64'],
            'sealed_secret.ctB64' => ['required_with:sealed_secret', 'string', 'max:512'],
        ]);

        if ($data['mode'] === 'sealed') {
            $recipient = User::query()->where('email', $data['invited_email'] ?? '')->first();
            if ($recipient === null || empty($recipient->guest_recovery_public_key)) {
                return response()->json(['message' => 'recipient_no_recovery_key'], 422);
            }
            if ($recipient->id === $owner->id) {
                return response()->json(['message' => 'cannot_invite_self'], 422);
            }
            // The seal must target the recipient's CURRENT key - the server is
            // the source of truth, so a stale or substituted key is refused.
            if (! hash_equals(strtolower($recipient->guest_recovery_public_key), strtolower((string) $data['invitee_public_key']))) {
                return response()->json(['message' => 'recovery_key_mismatch'], 422);
            }
        }

        [$token, $tokenHash] = CompanyInvite::mintToken();

        $invite = $company->invites()->create([
            'role' => $data['role'],
            'mode' => $data['mode'],
            'invited_email' => $data['invited_email'] ?? null,
            'invitee_public_key' => $data['mode'] === 'sealed' ? strtolower((string) $data['invitee_public_key']) : null,
            'sealed_secret_json' => $data['mode'] === 'sealed' ? json_encode($data['sealed_secret']) : null,
            'token_hash' => $tokenHash,
            'created_by' => $owner->id,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        AuditLog::log('company.invite_created', 'company', $company->id, [
            'invite_id' => $invite->id,
            'role' => $invite->role->value,
            'mode' => $invite->mode,
        ]);

        return response()->json([
            'invite' => $this->summarize($invite),
            'token' => $token,
        ], 201);
    }

    /** Owner: revoke a pending invite. */
    public function destroy(Company $company, CompanyInvite $invite): JsonResponse
    {
        abort_unless($invite->company_id === $company->id, 404);

        // Serialize revocation with acceptance: lock the row and re-check
        // pending state after the lock, so a concurrent accept cannot slip
        // through between the check and the write (and vice versa).
        DB::transaction(function () use ($company, $invite): void {
            /** @var CompanyInvite|null $locked */
            $locked = CompanyInvite::query()->whereKey($invite->id)->lockForUpdate()->first();
            if ($locked !== null && $locked->isPending()) {
                $locked->update(['revoked_at' => now()]);
                AuditLog::log('company.invite_revoked', 'company', $company->id, ['invite_id' => $locked->id]);
            }
        });

        return response()->json(['revoked' => true]);
    }

    /**
     * Recipient: preview an invite by token. For sealed invites the opaque
     * blob is returned only to the account it was sealed to; link invites
     * carry no server secret at all.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $invite = $this->resolvePending($token);
        if ($invite === null) {
            return response()->json(['message' => 'invite_not_found'], 404);
        }

        /** @var User $user */
        $user = $request->user();
        $sealed = null;
        if ($invite->mode === 'sealed') {
            if (! $this->recipientMatches($invite, $user)) {
                return response()->json(['message' => 'invite_not_for_this_account'], 403);
            }
            $sealed = json_decode((string) $invite->sealed_secret_json, true);
        }

        $invite->loadMissing('company:id,legal_name', 'creator:id,name,email');

        return response()->json([
            'company_id' => $invite->company_id,
            'company_name' => $invite->company?->legal_name,
            'role' => $invite->role->value,
            'mode' => $invite->mode,
            'invited_by' => optional($invite->creator)->name ?? optional($invite->creator)->email,
            'invitee_public_key' => $invite->invitee_public_key,
            'sealed_secret' => $sealed,
        ]);
    }

    /**
     * Recipient: accept an invite. Writes the membership row; the client then
     * registers the SharedOwner and materializes the local companyShare row.
     */
    public function accept(Request $request, string $token): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = DB::transaction(function () use ($token, $user) {
            $invite = $this->resolvePending($token, lock: true);
            if ($invite === null) {
                return ['status' => 404, 'body' => ['message' => 'invite_not_found']];
            }
            if ($invite->mode === 'sealed' && ! $this->recipientMatches($invite, $user)) {
                return ['status' => 403, 'body' => ['message' => 'invite_not_for_this_account']];
            }

            CompanyMember::query()->updateOrCreate(
                ['company_id' => $invite->company_id, 'user_id' => $user->id],
                [
                    'role' => $invite->role->value,
                    'invited_by' => $invite->created_by,
                    'accepted_at' => now(),
                    'revoked_at' => null,
                ],
            );

            $invite->update(['accepted_at' => now(), 'accepted_by' => $user->id]);

            AuditLog::log('company.invite_accepted', 'company', $invite->company_id, [
                'invite_id' => $invite->id,
                'role' => $invite->role->value,
            ]);

            return ['status' => 200, 'body' => [
                'company_id' => $invite->company_id,
                'role' => $invite->role->value,
            ]];
        });

        return response()->json($result['body'], $result['status']);
    }

    private function resolvePending(string $token, bool $lock = false): ?CompanyInvite
    {
        if ($token === '') {
            return null;
        }
        $query = CompanyInvite::query()->where('token_hash', CompanyInvite::hashToken($token))->pending();
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function recipientMatches(CompanyInvite $invite, User $user): bool
    {
        return ! empty($user->guest_recovery_public_key)
            && $invite->invitee_public_key !== null
            && hash_equals(strtolower($invite->invitee_public_key), strtolower($user->guest_recovery_public_key));
    }

    /** @return array<string, mixed> */
    private function summarize(CompanyInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'role' => $invite->role->value,
            'mode' => $invite->mode,
            'invited_email' => $invite->invited_email,
            'invitee_public_key' => $invite->invitee_public_key,
            'expires_at' => $invite->expires_at->toIso8601String(),
            'created_at' => $invite->created_at?->toIso8601String(),
        ];
    }
}
