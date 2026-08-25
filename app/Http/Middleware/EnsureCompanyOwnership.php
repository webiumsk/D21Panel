<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyOwnership
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');

        if (! $company instanceof Company) {
            abort(404, 'Company not found');
        }

        // Owner, active member (docs/COMPANY_SHARING.md) or support / admin.
        // Owner-only routes add EnsureCompanyRole on top.
        $user = $request->user();
        if ($user === null || ! $company->isAccessibleBy($user)) {
            abort(403, 'Unauthorized access to company');
        }

        return $next($request);
    }
}
