<?php

namespace Tests\Feature;

use App\Services\Documentation\UserDocsRepository;
use Tests\TestCase;

class DocumentationControllerTest extends TestCase
{
    private string $docsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->docsDir = sys_get_temp_dir().'/satflux-docs-'.uniqid();
        @mkdir($this->docsDir.'/en', 0777, true);
        @mkdir($this->docsDir.'/sk', 0777, true);

        file_put_contents($this->docsDir.'/categories.yaml', <<<'YAML'
        categories:
          - slug: getting-started
            order: 1
            name:
              en: Getting started
              sk: Začíname
          - slug: invoicing
            order: 2
            name:
              en: Invoicing
        YAML);

        file_put_contents($this->docsDir.'/en/intro.md', <<<'MD'
        ---
        title: Introduction
        category: getting-started
        order: 1
        meta_description: Start here.
        ---

        # Introduction

        Welcome to **Satflux**. It has a passkey feature.

        | A | B |
        |---|---|
        | 1 | 2 |
        MD);

        file_put_contents($this->docsDir.'/sk/intro.md', <<<'MD'
        ---
        title: Úvod
        category: getting-started
        order: 1
        ---

        # Úvod

        Vitajte v Satfluxe.
        MD);

        // Only in en - exercises the per-article locale fallback.
        file_put_contents($this->docsDir.'/en/billing.md', <<<'MD'
        ---
        title: Billing
        category: invoicing
        order: 1
        ---

        # Billing

        Invoices and payments.
        MD);

        $this->app->instance(UserDocsRepository::class, new UserDocsRepository($this->docsDir));
    }

    protected function tearDown(): void
    {
        foreach (['en/intro.md', 'sk/intro.md', 'en/billing.md', 'categories.yaml'] as $file) {
            @unlink($this->docsDir.'/'.$file);
        }
        @rmdir($this->docsDir.'/en');
        @rmdir($this->docsDir.'/sk');
        @rmdir($this->docsDir);

        parent::tearDown();
    }

    public function test_index_lists_articles_and_categories_from_files(): void
    {
        $response = $this->getJson('/api/documentation?locale=en');

        $response->assertOk();
        $data = $response->json('data');
        $slugs = array_column($data, 'slug');

        $this->assertContains('intro', $slugs);
        $this->assertContains('billing', $slugs);
        // Category order (getting-started before invoicing) then article order.
        $this->assertSame('intro', $slugs[0]);
        $this->assertSame(['getting-started', 'invoicing'], array_column($response->json('categories'), 'slug'));
    }

    public function test_show_renders_markdown_to_html(): void
    {
        $response = $this->getJson('/api/documentation/intro?locale=en');

        $response->assertOk();
        $content = $response->json('data.content');
        $this->assertStringContainsString('<h1>Introduction</h1>', $content);
        $this->assertStringContainsString('<strong>Satflux</strong>', $content);
        // GitHub-flavored: tables render.
        $this->assertStringContainsString('<table>', $content);
        $this->assertSame('Introduction', $response->json('data.title'));
        $this->assertSame('getting-started', $response->json('data.category.id'));
    }

    public function test_localized_content_is_served_when_present(): void
    {
        $response = $this->getJson('/api/documentation/intro?locale=sk');

        $response->assertOk();
        $this->assertSame('Úvod', $response->json('data.title'));
        // Category name is localized too.
        $this->assertSame('Začíname', $response->json('data.category.name'));
    }

    public function test_missing_translation_falls_back_to_english(): void
    {
        // billing.md only exists in en.
        $response = $this->getJson('/api/documentation/billing?locale=sk');

        $response->assertOk();
        $this->assertSame('Billing', $response->json('data.title'));
    }

    public function test_unsupported_cz_locale_does_not_error_and_cs_is_supported(): void
    {
        // cz was a legacy typo for cs - it must not be a supported locale.
        $this->assertNotContains('cz', UserDocsRepository::SUPPORTED_LOCALES);
        $this->assertContains('cs', UserDocsRepository::SUPPORTED_LOCALES);

        $this->getJson('/api/documentation/intro?locale=cz')->assertOk();
        $this->getJson('/api/documentation/intro?locale=cs')->assertOk();
    }

    public function test_search_filters_by_title_and_body(): void
    {
        $response = $this->getJson('/api/documentation?locale=en&search=passkey');

        $response->assertOk();
        $slugs = array_column($response->json('data'), 'slug');
        $this->assertSame(['intro'], $slugs);
    }

    public function test_category_filter(): void
    {
        $response = $this->getJson('/api/documentation?locale=en&category_id=invoicing');

        $response->assertOk();
        $this->assertSame(['billing'], array_column($response->json('data'), 'slug'));
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->getJson('/api/documentation/does-not-exist')->assertNotFound();
    }
}
