<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\BtcPay\BtcPayFileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProductImageController extends Controller
{
    public function __construct(
        protected BtcPayFileUploadService $btcPayFileUploadService
    ) {}

    /**
     * Upload a product / PoS / crowdfund perk image.
     * Uses BTCPay Server Files API (same as ticket images) so files persist on BTCPay, not Satflux local disk.
     */
    public function upload(Request $request, Store $store)
    {
        $user = $request->user();

        // Ensure user owns the store
        if ($store->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'image' => 'required|image|max:5120', // Max 5MB
        ]);

        try {
            $data = $this->btcPayFileUploadService->uploadForStore($request->file('image'), $store);

            Log::info('Product image uploaded (BTCPay Files API)', [
                'store_id' => $store->id,
                'user_id' => $user->id,
                'file_id' => $data['id'],
                'storage_name' => $data['storage_name'],
                'url' => $data['url'],
            ]);

            return response()->json([
                'message' => 'Image uploaded successfully',
                'data' => [
                    'url' => $data['url'],
                    'image_url' => $data['image_url'],
                    'file_id' => $data['id'],
                    'storage_name' => $data['storage_name'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to upload product image', [
                'store_id' => $store->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to upload image: '.$e->getMessage()], 500);
        }
    }
}
