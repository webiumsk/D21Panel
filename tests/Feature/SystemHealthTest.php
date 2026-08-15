<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeExternalChecks(): void
    {
        Http::fake([
            '*/api/v1/health' => Http::response('', 200),
            '*/usage/*' => Http::response('{}', 404),
        ]);
    }

    #[Test]
    public function admin_can_read_the_system_health_report(): void
    {
        $this->fakeExternalChecks();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/system-health')
            ->assertOk();

        $response->assertJsonPath('data.checks.database.ok', true);
        foreach (SystemHealthService::CHECKS as $check) {
            $response->assertJsonStructure(['data' => ['checks' => [$check => ['ok', 'detail', 'duration_ms']]]]);
        }
    }

    #[Test]
    public function non_admins_cannot_read_the_report(): void
    {
        $this->fakeExternalChecks();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/admin/system-health')
            ->assertForbidden();
    }

    #[Test]
    public function the_health_command_succeeds_on_a_healthy_system(): void
    {
        $this->fakeExternalChecks();

        $this->artisan('system:health-check')->assertSuccessful();
    }

    #[Test]
    public function the_health_command_fails_when_btcpay_is_down(): void
    {
        Http::fake([
            '*/api/v1/health' => Http::response('', 503),
            '*/usage/*' => Http::response('{}', 404),
        ]);

        $this->artisan('system:health-check')->assertFailed();
    }

    #[Test]
    public function old_failed_jobs_do_not_fail_the_queue_check(): void
    {
        $this->fakeExternalChecks();
        $this->seedFailedJobs(SystemHealthService::MAX_FAILED_JOBS + 40, now()->subDays(30));

        $checks = app(SystemHealthService::class)->runChecks();

        $this->assertTrue($checks['queue']['ok']);
        $this->assertStringContainsString('(50 total)', $checks['queue']['detail']);
    }

    #[Test]
    public function a_burst_of_recent_failed_jobs_fails_the_queue_check(): void
    {
        $this->fakeExternalChecks();
        $this->seedFailedJobs(SystemHealthService::MAX_FAILED_JOBS + 1, now()->subHour());

        $checks = app(SystemHealthService::class)->runChecks();

        $this->assertFalse($checks['queue']['ok']);
    }

    protected function seedFailedJobs(int $count, \DateTimeInterface $failedAt): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => '{}',
                'exception' => 'Test exception',
                'failed_at' => $failedAt,
            ];
        }
        DB::table('failed_jobs')->insert($rows);
    }
}
