<?php

namespace Database\Seeders;

use App\Enums\RegWatchSourceType;
use App\Enums\RegWatchTopic;
use App\Models\RegWatchJurisdiction;
use App\Models\RegWatchRule;
use App\Models\RegWatchSource;
use Illuminate\Database\Seeder;

/**
 * Seeds the RegWatch knowledge-base skeleton (docs/LEGAL.md): jurisdictions,
 * official sources (SK/CZ URLs verified 2026-07-21, DE/AT/CH/HU/PL
 * 2026-07-22) and
 * PLACEHOLDER rules only. Never seed concrete tax rates, thresholds,
 * deadlines or rule wording - a human fills those in after verifying the
 * official source, stamping verified_on. Idempotent (updateOrCreate for
 * structure, firstOrCreate for rules so re-runs never touch verified
 * content); run standalone:
 *
 *   php artisan db:seed --class=RegWatchSeeder
 */
class RegWatchSeeder extends Seeder
{
    private const PLACEHOLDER_RULE_TEXT = RegWatchRule::PLACEHOLDER_RULE_TEXT;

    public function run(): void
    {
        $jurisdictions = $this->seedJurisdictions();
        $sources = $this->seedSources($jurisdictions);
        $this->seedPlaceholderRules($jurisdictions, $sources);
    }

