<?php

use App\Domains\People\Tests\Support\GlobalHelperScan;

/*
 * Rule: a Pest helper is a global PHP function, shared by every test file in
 * the composed suite, so prefix it with the file's subject (fxOpFixture,
 * residueRetiredFixture) instead of a generic name (fixture, counts).
 *
 * Two files declaring the same helper are a redeclare fatal that stops the
 * composed suite at the first one, and domain-ci composes one domain at a
 * time, so it cannot see a collision across the People and connector trees
 * (#384, #393). This test reads every Tests/ tree with the connector when it
 * is mounted, and fails on the duplicate before the suite can.
 */

$peopleRoot = realpath(__DIR__.'/../..');
// Sibling mount in the composed host. Resolved at run time, not file load:
// the app is not booted while this file loads, and __DIR__ follows a
// symlinked mount to its real location, so a relative path would miss it.
$connectorRoot = fn (): string => base_path('app/Domains/PeopleConnector');

test('no two People test files declare the same global helper', function () use ($peopleRoot): void {
    $duplicates = GlobalHelperScan::tree(['People' => $peopleRoot]);

    expect($duplicates)->toBe([], 'Global helper declared in more than one test file: '.GlobalHelperScan::describe($duplicates));
});

test('no People helper shares its name with a mounted connector helper', function () use ($peopleRoot, $connectorRoot): void {
    $duplicates = GlobalHelperScan::tree(['People' => $peopleRoot, 'PeopleConnector' => $connectorRoot()]);

    expect($duplicates)->toBe([], 'Global helper declared in more than one test file: '.GlobalHelperScan::describe($duplicates));
})->skip(
    fn (): bool => ! is_dir($connectorRoot().'/Connector/Tests'),
    'app/Domains/PeopleConnector is not mounted in this host; the cross-tree half runs only in the composed suite',
);
