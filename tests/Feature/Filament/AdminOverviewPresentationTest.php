<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\InstallationReadinessWidget;
use App\Filament\Widgets\LeptonNetworkWidget;
use App\Filament\Widgets\TreasuryOverviewWidget;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    $this->actingAs($this->admin);
});

/**
 * Admin overview presentation.
 *
 * These guard the reading rules the overview depends on rather than its copy:
 * colour must encode state and never decoration, and a stat tile must not
 * carry a floating icon in the middle of its description. Filament renders
 * `->color()` onto the description paragraph, so a decorative `primary` or
 * `info` is what produced the orange-and-blue noise on the page.
 */
it('never uses primary or info to colour a stat description', function (): void {
    foreach ([InstallationReadinessWidget::class, LeptonNetworkWidget::class, TreasuryOverviewWidget::class] as $widget) {
        $html = Livewire::test($widget)->html();

        expect($html)
            ->not->toContain('fi-color-primary')
            ->not->toContain('fi-color-info');
    }
});

it('renders no description icon inside a stat tile', function (): void {
    // The description icon renders an <svg> inside the description block, so
    // asserting none survives is asserting the tiles carry no floating glyph.
    foreach ([InstallationReadinessWidget::class, LeptonNetworkWidget::class, TreasuryOverviewWidget::class] as $widget) {
        $html = Livewire::test($widget)->html();

        expect($html)
            ->toContain('fi-wi-stats-overview-stat-description')
            ->not->toMatch('#fi-wi-stats-overview-stat-description[^<]*<span>\s*<svg#');
    }
});

it('keeps a single accent on the dashboard header actions', function (): void {
    $html = Livewire::test(Dashboard::class)->html();

    // "Add user" is the one primary action; the rest must read as neutral.
    expect(substr_count($html, 'fi-color-primary'))->toBeLessThanOrEqual(2);
    expect(substr_count($html, 'fi-color-info'))->toBe(0);
    expect(substr_count($html, 'fi-color-warning'))->toBe(0);
});

it('labels the assistant without expanding the AI acronym twice', function (): void {
    $html = Livewire::test(Dashboard::class)->html();

    expect($html)->toContain('Advisory AI')->not->toContain('AI intelligence');
});

it('drops the welcome and sign-out card from the overview', function (): void {
    $html = Livewire::test(Dashboard::class)->html();

    expect($html)->not->toContain('Sign out');
});

/**
 * A U+FFFD in the source renders as a diamond on the page and is invisible in
 * a diff, because it looks like an em dash in most editors. It has already
 * happened once, in the readiness band description.
 */
it('renders no unicode replacement characters', function (): void {
    foreach ([InstallationReadinessWidget::class, LeptonNetworkWidget::class, TreasuryOverviewWidget::class] as $widget) {
        $html = Livewire::test($widget)->html();

        expect($html)->not->toContain("\u{FFFD}");
    }
});

/**
 * Six stats across three columns is the only arrangement that is both even
 * and readable. Six across left ~180px per tile, narrower than the word
 * "Impersonation", so values broke mid-word and every tile in the row
 * stretched to the tallest while the rest sat on dead space.
 */
it('divides the six readiness stats into two even rows of three', function (): void {
    $columns = (new ReflectionClass(InstallationReadinessWidget::class))
        ->getDefaultProperties()['columns'] ?? null;

    expect($columns)->toBeArray();
    expect($columns['@lg'])->toBe(3);
    expect($columns['default'])->toBe(1);

    // Six must divide evenly by the desktop column count, or the last row
    // is left ragged.
    expect(6 % $columns['@lg'])->toBe(0);
});

/**
 * Guards the rule the tile colours are supposed to express: green means
 * healthy, amber means attention, red means broken, grey means a plain fact.
 */
it('uses success, warning, danger and gray only', function (): void {
    $html = Livewire::test(InstallationReadinessWidget::class)->html();

    // Filament writes the colour onto the description's own class attribute,
    // e.g. class="fi-wi-stats-overview-stat-description fi-color-success".
    preg_match_all(
        '/class="([^"]*fi-wi-stats-overview-stat-description[^"]*)"/',
        $html,
        $matches,
    );

    // preg_match_all with an explicit $matches argument always populates both
    // offsets, so the ?? was dead weight.
    $colours = array_unique($matches[1]);

    // Guard against the extraction silently matching nothing.
    expect($colours)->not->toBeEmpty();

    foreach ($colours as $class) {
        preg_match_all('/fi-color-([a-z]+)/', $class, $tokens);

        foreach ($tokens[1] as $token) {
            expect($token)->toBeIn(['success', 'warning', 'danger', 'gray']);
        }
    }
});

/**
 * A treasury address is 42 unbroken characters, so it has no wrap opportunity
 * and pushes straight out of a tile, across its neighbour. The value slot
 * truncates it; nothing else on the overview may repeat it in full.
 */
it('never puts an untruncated address in a stat value or description', function (): void {
    foreach ([InstallationReadinessWidget::class, LeptonNetworkWidget::class, TreasuryOverviewWidget::class] as $widget) {
        $instance = new $widget;
        $method = new ReflectionMethod($widget, 'getStats');

        // Reflection::invoke() returns mixed and PHPStan cannot infer through
        // it, so the element type is declared once here rather than on the
        // loop variable - where a @var would attach to $field instead.
        /** @var list<Stat> $stats */
        $stats = $method->invoke($instance);

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
 * A stat description is a caption. Filament will happily wrap two paragraphs
 * of prose into it, which is what made every tile in a row a different
 * height. One line of copy per stat is the structural guard.
 */
it('keeps every stat description to a single short line', function (): void {
    foreach ([InstallationReadinessWidget::class, LeptonNetworkWidget::class, TreasuryOverviewWidget::class] as $widget) {
        $instance = new $widget;
        $method = new ReflectionMethod($widget, 'getStats');

        foreach ($method->invoke($instance) as $stat) {
            /** @var Stat $stat */
            $description = (string) $stat->getDescription();

            expect(mb_strlen($description))
                ->toBeLessThanOrEqual(90, $stat->getLabel().' description is too long to stay a caption');

            expect($stat->getDescriptionIcon())->toBeNull();
        }
    }
});
