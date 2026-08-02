<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function csp_header_is_absent_when_disabled(): void
    {
        config(['security.csp.enabled' => false]);

        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    #[Test]
    public function csp_report_only_header_is_sent_during_rollout(): void
    {
        config(['security.csp.enabled' => true, 'security.csp.report_only' => true]);

        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy');
        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($policy);
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
    }

    #[Test]
    public function csp_is_enforced_when_report_only_is_off(): void
    {
        config(['security.csp.enabled' => true, 'security.csp.report_only' => false]);

        $response = $this->get('/');

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($policy);
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        // YouTube embeds (landing video, documentation) must stay allowed
        $this->assertStringContainsString('https://www.youtube-nocookie.com', $policy);
    }

    #[Test]
    public function connect_src_is_an_explicit_allowlist_not_bare_wildcards(): void
    {
        config([
            'security.csp.enabled' => true,
            'security.csp.report_only' => false,
            'security.csp.evolu_relay_url' => 'wss://relay.example.com',
            'services.btcpay.public_url' => 'https://pay.example.com',
        ]);

        $policy = $this->get('/')->headers->get('Content-Security-Policy');

        // The connect-src directive must not fall back to bare https:/wss:.
        preg_match('/connect-src ([^;]+)/', (string) $policy, $m);
        $connect = $m[1] ?? '';
        $this->assertStringContainsString("'self'", $connect);
        $this->assertStringContainsString('https://pay.example.com', $connect);
        $this->assertStringContainsString('wss://relay.example.com', $connect);
        // The relay host serves the usage endpoint over HTTPS too.
        $this->assertStringContainsString('https://relay.example.com', $connect);
        $this->assertStringNotContainsString(' https: ', " {$connect} ");
        $this->assertStringNotContainsString(' wss: ', " {$connect} ");
    }

    #[Test]
    public function connect_src_includes_the_authenticated_users_custom_relay(): void
    {
        config(['security.csp.enabled' => true, 'security.csp.report_only' => false]);

        $user = User::factory()->create(['evolu_relay_url' => 'wss://my-relay.example.com']);

        $policy = (string) $this->actingAs($user)->get('/')->headers->get('Content-Security-Policy');
        preg_match('/connect-src ([^;]+)/', $policy, $m);
        $this->assertStringContainsString('wss://my-relay.example.com', $m[1] ?? '');
    }

    #[Test]
    public function csp_disabled_in_production_fails_closed(): void
    {
        config(['security.csp.enabled' => false]);
        app()->detectEnvironment(fn () => 'production');

        $this->get('/')->assertStatus(500);
    }

    #[Test]
    public function script_src_allows_wasm_and_chorala_but_not_eval(): void
    {
        config([
            'security.csp.enabled' => true,
            'security.csp.report_only' => false,
            'services.chorala.widget_url' => 'https://chorala.com',
        ]);

        $policy = (string) $this->get('/')->headers->get('Content-Security-Policy');
        preg_match('/script-src ([^;]+)/', $policy, $m);
        $script = $m[1] ?? '';

        // Evolu sqlite WASM needs wasm compilation; JS eval stays blocked.
        $this->assertStringContainsString("'wasm-unsafe-eval'", $script);
        $this->assertStringContainsString('https://chorala.com', $script);
        $this->assertStringNotContainsString("'unsafe-eval'", $script);
        $this->assertStringContainsString('report-uri /api/csp-report', $policy);
    }

    #[Test]
    public function csp_report_endpoint_accepts_and_logs_reports(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'CSP violation reported'
                    && ($context['violated_directive'] ?? null) === 'script-src'
                    && ($context['blocked_uri'] ?? null) === 'https://evil.example.com/x.js';
            });

        $this->call('POST', '/api/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], json_encode([
            'csp-report' => [
                'document-uri' => 'https://satflux.io/invoicing',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example.com/x.js',
            ],
        ]))->assertStatus(204);

        // Oversized bodies are dropped without error - and without logging
        // (the mock above allows exactly one warning call).
        $this->call('POST', '/api/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], str_repeat('x', 20000))->assertStatus(204);
    }

    #[Test]
    public function csp_report_endpoint_strips_sensitive_url_parts_before_logging(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'CSP violation reported'
                    && ($context['document_uri'] ?? null) === 'https://satflux.io/password/reset'
                    && ($context['source_file'] ?? null) === 'https://satflux.io/auth/verify-email/123/abcdef'
                    && ! str_contains(json_encode($context), 'reset-token')
                    && ! str_contains(json_encode($context), 'user@example.com')
                    && ! str_contains(json_encode($context), 'signed-secret');
            });

        $this->call('POST', '/api/csp-report', [], [], [], [
            'CONTENT_TYPE' => 'application/csp-report',
        ], json_encode([
            'csp-report' => [
                'document-uri' => 'https://satflux.io/password/reset?token=reset-token&email=user@example.com',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'data:text/html,<script>secret</script>',
                'source-file' => 'https://satflux.io/auth/verify-email/123/abcdef?signature=signed-secret&expires=999',
            ],
        ]))->assertStatus(204);
    }

    #[Test]
    public function matomo_origin_is_added_to_script_src_when_configured(): void
    {
        config([
            'security.csp.enabled' => true,
            'security.csp.report_only' => false,
            'services.matomo.url' => 'https://analytics.example.com/matomo',
        ]);

        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match('/script-src ([^;]+)/', $policy, $m);
        $this->assertStringContainsString('https://analytics.example.com', $m[1] ?? '');
    }

    #[Test]
    public function frame_src_allows_the_btcpay_origin_for_the_pos_embed(): void
    {
        config([
            'security.csp.enabled' => true,
            'security.csp.report_only' => false,
            'services.btcpay.public_url' => 'https://btcpay.example.com/some/path',
        ]);

        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $frameSrc = collect(explode(';', $csp))
            ->map(fn (string $directive) => trim($directive))
            ->first(fn (string $directive) => str_starts_with($directive, 'frame-src'));

        $this->assertNotNull($frameSrc, 'frame-src directive missing: '.$csp);
        $this->assertStringContainsString('https://btcpay.example.com', $frameSrc);
        $this->assertStringNotContainsString('/some/path', $frameSrc);
    }

    #[Test]
    public function frame_src_falls_back_to_the_base_url_like_api_config(): void
    {
        config([
            'security.csp.enabled' => true,
            'security.csp.report_only' => false,
            'services.btcpay.public_url' => '',
            'services.btcpay.base_url' => 'https://btcpay-internal.example.com',
        ]);

        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $frameSrc = collect(explode(';', $csp))
            ->map(fn (string $directive) => trim($directive))
            ->first(fn (string $directive) => str_starts_with($directive, 'frame-src'));

        $this->assertNotNull($frameSrc, 'frame-src directive missing: '.$csp);
        $this->assertStringContainsString('https://btcpay-internal.example.com', $frameSrc);
    }
}
