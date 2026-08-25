<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\User;
use App\Services\SubscriptionEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanAllowsBusinessInvoicing
{
    public function __construct(
        protected SubscriptionEntitlementService $subscriptionService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        if ($this->subscriptionService->canUseBusinessInvoicing($user)) {
            return $next($request);
        }

        // A member (e.g. the accountant) works under the OWNER's plan: the
        // company's owner is the one paying for invoicing, so an active member
        // may act on that company without a plan of their own.
        $company = $request->route('company');
        if ($company instanceof Company) {
            if (! $company->isOwnedBy($user)
                && $company->roleFor($user) !== null
                && $company->user instanceof User
                && $this->subscriptionService->canUseBusinessInvoicing($company->user)) {
                return $next($request);
            }
        } elseif ($this->hasEntitledMembership($user)) {
            // Company-less routes (the company index, ephemeral bridges): any
            // active membership under an entitled owner opens the module.
            return $next($request);
        }

        return response()->json([
            'message' => __('messages.business_invoicing_available_in_pro', [
                'default' => 'Business invoicing is available on the PRO plan. Please upgrade to create invoices.',
            ]),
        ], 403);
    }

    protected function hasEntitledMembership(User $user): bool
    {
        return CompanyMember::query()
            ->where('user_id', $user->id)
            ->active()
            ->with('company.user')
            ->get()
            ->contains(function (CompanyMember $member): bool {
                $owner = $member->company?->user;

                return $owner instanceof User && $this->subscriptionService->canUseBusinessInvoicing($owner);
            });
    }
}
