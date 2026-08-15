<?php

namespace App\Services\Chorala;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChoralaWidgetSettingsService
{
    private const CACHE_TTL_SECONDS = 600;

    public function isWidgetEnabled(): bool
    {
        $projectKey = config('services.chorala.project_key');

        return is_string($projectKey) && $projectKey !== '';
    }

    public function isApiSyncConfigured(): bool
    {
        $apiKey = config('services.chorala.api_key');

        return $this->isWidgetEnabled()
            && is_string($apiKey) && $apiKey !== '';
    }

    /**
     * Widget appearance for embed init. API sync (optional hk_ key) or .env overrides, plus satflux launcher layout.
     *
     * @return array{theme?: string, primaryColor?: string, position: string, mode: string}|null
     */
    public function getWidgetSettings(): ?array
    {
        if (! $this->isWidgetEnabled()) {
            return null;
        }

        $appearance = [];

        if ($this->isApiSyncConfigured()) {
            $remote = $this->fetchCachedRemoteWidgetSettings();
            if ($remote !== null) {
                $appearance = $remote;
            }
        }

        if ($appearance === []) {
            $appearance = $this->envWidgetSettings();
        }

        return $this->mergeWithSatfluxOverrides($appearance);
    }

    /**
     * @return array{theme?: string, primaryColor?: string}|null
     */
    private function fetchCachedRemoteWidgetSettings(): ?array
    {
        $cacheKey = 'chorala:widget-settings:'.sha1((string) config('services.chorala.project_key'));
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $remote = $this->fetchRemoteWidgetSettings();

        if ($remote === null) {
            return null;
        }

        Cache::put($cacheKey, $remote, self::CACHE_TTL_SECONDS);

        return $remote;
    }

    /**
     * @return array{theme?: string, primaryColor?: string}
     */
    private function envWidgetSettings(): array
    {
        $settings = [];

        $theme = config('services.chorala.widget_theme');
        if (is_string($theme) && in_array($theme, ['light', 'dark'], true)) {
            $settings['theme'] = $theme;
        }

        $primaryColor = config('services.chorala.widget_primary_color');
        if (is_string($primaryColor) && $primaryColor !== '') {
            $settings['primaryColor'] = $primaryColor;
        }

        return $settings;
    }

    /**
     * @return array{theme?: string, primaryColor?: string}|null
     */
    private function fetchRemoteWidgetSettings(): ?array
    {
        $project = $this->fetchProject();

        if ($project === null) {
            return null;
        }

        $widgetSettings = $project['widgetSettings'] ?? [];

        if (! is_array($widgetSettings)) {
            return [];
        }

        $normalized = [];

        $theme = $widgetSettings['theme'] ?? null;
        if (is_string($theme) && in_array($theme, ['light', 'dark'], true)) {
            $normalized['theme'] = $theme;
        }

        $primaryColor = $widgetSettings['primaryColor'] ?? null;
        if (is_string($primaryColor) && $primaryColor !== '') {
            $normalized['primaryColor'] = $primaryColor;
        }

        return $normalized;
    }

    /**
     * @param  array{theme?: string, primaryColor?: string}  $appearance
     * @return array{theme?: string, primaryColor?: string, position: string, mode: string}
     */
    private function mergeWithSatfluxOverrides(array $appearance): array
    {
        return array_merge($appearance, [
            'position' => 'bottom-left',
            'mode' => 'manual',
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProject(): ?array
    {
        $projectId = config('services.chorala.project_id');

        if (is_string($projectId) && $projectId !== '') {
            return $this->requestProject($projectId);
        }

        return $this->resolveProjectByPublicKey();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveProjectByPublicKey(): ?array
    {
        $publicKey = (string) config('services.chorala.project_key');
        $projects = $this->requestProjects();

        if ($projects === null) {
            return null;
        }

        foreach ($projects as $project) {
            if (($project['publicKey'] ?? null) === $publicKey) {
                return $project;
            }
        }

        Log::warning('[chorala] No project matched CHORALA_PROJECT_KEY for widget settings sync.');

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestProject(string $projectId): ?array
    {
        $response = $this->adminClient()->get($this->apiBaseUrl().'/projects/'.$projectId);

        if (! $response->successful()) {
            Log::warning('[chorala] Failed to fetch project for widget settings.', [
                'project_id' => $projectId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $project = $response->json();

        return is_array($project) ? $project : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function requestProjects(): ?array
    {
        $response = $this->adminClient()->get($this->apiBaseUrl().'/projects');

        if (! $response->successful()) {
            Log::warning('[chorala] Failed to list projects for widget settings sync.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $projects = $response->json();

        if (! is_array($projects)) {
            return null;
        }

        return array_values(array_filter($projects, 'is_array'));
    }

    private function adminClient(): PendingRequest
    {
        return Http::withToken((string) config('services.chorala.api_key'))
            ->acceptJson()
            ->timeout(10);
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) config('services.chorala.widget_url'), '/').'/api/v1';
    }
}
