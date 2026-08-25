<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMember;
use Illuminate\Http\JsonResponse;

/**
 * Company member management (docs/COMPANY_SHARING.md, "C5"), owner-only.
 *
 * Revoking a member sets `revoked_at`; because Company::roleFor / accessibleBy
 * and both company middlewares already exclude revoked rows, that is a complete
 * server-side lockout - the former member's API calls 403 immediately. It does
 * NOT rotate the SharedOwner key, so data already synced to their device stays
 * with them (Evolu 7.4.1 has no write-key rotation); forward secrecy needs the
 * separate re-key flow.
 */
class CompanyMemberController extends Controller
{
    /** List the company's active members (owner excluded - it is implicit). */
    public function index(Company $company): JsonResponse
    {
        $members = $company->members()
            ->active()
            ->with('user:id,name,email')
            ->get()
            ->map(fn (CompanyMember $member): array => [
                'id' => $member->id,
                'role' => $member->role->value,
                'name' => $member->user?->name,
                'email' => $member->user?->email,
                'accepted_at' => $member->accepted_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $members]);
    }

    /** Revoke a member's access. Idempotent. */
    public function destroy(Company $company, CompanyMember $member): JsonResponse
    {
        abort_unless($member->company_id === $company->id, 404);

        if ($member->revoked_at === null) {
            $member->update(['revoked_at' => now()]);
            AuditLog::log('company.member_revoked', 'company', $company->id, [
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'role' => $member->role->value,
            ]);
        }

        return response()->json(['revoked' => true]);
    }
}
