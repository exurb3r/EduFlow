<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * A panel heading that navigates to the page where the thing is managed.
 *
 * Filament's `Stat` has `->url()` but `ChartWidget` and `TableWidget` only
 * accept a heading, so a clickable heading is the only way to make those two
 * navigate. Rendering the label as an anchor keeps the affordance visible
 * instead of hiding it behind a hover state nobody discovers.
 */
final readonly class LinkedHeading
{
    public static function make(?string $label, ?string $url): string|Htmlable|null
    {
        if ($label === null || $label === '') {
            return null;
        }

        if ($url === null || $url === '') {
            return $label;
        }

        return new HtmlString(sprintf(
            '<a href="%s" class="fi-finance-heading-link">%s</a>',
            e($url),
            e($label),
        ));
    }
}
