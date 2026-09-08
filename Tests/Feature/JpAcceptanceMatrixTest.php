<?php

/*
 * JP-G08 (#200): every JD/performance acceptance scenario JP-A01–JP-A13
 * names the test files that prove it, and the executable suite resolves
 * each named path it claims to run through.
 *
 * A row naming a file that does not exist, or a path that does not
 * resolve, fails here — not in a reviewer's head. Failing first: this test
 * was added before docs/contracts/jd-performance-acceptance.md held any
 * JP-A row.
 */
test('every JP acceptance scenario names real proof and resolvable paths', function (): void {
    $domainRoot = dirname(__DIR__, 2);
    $matrix = @file_get_contents($domainRoot.'/docs/contracts/jd-performance-acceptance.md');

    expect($matrix)->not->toBeFalse('the JP acceptance matrix is missing');

    $lines = collect(preg_split('/\R/', $matrix))
        ->filter(fn (string $line): bool => str_starts_with($line, '|'))
        ->map(fn (string $line): array => array_map('trim', explode('|', trim($line, '|'))));

    expect($lines->first())->toBe([
        'Scenario', 'Observable result', 'Test file(s)', 'Direct path', 'Explorer path', 'Export path',
    ]);

    $rows = $lines
        ->filter(fn (array $cells): bool => count($cells) === 6)
        ->reject(fn (array $cells): bool => $cells[0] === 'Scenario' || $cells[0] === '---')
        ->values();

    $ids = $rows->map(fn (array $cells): string => $cells[0])->all();
    foreach (range(1, 13) as $n) {
        expect($ids)->toContain(sprintf('JP-A%02d', $n));
    }

    $rows->each(function (array $cells) use ($domainRoot): void {
        [$scenario, $observable, $testFiles, $direct, $explorer, $export] = $cells;

        expect($observable)->not->toBeEmpty("{$scenario} states no observable result");

        preg_match_all('/`([^`]+\.php)`/', $testFiles, $matches);
        expect($matches[1])->not->toBeEmpty("{$scenario} names no test file");

        foreach ($matches[1] as $testFile) {
            expect(is_file($domainRoot.'/'.$testFile))
                ->toBeTrue("{$scenario} names missing test file {$testFile}");
        }

        foreach (['direct' => $direct, 'explorer' => $explorer, 'export' => $export] as $kind => $path) {
            if ($path === 'missing') {
                continue;
            }

            preg_match_all('/`([^`]+)`/', $path, $pathMatches);
            expect($pathMatches[1])->not->toBeEmpty("{$scenario} {$kind} path names nothing");

            foreach ($pathMatches[1] as $target) {
                if (str_starts_with($target, 'class:')) {
                    expect(class_exists(substr($target, strlen('class:'))))
                        ->toBeTrue("{$scenario} {$kind} path names missing class {$target}");
                } elseif (str_starts_with($target, 'route:')) {
                    expect(app('router')->has(substr($target, strlen('route:'))))
                        ->toBeTrue("{$scenario} {$kind} path names missing route {$target}");
                } else {
                    $this->fail("{$scenario} {$kind} path uses an unknown target kind: {$target}");
                }
            }
        }
    });
});

/*
 * Follow-up (#342): `missing` is the one path value the matrix above accepts
 * without proving anything, so nothing stopped every resolvable `class:`/
 * `route:` cell from being rewritten to that word with the suite green — the
 * exact drift the matrix exists to catch. The document now declares how many
 * `missing` path cells it holds; this test counts them and requires the two
 * to agree, so removing a proven path is a two-place edit, not one word.
 *
 * Failing first: at efec41f the document declares no count and this test is
 * red with "the matrix does not declare its missing path-cell count".
 */
test('the JP acceptance matrix declares how many of its path cells are missing', function (): void {
    $domainRoot = dirname(__DIR__, 2);
    $matrix = @file_get_contents($domainRoot.'/docs/contracts/jd-performance-acceptance.md');

    expect($matrix)->not->toBeFalse('the JP acceptance matrix is missing');

    preg_match('/^Declared `missing` path cells:\s*(\d+)\s*$/m', (string) $matrix, $declaration);

    expect($declaration[1] ?? null)
        ->not->toBeNull('the matrix does not declare its missing path-cell count');

    $rows = collect(preg_split('/\R/', (string) $matrix))
        ->filter(fn (string $line): bool => str_starts_with($line, '|'))
        ->map(fn (string $line): array => array_map('trim', explode('|', trim($line, '|'))))
        ->filter(fn (array $cells): bool => count($cells) === 6)
        ->reject(fn (array $cells): bool => $cells[0] === 'Scenario' || $cells[0] === '---')
        ->values();

    // Guard the counter itself: a parse that silently matched nothing would
    // otherwise agree with any declaration of zero.
    expect($rows)->toHaveCount(13);

    $pathCells = $rows->flatMap(fn (array $cells): array => array_slice($cells, 3, 3));

    expect($pathCells)->toHaveCount(39);

    expect($pathCells->filter(fn (string $cell): bool => $cell === 'missing')->count())
        ->toBe((int) $declaration[1]);
});
