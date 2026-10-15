<?php

declare(strict_types=1);

use App\Filament\Finance\Widgets\ArcSettlementWidget;
use App\Filament\Finance\Widgets\AutonomyLaneWidget;
use App\Filament\Finance\Widgets\OperationsHealthWidget;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Finance overview presentation.
 *
 * The same reading rules the admin overview follows, applied to the three
 * stat bands in the finance panel. These guard the rules rather than the copy:
 * colour must encode state and never decoration, a stat tile must not carry a
 * floating icon mid-description, and a caption must stay a caption.
 *
 * The finance panel is read as evidence, so a tile that spends its colour
 * budget on decoration spends the one thing a reviewer needs to be free.
 */
beforeEach(function (): void {
    $this->finance = [
        ArcSettlementWidget::class,
        AutonomyLaneWidget::class,
        OperationsHealthWidget::class,
    ];

    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->actingAs($admin);
});

/**
 * Filament renders `->color()` onto the description paragraph, so a
 * decorative `primary` or `info` is what put blue and orange next to the
 * genuine warnings in the same row. Colour means state: green healthy, amber
 * attention, red broken, grey a plain fact.
 */
it('never uses primary or info to colour a finance stat description', function (): void {
    foreach ($this->finance as $widget) {
        expect(Livewire::test($widget)->html())
            ->not->toContain('fi-color-primary')
            ->not->toContain('fi-color-info');
    }
});

it('renders no description icon inside a finance stat tile', function (): void {
    foreach ($this->finance as $widget) {
        $html = Livewire::test($widget)->html();

        expect($html)
            ->toContain('fi-wi-stats-overview-stat-description')
            ->not->toMatch('#fi-wi-stats-overview-stat-description[^<]*<span>\s*<svg#');
    }
});

/**
 * A stat description is a caption. Filament will happily wrap three
 * sentences into it, which is what made every tile in a row a different
 * height — and truncated a real safety caveat in the process.
 */
it('keeps every finance stat description to a single short line', function (): void {
    foreach ($this->finance as $widget) {
        $method = new ReflectionMethod($widget, 'getStats');

        foreach ($method->invoke(new $widget) as $stat) {
            /** @var Stat $stat */
            expect(mb_strlen((string) $stat->getDescription()))
                ->toBeLessThanOrEqual(90, $stat->getLabel().' description is too long to stay a caption');

            expect($stat->getDescriptionIcon())->toBeNull();
        }
    }
});

/**
 * A 42-character treasury address has no wrap opportunity and pushes out of
 * the tile, across its neighbour. The value slot truncates it; nothing on the
 * overview may repeat it in full.
 */
it('never puts an untruncated address in a finance stat value or description', function (): void {
    foreach ($this->finance as $widget) {
        $method = new ReflectionMethod($widget, 'getStats');

        // See AdminOverviewPresentationTest: the element type is declared once
        // on the local, because a @var inside the loop would attach to $field.
        /** @var list<Stat> $stats */
        $stats = $method->invoke(new $widget);

        foreach ($stats as $stat) {
            foreach ([(string) $stat->getValue(), (string) $stat->getDescription()] as $field) {
                expect($field)->not->toMatch(
                    '/0x[0-9a-fA-F]{40}/',
                    $stat->getLabel().' carries a full 42-character address, which overflows the tile',
                );
            }
        }
    }
});

/**
 * A plain integer applies the column count at every breakpoint, which put
 * four tiles side by side on a 390px phone. Each band must be able to fall
 * back to a single column.
 */
it('lets every finance stat band collapse to one column', function (): void {
    foreach ($this->finance as $widget) {
        $columns = (new ReflectionClass($widget))->getDefaultProperties()['columns'] ?? null;

        expect($columns)->toBeArray(
            $widget.' uses a plain integer column count, which applies at every breakpoint',
        );
        expect($columns['default'])->toBe(1, $widget.' must fall back to a single column');
    }
});

/**
 * Filament's `@`-prefixed grid breakpoints are CONTAINER queries measured
 * against the widget itself, not the viewport: `@2xl` means 42rem of *widget*
 * width. Reading them as viewport breakpoints is what put five 176px tiles in
 * a full-width band, with captions running to five lines.
 *
 * This asserts the real invariant at every declared tier — that the tile stays
 * wide enough to hold its longest word — rather than only checking the array
 * shape.
 */
