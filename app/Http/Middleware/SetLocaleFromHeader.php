<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Select the response locale from Accept-Language: the first supported base language wins,
 * otherwise the fallback locale (English).
 */
class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $supported = config('app.supported_locales');

        // getLanguages() is already ordered by q-value.
        foreach ($request->getLanguages() as $language) {
            $base = strtolower(preg_split('/[_-]/', $language)[0]);

            if (in_array($base, $supported, true)) {
                return $base;
            }
        }

        return config('app.fallback_locale');
    }
}
