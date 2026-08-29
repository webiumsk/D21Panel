<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Services\Invoicing\CompanySlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySlotController extends Controller
{
    public function __construct(
        protected CompanySlotService $slotService,
    ) {}

    public function purchase(Request $request): JsonResponse
    {
        $request->validate([
            'slots' => ['required', 'integer'],
        ]);

        $checkout = $this->slotService->startPurchase(
            $request->user(),
            (int) $request->input('slots'),
        );

        return response()->json(['data' => $checkout]);
    }
}
