<?php

namespace App\Http\Middleware;

use App\Enums\CompanyMemberRole;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate on top of EnsureCompanyOwnership (which now admits members):
 * `EnsureCompanyRole:owner` keeps destructive and credential-bearing routes
 * (delete, reset, store links, SMTP settings) with the company owner.
 * Support / admin keep their bypass.
 */
class EnsureCompanyRole
{
    public function handle(Request $request, Closure $next, string $role = 'owner'): Response
    {
        $company = $request->route('company');
        if (! $company instanceof Company) {
            abort(404, 'Company not found');
        }

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        if ($user->isSupport() || $user->isAdmin()) {
            return $next($request);
        }

        $required = CompanyMemberRole::tryFrom($role) ?? CompanyMemberRole::Owner;
        $actual = $company->roleFor($user);

        if ($actual === null || ($required === CompanyMemberRole::Owner && $actual !== CompanyMemberRole::Owner)) {
            abort(403, 'This action is reserved for the company owner');
        }

        return $next($request);
    }
}
