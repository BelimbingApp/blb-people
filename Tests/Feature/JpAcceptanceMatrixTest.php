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
