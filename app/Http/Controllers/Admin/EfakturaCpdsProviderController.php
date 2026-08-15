<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EfakturaCpdsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Admin editor of the CPDS (digitálny poštár) presets shown in the merchant
 * e-faktura settings form. RegWatch rule applies: an operator adds a preset
 * only after verifying the provider's real SAPI-SK endpoint - nothing here
 * is seeded or generated. Preset hosts become SSRF-trusted (SapiSkClient).
 */
class EfakturaCpdsProviderController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => EfakturaCpdsProvider::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (EfakturaCpdsProvider $provider) => $this->format($provider))
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $this->assertHostNotTakenByAnotherActivePreset($validated);

        $provider = EfakturaCpdsProvider::create($validated);

        return response()->json(['data' => $this->format($provider)], 201);
    }

    public function update(Request $request, EfakturaCpdsProvider $provider): JsonResponse
    {
        $validated = $this->validated($request);
        $this->assertHostNotTakenByAnotherActivePreset($validated, $provider);

        $provider->update($validated);

        return response()->json(['data' => $this->format($provider->fresh())]);
    }

    public function destroy(EfakturaCpdsProvider $provider): JsonResponse
    {
        $provider->delete();

        return response()->json(['message' => __('Preset deleted.')]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'base_url' => ['required', 'string', 'max:255', 'url', 'starts_with:https://'],
            'send_detail_path' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);

        $validated['base_url'] = rtrim((string) $validated['base_url'], '/');

        $detailPath = trim((string) ($validated['send_detail_path'] ?? ''));
        if ($detailPath !== '' && ! str_contains($detailPath, '{id}')) {
            throw ValidationException::withMessages([
                'send_detail_path' => ['The detail path must contain the {id} placeholder.'],
            ]);
        }
        $validated['send_detail_path'] = $detailPath !== '' ? $detailPath : null;

        return $validated;
    }

    /**
     * One ACTIVE preset per host - detailPathForBaseUrl and the SSRF trust
     * resolve by hostname, so an ambiguous host would pick an arbitrary
     * preset's status path.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function assertHostNotTakenByAnotherActivePreset(
        array $validated,
        ?EfakturaCpdsProvider $ignore = null,
    ): void {
        // Effective active state: the request value, else the current row
        // (update), else the column default (create).
        $active = (bool) ($validated['active'] ?? $ignore->active ?? true);
        if (! $active) {
            return;
        }

        $host = strtolower((string) parse_url((string) $validated['base_url'], PHP_URL_HOST));

        $taken = EfakturaCpdsProvider::query()
            ->where('active', true)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore->id))
            ->get()
            ->contains(fn (EfakturaCpdsProvider $preset): bool => strtolower((string) parse_url($preset->base_url, PHP_URL_HOST)) === $host);

        if ($taken) {
            throw ValidationException::withMessages([
                'base_url' => ['Another active preset already uses this host.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function format(EfakturaCpdsProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'base_url' => $provider->base_url,
            'send_detail_path' => $provider->send_detail_path,
            'active' => $provider->active,
            'sort_order' => $provider->sort_order,
            'updated_at' => $provider->updated_at?->toIso8601String(),
        ];
    }
}
