<?php

namespace App\Http\Controllers;

use App\Services\Documentation\UserDocsRepository;
use Illuminate\Http\Request;

class DocumentationController extends Controller
{
    public function __construct(private UserDocsRepository $docs) {}

    /** Query param wins over the app locale; unknown locales fall back to en. */
    private function resolveLocale(Request $request): string
    {
        $locale = $request->query('locale');
        if (is_string($locale) && in_array($locale, UserDocsRepository::SUPPORTED_LOCALES, true)) {
            return $locale;
        }

        return $this->docs->normalizeLocale(app()->getLocale());
    }

    /** List published articles (optionally filtered by category / search) + categories. */
    public function index(Request $request)
    {
        $locale = $this->resolveLocale($request);
        $categoryId = $request->query('category_id');
        $search = $request->query('search');

        return response()->json([
            'data' => $this->docs->articles(
                $locale,
                is_string($categoryId) ? $categoryId : null,
                is_string($search) ? $search : null,
            ),
            'categories' => $this->docs->categories($locale),
        ]);
    }

    /** A single article by slug (404 if unknown). */
    public function show(Request $request, string $slug)
    {
        $locale = $this->resolveLocale($request);
        $article = $this->docs->article($slug, $locale);
        if ($article === null) {
            abort(404);
        }

        return response()->json(['data' => $article]);
    }
}
