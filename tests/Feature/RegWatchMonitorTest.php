<?php

namespace Tests\Feature;

use App\Enums\RegWatchChangeStatus;
use App\Enums\RegWatchSourceType;
use App\Jobs\RegWatch\CheckRegWatchSource;
use App\Models\RegWatchChange;
use App\Models\RegWatchJurisdiction;
use App\Models\RegWatchRule;
use App\Models\RegWatchSource;
use App\Notifications\RegWatchChangeDetected;
use App\Services\RegWatch\SourceMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegWatchMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'regwatch.enabled' => true,
            'regwatch.notify_email' => null,
            'regwatch.classifier.api_key' => null,
            'regwatch.classifier.retry_delay_ms' => 0,
            'regwatch.snapshot_disk' => 'local',
        ]);
        Storage::fake('local');
        Notification::fake();
    }

    private function makeSource(): RegWatchSource
    {
        $jurisdiction = RegWatchJurisdiction::create([
            'code' => 'SK',
            'name' => 'Slovakia',
            'active' => true,
        ]);

        return RegWatchSource::create([
            'jurisdiction_id' => $jurisdiction->id,
            'slug' => 'sk-test-source',
            'name' => 'Test source',
            'url' => 'https://source.test/page',
            'type' => RegWatchSourceType::TaxAuthority,
            'active' => true,
        ]);
    }

    private function monitor(): SourceMonitor
    {
        return app(SourceMonitor::class);
    }

    #[Test]
    public function first_run_stores_a_baseline_without_recording_a_change(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::response('<html><body><p>VAT page v1</p></body></html>')]);

        $change = $this->monitor()->check($source);

        $this->assertNull($change);
        $this->assertSame(0, RegWatchChange::count());
        $source->refresh();
        $this->assertNotNull($source->last_snapshot_hash);
        $this->assertNotNull($source->last_checked_at);
        Storage::disk('local')->assertExists('regwatch/snapshots/sk-test-source.txt');
    }

    #[Test]
    public function unchanged_content_only_touches_last_checked_at(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::response('<p>stable</p>')]);

        $this->monitor()->check($source);
        $source->refresh();
        $hash = $source->last_snapshot_hash;

        $change = $this->monitor()->check($source->refresh());

        $this->assertNull($change);
        $this->assertSame(0, RegWatchChange::count());
        $this->assertSame($hash, $source->refresh()->last_snapshot_hash);
    }

    #[Test]
    public function changed_content_records_a_new_change_with_diff_and_never_touches_rules(): void
    {
        $source = $this->makeSource();
        $rule = RegWatchRule::create([
            'jurisdiction_id' => $source->jurisdiction_id,
            'source_id' => $source->id,
            'slug' => 'sk-vat-registration',
            'topic' => 'vat_registration',
            'title' => 'DPH - registračná povinnosť a prahy',
            'rule_text' => 'TODO: overiť z oficiálneho zdroja',
            'source_url' => $source->url,
        ]);

        Http::fake(['source.test/*' => Http::sequence()
            ->push('<p>old content</p>')
            ->push('<p>new content</p>')]);
        $this->monitor()->check($source);
        $change = $this->monitor()->check($source->refresh());

        $this->assertNotNull($change);
        $this->assertSame(RegWatchChangeStatus::New, $change->status);
        $this->assertStringContainsString('- old content', (string) $change->diff);
        $this->assertStringContainsString('+ new content', (string) $change->diff);
        $this->assertNull($change->summary);
        $this->assertNull($change->classification_json);

        // The LEGAL.md invariant: monitoring never modifies rules.
        $this->assertSame('TODO: overiť z oficiálneho zdroja', $rule->refresh()->rule_text);
        $this->assertNull($rule->verified_on);
    }

    #[Test]
    public function script_and_style_churn_does_not_produce_changes(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::sequence()
            ->push('<html><head><script src="app-abc123.js"></script><style>.a{color:red}</style></head><body>content</body></html>')
            ->push('<html><head><script src="app-def456.js"></script><style>.a{color:blue}</style></head><body>content</body></html>')]);
        $this->monitor()->check($source);
        $change = $this->monitor()->check($source->refresh());

        $this->assertNull($change);
        $this->assertSame(0, RegWatchChange::count());
    }

    #[Test]
    public function minified_html_diffs_per_block_not_as_one_line(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::sequence()
            ->push('<html><body><p>stable paragraph</p><p>old paragraph</p></body></html>')
            ->push('<html><body><p>stable paragraph</p><p>new paragraph</p></body></html>')]);

        $this->monitor()->check($source);
        $change = $this->monitor()->check($source->refresh());

        $this->assertNotNull($change);
        $this->assertStringContainsString('- old paragraph', (string) $change->diff);
        $this->assertStringContainsString('+ new paragraph', (string) $change->diff);
        // The unchanged block must not appear in the diff - minified HTML
        // would otherwise collapse into a single line and diff as a whole.
        $this->assertStringNotContainsString('stable paragraph', (string) $change->diff);
    }

    #[Test]
    public function missing_snapshot_with_existing_hash_records_a_change_without_diff(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::sequence()
            ->push('<p>old</p>')
            ->push('<p>new</p>')]);

        $this->monitor()->check($source);
        // Simulate wiped storage: the hash survives in the DB, the file is gone.
        Storage::disk('local')->delete('regwatch/snapshots/sk-test-source.txt');

        $change = $this->monitor()->check($source->refresh());

        $this->assertNotNull($change);
        $this->assertStringContainsString('previous snapshot missing', (string) $change->diff);
        $this->assertNull($change->classification_json);
        Storage::disk('local')->assertExists('regwatch/snapshots/sk-test-source.txt');
    }

    #[Test]
    public function fetch_failure_leaves_the_source_untouched(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::response('down', 500)]);

        $change = $this->monitor()->check($source);

        $this->assertNull($change);
        $this->assertSame(0, RegWatchChange::count());
        $this->assertNull($source->refresh()->last_snapshot_hash);
    }

    #[Test]
    public function classifier_summary_is_stored_when_configured(): void
    {
        config(['regwatch.classifier.api_key' => 'test-key']);
        $source = $this->makeSource();

        Http::fake([
            'source.test/*' => Http::sequence()
                ->push('<p>old</p>')
                ->push('<p>new</p>'),
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'relevant' => true,
                        'confidence' => 'high',
                        'topics' => ['vat_registration'],
                        'summary' => 'Zmena na stránke o DPH.',
                    ]),
                ]],
            ]),
        ]);

        $this->monitor()->check($source);
        $change = $this->monitor()->check($source->refresh());

        $this->assertNotNull($change);
        $this->assertSame('Zmena na stránke o DPH.', $change->summary);
        $this->assertTrue((bool) ($change->classification_json['relevant'] ?? false));
        $this->assertSame(['vat_registration'], $change->classification_json['topics']);

        // The critical rule: the classifier prompt forbids adding facts. The
        // predicate must only accept the Anthropic request itself - matching
        // any other request would make the assertion vacuous.
        Http::assertSent(
            fn ($request) => str_contains($request->url(), 'api.anthropic.com')
                && str_contains(json_encode($request->data()) ?: '', 'never add legal facts'),
        );
    }

    #[Test]
    public function classifier_failure_still_records_the_change(): void
    {
        config(['regwatch.classifier.api_key' => 'test-key']);
        $source = $this->makeSource();

        Http::fake([
            'source.test/*' => Http::sequence()->push('<p>old</p>')->push('<p>new</p>'),
            'api.anthropic.com/*' => Http::response('overloaded', 529),
        ]);

        $this->monitor()->check($source);
        $change = $this->monitor()->check($source->refresh());

        $this->assertNotNull($change);
        $this->assertNull($change->summary);
        $this->assertNull($change->classification_json);
    }

    #[Test]
    public function detected_change_notifies_the_configured_address(): void
    {
        config(['regwatch.notify_email' => 'alerts@example.test']);
        $source = $this->makeSource();

        Http::fake(['source.test/*' => Http::sequence()->push('<p>old</p>')->push('<p>new</p>')]);
        $this->monitor()->check($source);
        $this->monitor()->check($source->refresh());

        Notification::assertSentOnDemand(
            RegWatchChangeDetected::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'alerts@example.test',
        );
    }

    #[Test]
    public function no_notification_without_configured_address(): void
    {
        $source = $this->makeSource();

        Http::fake(['source.test/*' => Http::sequence()->push('<p>old</p>')->push('<p>new</p>')]);
        $this->monitor()->check($source);
        $this->monitor()->check($source->refresh());

        $this->assertSame(1, RegWatchChange::count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function monitor_command_dispatches_a_job_per_active_source(): void
    {
        Queue::fake();
        $source = $this->makeSource();
        RegWatchSource::create([
            'jurisdiction_id' => $source->jurisdiction_id,
            'slug' => 'sk-inactive',
            'name' => 'Inactive',
            'url' => 'https://source.test/inactive',
            'type' => RegWatchSourceType::LegalRegister,
            'active' => false,
        ]);

        $this->artisan('regwatch:monitor')->assertSuccessful();

        Queue::assertPushed(CheckRegWatchSource::class, 1);
        Queue::assertPushed(CheckRegWatchSource::class, fn ($job) => $job->sourceId === $source->id);
    }

    #[Test]
    public function monitor_command_skips_when_disabled(): void
    {
        config(['regwatch.enabled' => false]);
        Queue::fake();
        $this->makeSource();

        $this->artisan('regwatch:monitor')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function monitor_command_sync_checks_inline(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::response('<p>baseline</p>')]);

        $this->artisan('regwatch:monitor --sync')->assertSuccessful();

        $this->assertNotNull($source->refresh()->last_snapshot_hash);
    }

    #[Test]
    public function check_job_runs_the_monitor_for_its_source(): void
    {
        $source = $this->makeSource();
        Http::fake(['source.test/*' => Http::response('<p>baseline</p>')]);

        (new CheckRegWatchSource($source->id))->handle($this->monitor());

        $this->assertNotNull($source->refresh()->last_snapshot_hash);
    }
}
