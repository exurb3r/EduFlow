<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the saved color palette to the root view.
 *
 * The palette is chosen in settings → Personalization and applies to the whole
 * app, so it must survive a hard refresh — otherwise the server renders the
 * default palette and the portal flashes on first paint. The cookie is written
 * unencrypted by the client and is listed in EncryptCookies::$except so it
 * arrives here intact; the class is then rendered straight onto <html>.
 *
 * The whitelist mirrors the ids in resources/js/conf/themes.ts. Keep the two in
 * step: an id missing here silently falls back to the default palette.
 */
class HandleColorTheme
{
    private const VALID = ['default', 'rose', 'ocean', 'sage-garden', 'claude'];

    public function handle(Request $request, Closure $next): Response
    {
        $theme = $request->cookie('color-theme');

        View::share(
            'colorTheme',
            is_string($theme) && in_array($theme, self::VALID, true) ? $theme : 'default',
        );

        return $next($request);
    }
}
