<?php

use App\Domains\People\Tests\Support\TableCaptionScan;

/*
 * Unit cover for the caption guard's tag reading (see
 * Tests/Feature/ViewTableCaptionsTest.php). Inline sources only: the guard
 * globs real view files, so nothing here may live in a *.blade.php file.
 */

test('an attribute before the caption does not hide it', function (): void {
    // Reported today: the scan stops at the > inside $rows->isEmpty().
    expect(TableCaptionScan::missing(
        '<x-ui.table :empty="$rows->isEmpty()" :caption="__(\'Courses\')">',
        'fixture.blade.php',
    ))->toBe([]);
});

test('a blank caption counts as missing', function (): void {
    // All three pass today; each renders no <caption> element.
    expect(TableCaptionScan::missing('<x-ui.table :caption="\'\'">', 'a.blade.php'))->not->toBe([])
        ->and(TableCaptionScan::missing("<x-ui.table :caption=''>", 'b.blade.php'))->not->toBe([])
        ->and(TableCaptionScan::missing('<x-ui.table caption="">', 'c.blade.php'))->not->toBe([]);
});

test('a real caption still passes', function (): void {
    expect(TableCaptionScan::missing('<x-ui.table :caption="__(\'Courses\')">', 'a.blade.php'))->toBe([])
        ->and(TableCaptionScan::missing('<x-ui.table caption="Courses">', 'b.blade.php'))->toBe([])
        ->and(TableCaptionScan::missing('<x-ui.table no-caption>', 'c.blade.php'))->toBe([]);
});

test('a missing caption is reported with file and line', function (): void {
    expect(TableCaptionScan::missing("<div>\n<x-ui.table>\n</x-ui.table>", 'shop.blade.php'))
        ->toBe(['shop.blade.php:2']);
});

test('x-ui.table variants keep their skip', function (): void {
    // The guard counts only the table component itself; a variant without a
    // caption must not report, and removing the skip turns this red.
    expect(TableCaptionScan::missing('<x-ui.table-grid :items="$rows">', 'a.blade.php'))->toBe([]);
});

test('the no-caption opt-out keeps working', function (): void {
    // Removing the opt-out branch turns this red.
    expect(TableCaptionScan::missing('<x-ui.table no-caption>...</x-ui.table>', 'a.blade.php'))->toBe([]);
});