it('never squeezes a finance stat tile below a readable width', function (): void {
    /** Widget width, in px, at which each Filament container breakpoint fires. */
    $containerQueries = [
        '@2xs' => 288, '@xs' => 320, '@sm' => 384, '@md' => 448, '@lg' => 512,
        '@xl' => 576, '@2xl' => 672, '@3xl' => 768, '@4xl' => 896,
        '@5xl' => 1024, '@6xl' => 1152, '@7xl' => 1280,
    ];

    $minTileWidth = 200;
    $gutter = 14; // matches the .fi-section-content.fi-grid gap in stats-overview.css

    foreach ($this->finance as $widget) {
        $columns = (new ReflectionClass($widget))->getDefaultProperties()['columns'];

        expect($columns['default'])->toBe(1, $widget.' must fall back to a single column');

        foreach ($columns as $breakpoint => $count) {
            if ($breakpoint === 'default') {
                continue;
            }

            expect(array_key_exists($breakpoint, $containerQueries))->toBeTrue(
                $widget.' uses '.$breakpoint.', which is not a Filament container breakpoint',
            );

            $tile = ($containerQueries[$breakpoint] - ($count - 1) * $gutter) / $count;

            expect($tile)->toBeGreaterThanOrEqual(
                $minTileWidth,
                sprintf(
                    '%s asks for %d columns at %s (%dpx widget) = %dpx tiles, below the %dpx floor',
                    class_basename($widget),
                    $count,
                    $breakpoint,
                    $containerQueries[$breakpoint],
                    (int) round($tile),
                    $minTileWidth,
                ),
            );
        }
    }
});

/**
 * A band description orients the reader; it is not body copy. Eight bands
 * carried 138 to 290 characters each, which is two lines of prose above every
 * chart and stat band. The operative caveat stays, the elaboration goes.
 */
it('keeps every finance band description to a single orienting line', function (): void {
    $bands = [
        ArcSettlementWidget::class,
        AutonomyLaneWidget::class,
        OperationsHealthWidget::class,
    ];

    foreach ($bands as $widget) {
        $method = new ReflectionMethod($widget, 'getDescription');

        $description = (string) $method->invoke(new $widget);

        expect(mb_strlen($description))->toBeLessThanOrEqual(
            150,
            class_basename($widget).' band description is '.mb_strlen($description).' characters',
        );
    }
});

/**
 * A U+FFFD renders as a diamond on the page and is invisible in a diff,
 * because it looks like an em dash in most editors. It has already happened
 * once in this codebase.
 */
it('renders no unicode replacement characters on the finance overview', function (): void {
    foreach ($this->finance as $widget) {
        expect(Livewire::test($widget)->html())->not->toContain("\u{FFFD}");
    }
});

/**
 * The approval inbox is the one band with its own blade, so none of the widget
 * rules reach it. Its three lanes were amber, sky and emerald � three hues for
 * three kinds of work, which read as three severities. Emerald is the colour
 * this panel uses for proven settlement, so a queue of payments awaiting
 * authorization was tinted like good news, and the "Authorize" row action wore
 * it too.
 *
 * Colour there now means "is work waiting": amber when it is, grey when it is
 * not. Lane identity comes from the title and icon.
 */
it('uses colour for state, not category, in the approval inbox', function (): void {
    $blade = file_get_contents(resource_path('views/filament/finance/widgets/approval-inbox.blade.php'));

    // No per-lane hue left behind.
    expect($blade)->not->toContain('badge_color');

    // Exactly one amber and one grey tone, reused from a single helper.
    expect(substr_count($blade, 'bg-amber-50 text-amber-600'))->toBe(1);
    expect(substr_count($blade, 'bg-gray-100 text-gray-500'))->toBe(1);

    // No row action may be tinted: a tinted "Authorize" looks like a settled
    // payment before anyone has approved it.
    expect($blade)->not->toMatch('/href="\{\{ \$item\[.url.\] \}\}" class="[^"]*(emerald|sky|red)-/');
});