    /** @return array<string, RegWatchJurisdiction> keyed by code */
    private function seedJurisdictions(): array
    {
        $rows = [
            ['code' => 'SK', 'name' => 'Slovakia'],
            ['code' => 'CZ', 'name' => 'Czechia'],
            ['code' => 'DE', 'name' => 'Germany'],
            ['code' => 'AT', 'name' => 'Austria'],
            ['code' => 'CH', 'name' => 'Switzerland'],
            ['code' => 'HU', 'name' => 'Hungary'],
            ['code' => 'PL', 'name' => 'Poland'],
            ['code' => 'US-WY', 'name' => 'United States - Wyoming'],
        ];

        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['code']] = RegWatchJurisdiction::updateOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'active' => true],
            );
        }

        return $byCode;
    }

    /**
     * Monitored sources for all seven EU/CH jurisdictions. Official portals
     * only; the CZ URLs are canonical (e-sbirka.gov.cz and
     * financnisprava.gov.cz are the targets of permanent redirects from the
     * legacy .cz domains). AT RIS, HU njt.hu and PL ISAP are deliberately
     * absent - they block automated fetches (503/reset/CAPTCHA, verified
     * 2026-07-22), so the cron could never monitor them; the tax authority
     * covers those jurisdictions' topics instead.
     *
     * @param  array<string, RegWatchJurisdiction>  $jurisdictions
     * @return array<string, RegWatchSource> keyed by slug
     */
    private function seedSources(array $jurisdictions): array
    {
        $rows = [
            [
                'slug' => 'sk-slov-lex',
                'jurisdiction' => 'SK',
                'name' => 'Slov-Lex - právny a informačný portál SR',
                'url' => 'https://www.slov-lex.sk/',
                'type' => RegWatchSourceType::LegalRegister,
            ],
            [
                'slug' => 'sk-financna-sprava',
                'jurisdiction' => 'SK',
                'name' => 'Finančná správa SR',
                'url' => 'https://www.financnasprava.sk/',
                'type' => RegWatchSourceType::TaxAuthority,
            ],
            [
                'slug' => 'cz-e-sbirka',
                'jurisdiction' => 'CZ',
                'name' => 'e-Sbírka - elektronická Sbírka zákonů ČR',
                'url' => 'https://e-sbirka.gov.cz/',
                'type' => RegWatchSourceType::LegalRegister,
            ],
            [
                'slug' => 'cz-financni-sprava',
                'jurisdiction' => 'CZ',
                'name' => 'Finanční správa ČR',
                'url' => 'https://financnisprava.gov.cz/',
                'type' => RegWatchSourceType::TaxAuthority,
            ],
            [
                'slug' => 'de-gesetze-im-internet',
                'jurisdiction' => 'DE',
                'name' => 'Gesetze im Internet (BMJ) - UStG a spol.',
                'url' => 'https://www.gesetze-im-internet.de/',
                'type' => RegWatchSourceType::LegalRegister,
            ],
            [
                'slug' => 'de-bzst',
                'jurisdiction' => 'DE',
                'name' => 'Bundeszentralamt für Steuern (BZSt)',
                'url' => 'https://www.bzst.de/',
                'type' => RegWatchSourceType::TaxAuthority,
            ],
            [
                'slug' => 'at-bmf',
                'jurisdiction' => 'AT',
                'name' => 'Bundesministerium für Finanzen (AT)',
                'url' => 'https://www.bmf.gv.at/',
                'type' => RegWatchSourceType::TaxAuthority,
            ],
            [
                'slug' => 'ch-fedlex',
                'jurisdiction' => 'CH',
                'name' => 'Fedlex - platforma spolkového práva CH',
                'url' => 'https://www.fedlex.admin.ch/',
                'type' => RegWatchSourceType::LegalRegister,
            ],
            [
                'slug' => 'ch-estv',
                'jurisdiction' => 'CH',
                'name' => 'Eidgenössische Steuerverwaltung (ESTV)',
                'url' => 'https://www.estv.admin.ch/',
                'type' => RegWatchSourceType::TaxAuthority,
            ],
            [
                'slug' => 'hu-nav',
                'jurisdiction' => 'HU',
                'name' => 'Nemzeti Adó- és Vámhivatal (NAV)',
                'url' => 'https://nav.gov.hu/',
                'type' => RegWatchSourceType::TaxAuthority,
            ],
            [
                'slug' => 'pl-dziennik-ustaw',
                'jurisdiction' => 'PL',
                'name' => 'Dziennik Ustaw RP',
                'url' => 'https://dziennikustaw.gov.pl/',
                'type' => RegWatchSourceType::LegalRegister,
            ],
            [
                'slug' => 'pl-podatki',
                'jurisdiction' => 'PL',
                'name' => 'podatki.gov.pl (Ministerstwo Finansów)',
                'url' => 'https://www.podatki.gov.pl/',
                'type' => RegWatchSourceType::TaxAuthority,
            ],
        ];

        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row['slug']] = RegWatchSource::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'jurisdiction_id' => $jurisdictions[$row['jurisdiction']]->id,
                    'name' => $row['name'],
                    'url' => $row['url'],
                    'type' => $row['type'],
                    'active' => true,
                ],
            );
        }

        return $bySlug;
    }

    /**
     * PLACEHOLDER rules for the phase-1 topics in all seven EU/CH
     * jurisdictions. Deliberately no
     * rates, thresholds or deadlines - titles only name the topic, rule_text
     * is a TODO and verified_on stays NULL until a human verifies the rule
     * against source_url.
     *
     * @param  array<string, RegWatchJurisdiction>  $jurisdictions
     * @param  array<string, RegWatchSource>  $sources
     */
    private function seedPlaceholderRules(array $jurisdictions, array $sources): void
    {
        // Topic -> [title, source slug per jurisdiction, optional deep URL].
        // Tax-administration topics point at the tax authority; law-text
        // topics (ReverseCharge, Archiving) point at the legal register -
        // except AT and HU, which have no monitorable legal register (RIS
        // and njt.hu block automated fetches), so those topics fall back to
        // the tax authority (at-bmf, hu-nav). Deep URLs are used only where
        // they were verified against the live site (SK paths 2026-07-21,
        // DE paths 2026-07-22).
        $topics = [
            RegWatchTopic::VatRegistration->value => [
                'title' => 'DPH - registračná povinnosť a prahy',
                'source' => ['SK' => 'sk-financna-sprava', 'CZ' => 'cz-financni-sprava', 'DE' => 'de-gesetze-im-internet', 'AT' => 'at-bmf', 'CH' => 'ch-estv', 'HU' => 'hu-nav', 'PL' => 'pl-podatki'],
                'url' => [
                    'SK' => 'https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-pridanej-hodnoty/registracna-povinnost-pre-dph',
                    // §19 UStG - Kleinunternehmer (thresholds live here).
                    'DE' => 'https://www.gesetze-im-internet.de/ustg_1980/__19.html',
                ],
            ],
            RegWatchTopic::ReverseCharge->value => [
                'title' => 'Reverse charge - cezhraničné B2B služby',
                'source' => ['SK' => 'sk-slov-lex', 'CZ' => 'cz-e-sbirka', 'DE' => 'de-gesetze-im-internet', 'AT' => 'at-bmf', 'CH' => 'ch-fedlex', 'HU' => 'hu-nav', 'PL' => 'pl-dziennik-ustaw'],
                'url' => [],
            ],
            RegWatchTopic::Oss->value => [
                'title' => 'OSS režim (One Stop Shop)',
                'source' => ['SK' => 'sk-slov-lex', 'CZ' => 'cz-e-sbirka', 'DE' => 'de-bzst', 'AT' => 'at-bmf', 'CH' => 'ch-estv', 'HU' => 'hu-nav', 'PL' => 'pl-podatki'],
                'url' => [
                    // BZSt Umsatzsteuer section carries the OSS procedures.
                    'DE' => 'https://www.bzst.de/DE/Unternehmen/Umsatzsteuer/umsatzsteuer_node.html',
                ],
            ],
            RegWatchTopic::UsLlcIncome->value => [
                'title' => 'Príjem z US LLC v daňovom priznaní',
                'source' => ['SK' => 'sk-financna-sprava', 'CZ' => 'cz-financni-sprava', 'DE' => 'de-bzst', 'AT' => 'at-bmf', 'CH' => 'ch-estv', 'HU' => 'hu-nav', 'PL' => 'pl-podatki'],
                'url' => [
                    'SK' => 'https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-prijmov/',
                ],
            ],
            RegWatchTopic::IncomeTax->value => [
                'title' => 'Daň z príjmov / CIT',
                'source' => ['SK' => 'sk-financna-sprava', 'CZ' => 'cz-financni-sprava', 'DE' => 'de-gesetze-im-internet', 'AT' => 'at-bmf', 'CH' => 'ch-estv', 'HU' => 'hu-nav', 'PL' => 'pl-podatki'],
                'url' => [
                    'SK' => 'https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-prijmov/',
                ],
            ],
            RegWatchTopic::Archiving->value => [
                'title' => 'Archivácia účtovných a daňových dokladov',
                'source' => ['SK' => 'sk-slov-lex', 'CZ' => 'cz-e-sbirka', 'DE' => 'de-gesetze-im-internet', 'AT' => 'at-bmf', 'CH' => 'ch-fedlex', 'HU' => 'hu-nav', 'PL' => 'pl-dziennik-ustaw'],
                'url' => [],
            ],
        ];

        foreach (['SK', 'CZ', 'DE', 'AT', 'CH', 'HU', 'PL'] as $code) {
            foreach ($topics as $topic => $spec) {
                $source = $sources[$spec['source'][$code]];
                // firstOrCreate, never updateOrCreate: rules are the human-
                // edited source of truth (docs/LEGAL.md) - re-running the
                // seeder must only add missing placeholders and can never
                // overwrite verified rule content back to a placeholder.
                RegWatchRule::firstOrCreate(
                    ['slug' => strtolower($code).'-'.str_replace('_', '-', $topic)],
                    [
                        'jurisdiction_id' => $jurisdictions[$code]->id,
                        'source_id' => $source->id,
                        'topic' => $topic,
                        'title' => $spec['title'],
                        'rule_text' => self::PLACEHOLDER_RULE_TEXT,
                        'source_url' => $spec['url'][$code] ?? $source->url,
                        'verified_on' => null,
                        'effective_from' => null,
                    ],
                );
            }
        }
    }
}
