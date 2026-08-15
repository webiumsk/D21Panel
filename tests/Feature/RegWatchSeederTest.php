<?php

namespace Tests\Feature;

use App\Enums\RegWatchChangeStatus;
use App\Enums\RegWatchSourceType;
use App\Enums\RegWatchTopic;
use App\Models\RegWatchChange;
use App\Models\RegWatchJurisdiction;
use App\Models\RegWatchRule;
use App\Models\RegWatchSource;
use Database\Seeders\RegWatchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegWatchSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seed_regwatch(): void
    {
        $this->seed(RegWatchSeeder::class);
    }

    #[Test]
    public function seeder_creates_jurisdictions_sources_and_placeholder_rules(): void
    {
        $this->seed_regwatch();

        $this->assertSame(8, RegWatchJurisdiction::count());
        $this->assertSame(12, RegWatchSource::count());
        $this->assertSame(42, RegWatchRule::count());

        $codes = RegWatchJurisdiction::pluck('code')->sort()->values()->all();
        $this->assertSame(['AT', 'CH', 'CZ', 'DE', 'HU', 'PL', 'SK', 'US-WY'], $codes);

        // Sources exist for all seven EU/CH jurisdictions, with official URLs.
        $slugs = RegWatchSource::pluck('slug')->sort()->values()->all();
        $this->assertSame(
            [
                'at-bmf', 'ch-estv', 'ch-fedlex', 'cz-e-sbirka', 'cz-financni-sprava',
                'de-bzst', 'de-gesetze-im-internet', 'hu-nav', 'pl-dziennik-ustaw',
                'pl-podatki', 'sk-financna-sprava', 'sk-slov-lex',
            ],
            $slugs,
        );

        // DE relationships: both sources hang off the DE jurisdiction and
        // carry the verified official URLs.
        $de = RegWatchJurisdiction::where('code', 'DE')->firstOrFail();
        $deSources = RegWatchSource::where('jurisdiction_id', $de->id)
            ->pluck('url', 'slug')->sort()->all();
        $this->assertSame([
            'de-bzst' => 'https://www.bzst.de/',
            'de-gesetze-im-internet' => 'https://www.gesetze-im-internet.de/',
        ], $deSources);

        // AT/CH/HU/PL sources: jurisdiction, type and official URL each match
        // the verified seed data.
        $expected = [
            'at-bmf' => ['AT', RegWatchSourceType::TaxAuthority, 'https://www.bmf.gv.at/'],
            'ch-fedlex' => ['CH', RegWatchSourceType::LegalRegister, 'https://www.fedlex.admin.ch/'],
            'ch-estv' => ['CH', RegWatchSourceType::TaxAuthority, 'https://www.estv.admin.ch/'],
            'hu-nav' => ['HU', RegWatchSourceType::TaxAuthority, 'https://nav.gov.hu/'],
            'pl-dziennik-ustaw' => ['PL', RegWatchSourceType::LegalRegister, 'https://dziennikustaw.gov.pl/'],
            'pl-podatki' => ['PL', RegWatchSourceType::TaxAuthority, 'https://www.podatki.gov.pl/'],
        ];
        foreach ($expected as $slug => [$code, $type, $url]) {
            $source = RegWatchSource::where('slug', $slug)->firstOrFail();
            $this->assertSame($code, $source->jurisdiction?->code, "source {$slug} jurisdiction");
            $this->assertSame($type, $source->type, "source {$slug} type");
            $this->assertSame($url, $source->url, "source {$slug} url");
        }
    }

    #[Test]
    public function all_seeded_rules_are_unverified_placeholders(): void
    {
        $this->seed_regwatch();

        // The critical LEGAL.md rule: no concrete rates/thresholds may be
        // seeded - every rule is a TODO placeholder pending human review.
        foreach (RegWatchRule::all() as $rule) {
            $this->assertNull($rule->verified_on, "rule {$rule->slug} must not be verified");
            $this->assertSame('TODO: overiť z oficiálneho zdroja', $rule->rule_text);
            $this->assertNotNull($rule->source_id);
            $this->assertStringStartsWith('https://', $rule->source_url);
        }

        // Every jurisdiction with sources carries the full phase-1 topic set.
        foreach (['SK', 'CZ', 'DE', 'AT', 'CH', 'HU', 'PL'] as $code) {
            $topics = RegWatchRule::whereHas('jurisdiction', fn ($q) => $q->where('code', $code))
                ->pluck('topic')->map(fn (RegWatchTopic $t) => $t->value)->sort()->values()->all();
            $this->assertSame(
                ['archiving', 'income_tax', 'oss', 'reverse_charge', 'us_llc_income', 'vat_registration'],
                $topics,
                "jurisdiction {$code} must carry the full topic set",
            );
        }
    }

    #[Test]
    public function seeder_is_idempotent(): void
    {
        $this->seed_regwatch();
        $this->seed_regwatch();

        $this->assertSame(8, RegWatchJurisdiction::count());
        $this->assertSame(12, RegWatchSource::count());
        $this->assertSame(42, RegWatchRule::count());
    }

    #[Test]
    public function reseeding_never_overwrites_human_verified_rules(): void
    {
        $this->seed_regwatch();

        $rule = RegWatchRule::where('slug', 'sk-vat-registration')->firstOrFail();
        $rule->forceFill([
            'rule_text' => 'Overené znenie doplnené človekom.',
            'verified_on' => '2026-07-22',
        ])->save();

        $this->seed_regwatch();

        $rule->refresh();
        $this->assertSame('Overené znenie doplnené človekom.', $rule->rule_text);
        $this->assertSame('2026-07-22', $rule->verified_on->toDateString());
    }

    #[Test]
    public function relations_and_enum_casts_work(): void
    {
        $this->seed_regwatch();

        $sk = RegWatchJurisdiction::where('code', 'SK')->firstOrFail();
        $this->assertCount(2, $sk->sources);
        $this->assertCount(6, $sk->rules);

        $source = RegWatchSource::where('slug', 'sk-financna-sprava')->firstOrFail();
        $this->assertSame(RegWatchSourceType::TaxAuthority, $source->type);
        $this->assertSame('SK', $source->jurisdiction->code);
        $this->assertTrue($source->rules->isNotEmpty());

        $rule = RegWatchRule::where('slug', 'sk-vat-registration')->firstOrFail();
        $this->assertSame(RegWatchTopic::VatRegistration, $rule->topic);

        // The changelog rows the monitoring cron will insert (status 'new').
        $change = RegWatchChange::create([
            'source_id' => $source->id,
            'rule_id' => $rule->id,
            'summary' => 'test detection',
            'detected_at' => now(),
        ]);
        $this->assertSame(RegWatchChangeStatus::New, $change->refresh()->status);
        $this->assertSame($rule->id, $change->rule->id);
        $this->assertSame($source->id, $change->source->id);
    }
}
