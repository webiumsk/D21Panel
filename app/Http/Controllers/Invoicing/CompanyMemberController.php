<?php

namespace App\Http\Controllers\Invoicing;

use App\Enums\CompanyMemberRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
            ->where('role', '!=', CompanyMemberRole::Owner->value)
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
        // The owner is implicit (companies.user_id), never a member row; this
        // endpoint may only revoke accountants / members.
        abort_if($member->role === CompanyMemberRole::Owner, 403);

        // Serialize concurrent revocations: lock the row, re-check revoked_at
        // after the lock, and keep the audit write in the same transaction so a
        // second DELETE cannot double-revoke or emit a duplicate audit event,
        // and an audit failure rolls back the membership update.
        DB::transaction(function () use ($company, $member): void {
            /** @var CompanyMember|null $locked */
            $locked = CompanyMember::query()->whereKey($member->id)->lockForUpdate()->first();
            if ($locked === null || $locked->revoked_at !== null || $locked->role === CompanyMemberRole::Owner) {
                return;
            }
            $locked->update(['revoked_at' => now()]);
            AuditLog::log('company.member_revoked', 'company', $company->id, [
                'member_id' => $locked->id,
                'user_id' => $locked->user_id,
                'role' => $locked->role->value,
            ]);
        });

        return response()->json(['revoked' => true]);
    }
}
