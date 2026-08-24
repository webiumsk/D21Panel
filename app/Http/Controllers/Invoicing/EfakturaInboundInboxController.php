<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\EfakturaInboundReceipt;
use App\Models\User;
use App\Services\Invoicing\Efaktura\EfakturaInboundInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Local-first e-faktura inbox (see EfakturaInboundInboxService). */
class EfakturaInboundInboxController extends Controller
{
    public function __construct(
        protected EfakturaInboundInboxService $inboxService,
    ) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $this->inboxService->listPending($user, $company)->values()]);
    }

    public function show(Request $request, Company $company, EfakturaInboundReceipt $receipt): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => $this->inboxService->detail($user, $company, $receipt)]);
    }

    public function markImported(Company $company, EfakturaInboundReceipt $receipt): Response
    {
        $this->inboxService->markImported($receipt, $company);

        return response()->noContent();
    }

    public function dismiss(Company $company, EfakturaInboundReceipt $receipt): Response
    {
        $this->inboxService->dismiss($receipt, $company);

        return response()->noContent();
    }
}
