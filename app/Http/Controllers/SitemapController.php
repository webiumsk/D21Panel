<?php

namespace App\Http\Controllers;

use App\Services\Documentation\UserDocsRepository;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Generate XML sitemap for SEO.
     */
    public function index(UserDocsRepository $docs): Response
    {
        $baseUrl = rtrim(config('app.url'), '/');

        $staticUrls = [
            ['loc' => $baseUrl.'/', 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => $baseUrl.'/documentation', 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => $baseUrl.'/faq', 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => $baseUrl.'/support', 'priority' => '0.8', 'changefreq' => 'monthly'],
            ['loc' => $baseUrl.'/login', 'priority' => '0.5', 'changefreq' => 'monthly'],
            ['loc' => $baseUrl.'/register', 'priority' => '0.8', 'changefreq' => 'monthly'],
        ];

        $articleUrls = array_map(fn ($article) => [
            'loc' => $baseUrl.'/documentation/'.$article['slug'],
            'priority' => '0.7',
            'changefreq' => 'monthly',
            'lastmod' => $article['updated_at'] ?? null,
        ], $docs->articles(UserDocsRepository::FALLBACK_LOCALE));

        $urls = array_merge($staticUrls, $articleUrls);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.htmlspecialchars($url['loc']).'</loc>'."\n";
            $xml .= '    <priority>'.($url['priority'] ?? '0.5').'</priority>'."\n";
            $xml .= '    <changefreq>'.($url['changefreq'] ?? 'monthly').'</changefreq>'."\n";
            if (! empty($url['lastmod'])) {
                $xml .= '    <lastmod>'.$url['lastmod'].'</lastmod>'."\n";
            }
            $xml .= '  </url>'."\n";
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
