<?php

namespace App\Http\Controllers\Invoicing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Invoicing\CompanyBrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyBrandingController extends Controller
{
    public function __construct(
        protected CompanyBrandingService $brandingService,
    ) {}

    public function uploadLogo(Request $request, Company $company): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:2048'],
        ]);

        $company = $this->brandingService->storeLogo($company, $request->file('image'));

        return response()->json([
            'data' => array_merge($company->toArray(), $this->brandingService->brandingMeta($company)),
        ]);
    }

    public function uploadSignatureStamp(Request $request, Company $company): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:2048'],
        ]);

        $company = $this->brandingService->storeSignatureStamp($company, $request->file('image'));

        return response()->json([
            'data' => array_merge($company->toArray(), $this->brandingService->brandingMeta($company)),
        ]);
    }

    public function deleteLogo(Company $company): JsonResponse
    {
        $company = $this->brandingService->deleteLogo($company);

        return response()->json([
            'data' => array_merge($company->toArray(), $this->brandingService->brandingMeta($company)),
        ]);
    }

    public function deleteSignatureStamp(Company $company): JsonResponse
    {
        $company = $this->brandingService->deleteSignatureStamp($company);

        return response()->json([
            'data' => array_merge($company->toArray(), $this->brandingService->brandingMeta($company)),
        ]);
    }

    public function showLogo(Company $company): Response|StreamedResponse
    {
        return $this->streamImage($company->logo_path);
    }

    public function showSignatureStamp(Company $company): Response|StreamedResponse
    {
        return $this->streamImage($company->signature_stamp_path);
    }

    protected function streamImage(?string $path): Response|StreamedResponse
    {
        if (! $path || ! Storage::disk(CompanyBrandingService::DISK)->exists($path)) {
            abort(404);
        }

        $disk = Storage::disk(CompanyBrandingService::DISK);

        return response()->stream(function () use ($disk, $path) {
            echo $disk->get($path);
        }, 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
