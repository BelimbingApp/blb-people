<?php

test('every denial parity evidence path exists in the People domain', function (): void {
    $domainRoot = dirname(__DIR__, 2);
    $matrix = file_get_contents($domainRoot.'/docs/contracts/denial-parity.md');

    expect($matrix)->not->toBeFalse();

    $lines = collect(preg_split('/\R/', $matrix))
        ->filter(fn (string $line): bool => str_starts_with($line, '|'))
        ->map(fn (string $line): array => array_map('trim', explode('|', trim($line, '|'))));

    // The header is the row shape every consumer parses (the connector's
    // matrix-driven suite included), so it is asserted by name, not by count.
    expect($lines->first())->toBe([
        'Module', 'Business operation', 'Wrong tenant', 'Wrong company', 'Missing capability', 'Unauthorized actor', 'Test file(s)', 'Projection path',
    ]);

    $rows = $lines
        ->filter(fn (array $cells): bool => count($cells) === 8)
        ->reject(fn (array $cells): bool => $cells[0] === 'Module' || $cells[0] === '---')
        ->values();

    expect($rows)->not->toBeEmpty();

    $projectionPaths = 0;

    $rows->each(function (array $cells) use ($domainRoot, &$projectionPaths): void {
        [$module, $operation, $wrongTenant, $wrongCompany, $missingCapability, $unauthorizedActor, $testFiles, $projectionPath] = $cells;

        // A projection path is either absent or a connector-relative pair of
        // implementation and parity test. The connector is optional and not
        // composed here, so only the shape is checked; the connector's own
        // suite executes it.
        if ($projectionPath !== 'missing') {
            preg_match_all('/`connector:([^`]+\.php)`/', $projectionPath, $projectionMatches);

            expect($projectionMatches[1])->toHaveCount(2, "{$module} / {$operation} must name a connector implementation and its parity test")
                ->and($projectionMatches[1][0])->toStartWith('Connector/Services/')
                ->and($projectionMatches[1][1])->toStartWith('Connector/Tests/');

            $projectionPaths++;
        }

        expect($module)->toBeIn(['Skills', 'Training', 'Provider', 'Progression'])
            ->and($operation)->not->toBeEmpty();

        foreach ([$wrongTenant, $wrongCompany, $missingCapability, $unauthorizedActor] as $status) {
            expect($status)->toBeIn(['covered', 'missing']);
        }

        preg_match_all('/`([^`]+\.php)`/', $testFiles, $matches);

        if ($testFiles === 'missing') {
            expect($matches[1])->toBeEmpty();

            return;
        }

        expect($matches[1])->not->toBeEmpty();

        foreach ($matches[1] as $testFile) {
            expect(is_file($domainRoot.'/'.$testFile))
                ->toBeTrue("{$module} / {$operation} names missing test file {$testFile}");
        }
    });

    // The count is pinned so a new second-path implementation cannot land
    // without the matrix (and the connector's parity suite) recording it.
    expect($projectionPaths)->toBe(1);
    fwrite(STDERR, sprintf("denial parity: %d of %d rows have a projection path\n", $projectionPaths, $rows->count()));
});
