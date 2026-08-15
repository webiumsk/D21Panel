<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Supported locales
        $supportedLocales = ['en', 'sk', 'cs', 'de', 'es'];
        $defaultLocale = 'en';

        $locale = null;

        // Get locale from session (set by API endpoint) - only if session is available
        if ($request->hasSession()) {
            $locale = $request->session()->get('locale');
        }

        // If no locale in session, try to get from Accept-Language header
        if (! $locale && $request->hasHeader('Accept-Language')) {
            $preferredLocales = $request->getLanguages();
            foreach ($preferredLocales as $preferredLocale) {
                // Extract language code (e.g., 'en' from 'en-US')
                $langCode = strtolower(substr($preferredLocale, 0, 2));
                if (in_array($langCode, $supportedLocales)) {
                    $locale = $langCode;
                    break;
                }
            }
        }

        // Fallback to default if locale is not supported
        if (! $locale || ! in_array($locale, $supportedLocales)) {
            $locale = $defaultLocale;
        }

        // Set the application locale
        app()->setLocale($locale);

        return $next($request);
    }
}
