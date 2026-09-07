<?php

/*
 * Every <x-ui.table> in a People view must carry a caption so screen readers
 * announce what the table is about. The component renders it sr-only, so the
 * caption never changes the visible layout.
 *
 * SonarCloud flags this in the platform repository, but Domain views are
 * excluded from that project and the Domain repository has no analyser of its
 * own. This test is the guard that the composed domain-ci can run (#310).
 *
 * Tag reading lives in Tests\Support\TableCaptionScan so the parsing is
 * unit-testable (Tests/Feature/TableCaptionScanTest.php); this file only
 * walks the repository. Raw <table> elements are out of the contract: only
 * the <x-ui.table> component counts, because only it renders the sr-only
 * caption slot the guard can rely on (blb-people#343).
 *
 * A table that deliberately has no caption opts out with an explicit
 * `no-caption` attribute, so the choice is visible in the view itself.
 */

use App\Domains\People\Tests\Support\TableCaptionScan;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

it('gives every x-ui.table in a People view a caption or an explicit no-caption opt-out', function () {
    $root = realpath(__DIR__.'/../..');

    $views = Finder::create()
        ->files()
        ->in($root)
        ->path('#(^|/)Views/#')
        ->name('*.blade.php')
        ->exclude(['vendor', 'node_modules']);

    $missing = [];

    foreach ($views as $view) {
        array_push($missing, ...TableCaptionScan::missing($view->getContents(), $view->getRelativePathname()));
    }

    expect($missing)->toBe([], 'Tables without a caption (add :caption="__(...)" or an explicit no-caption attribute): '.implode(', ', $missing));
});

test('a no-caption table does not leak the opt-out into the wrapper', function (): void {
    // no-caption is not a rendered option, it is the author's explicit
    // captionless choice; the component must swallow it instead of letting
    // it fall through $attributes onto the wrapper div (blb-people#343).
    $html = Blade::render('<x-ui.table no-caption></x-ui.table>');

    expect($html)->not->toContain('no-caption');
});
