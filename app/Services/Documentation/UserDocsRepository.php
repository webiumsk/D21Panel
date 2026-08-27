<?php

namespace App\Services\Documentation;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads user-facing documentation from repo Markdown (docs/user), so the docs
 * live and version alongside the code instead of a database CMS. Files are
 * organized as docs/user/<locale>/<slug>.md with YAML front-matter
 * (title, category, order, meta_description, updated); en is the canonical
 * slug set and the per-article fallback locale. Category metadata lives in
 * docs/user/categories.yaml.
 *
 * The rendered HTML is escaped/safe (html_input=escape) and the frontend
 * additionally sanitizes it with DOMPurify, so untrusted raw HTML never
 * reaches the page.
 */
class UserDocsRepository
{
    /** Locales with authored docs; mirrors the app UI locales. */
    public const SUPPORTED_LOCALES = ['en', 'sk', 'es', 'de', 'cs'];

    public const FALLBACK_LOCALE = 'en';

    private string $baseDir;

    private MarkdownConverter $converter;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? base_path('docs/user');

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new FrontMatterExtension);
        $this->converter = new MarkdownConverter($environment);
    }

    public function normalizeLocale(?string $locale): string
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::FALLBACK_LOCALE;
    }

    /** Single article for a slug in the given locale, or null if unknown. */
    public function article(string $slug, string $locale): ?array
    {
        $path = $this->filePath($slug, $this->normalizeLocale($locale));
        if ($path === null) {
            return null;
        }

        [$frontMatter, $html] = $this->parse($path);
        $article = $this->assemble($slug, $frontMatter, $html, $this->normalizeLocale($locale));
        unset($article['order']);

        return $article;
    }

    /**
     * Published articles, optionally filtered by category slug and a free-text
     * search over title + body. Ordered by category order, then article order.
     */
    public function articles(string $locale, ?string $categoryId = null, ?string $search = null): array
    {
        $locale = $this->normalizeLocale($locale);

        $articles = [];
        foreach ($this->slugs() as $slug) {
            $path = $this->filePath($slug, $locale);
            if ($path === null) {
                continue;
            }
            [$frontMatter, $html] = $this->parse($path);
            $article = $this->assemble($slug, $frontMatter, $html, $locale);

            if ($categoryId !== null && $categoryId !== '' && ($article['category']['id'] ?? null) !== $categoryId) {
                continue;
            }
            if ($search !== null && $search !== '') {
                $haystack = mb_strtolower($article['title'].' '.strip_tags($article['content']));
                if (! str_contains($haystack, mb_strtolower($search))) {
                    continue;
                }
            }
            $articles[] = $article;
        }

        $categoryOrder = [];
        foreach ($this->categories($locale) as $index => $category) {
            $categoryOrder[$category['slug']] = $index;
        }
        usort($articles, function ($a, $b) use ($categoryOrder) {
            $ca = $categoryOrder[$a['category']['slug'] ?? ''] ?? PHP_INT_MAX;
            $cb = $categoryOrder[$b['category']['slug'] ?? ''] ?? PHP_INT_MAX;

            return [$ca, $a['order'], $a['title']] <=> [$cb, $b['order'], $b['title']];
        });

        return array_map(function ($article) {
            unset($article['order']);

            return $article;
        }, $articles);
    }

    /** Categories from the manifest, localized and ordered. */
    public function categories(string $locale): array
    {
        $locale = $this->normalizeLocale($locale);
        $manifest = $this->baseDir.'/categories.yaml';
        if (! is_file($manifest)) {
            return [];
        }

        $data = Yaml::parseFile($manifest);
        $rows = is_array($data['categories'] ?? null) ? $data['categories'] : [];

        $categories = [];
        foreach ($rows as $index => $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $categories[] = [
                'id' => $slug,
                'slug' => $slug,
                'name' => $this->localized($row['name'] ?? null, $locale) ?? $slug,
                'description' => $this->localized($row['description'] ?? null, $locale),
                'order' => (int) ($row['order'] ?? $index),
            ];
        }
        usort($categories, fn ($a, $b) => $a['order'] <=> $b['order']);

        return array_map(function ($category) {
            unset($category['order']);

            return $category;
        }, $categories);
    }

    /** Canonical slug set = the Markdown files in the fallback (en) directory. */
    private function slugs(): array
    {
        $dir = $this->baseDir.'/'.self::FALLBACK_LOCALE;
        if (! is_dir($dir)) {
            return [];
        }
        $slugs = [];
        foreach (glob($dir.'/*.md') ?: [] as $path) {
            $slugs[] = basename($path, '.md');
        }
        sort($slugs);

        return $slugs;
    }

    /** Localized file for a slug, falling back to en; null if neither exists. */
    private function filePath(string $slug, string $locale): ?string
    {
        // Reject traversal - slugs are single path segments only.
        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            return null;
        }
        $localized = $this->baseDir.'/'.$locale.'/'.$slug.'.md';
        if (is_file($localized)) {
            return $localized;
        }
        $fallback = $this->baseDir.'/'.self::FALLBACK_LOCALE.'/'.$slug.'.md';

        return is_file($fallback) ? $fallback : null;
    }

    /** @return array{0: array<string,mixed>, 1: string} front-matter + rendered HTML */
    private function parse(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $rendered = $this->converter->convert($raw);
        $frontMatter = $rendered instanceof RenderedContentWithFrontMatter ? $rendered->getFrontMatter() : [];

        return [is_array($frontMatter) ? $frontMatter : [], $rendered->getContent()];
    }

    /** Build the API-shaped article record (category resolved, order kept for sorting). */
    private function assemble(string $slug, array $frontMatter, string $html, string $locale): array
    {
        $categorySlug = (string) ($frontMatter['category'] ?? '');
        $category = null;
        if ($categorySlug !== '') {
            foreach ($this->categories($locale) as $candidate) {
                if ($candidate['slug'] === $categorySlug) {
                    $category = ['id' => $candidate['id'], 'slug' => $candidate['slug'], 'name' => $candidate['name']];
                    break;
                }
            }
        }

        $article = [
            'id' => $slug,
            'slug' => $slug,
            'title' => (string) ($frontMatter['title'] ?? $slug),
            'content' => $html,
            'meta_description' => isset($frontMatter['meta_description']) ? (string) $frontMatter['meta_description'] : null,
            'category' => $category,
            'order' => (int) ($frontMatter['order'] ?? 0),
        ];
        if (! empty($frontMatter['updated'])) {
            $article['updated_at'] = (string) $frontMatter['updated'];
        }

        return $article;
    }

    private function localized(mixed $map, string $locale): ?string
    {
        if (is_string($map)) {
            return $map;
        }
        if (! is_array($map)) {
            return null;
        }

        return $map[$locale] ?? $map[self::FALLBACK_LOCALE] ?? null;
    }
}
