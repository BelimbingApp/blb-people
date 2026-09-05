<?php

test('every denial parity evidence path exists in the People domain', function (): void {
    $domainRoot = dirname(__DIR__, 2);
    $matrix = file_get_contents($domainRoot.'/docs/contracts/denial-parity.md');

    expect($matrix)->not->toBeFalse();

    $rows = collect(preg_split('/\R/', $matrix))
        ->filter(fn (string $line): bool => str_starts_with($line, '|'))
        ->map(fn (string $line): array => array_map('trim', explode('|', trim($line, '|'))))
        ->filter(fn (array $cells): bool => count($cells) === 7)
        ->reject(fn (array $cells): bool => $cells[0] === 'Module' || $cells[0] === '---')
        ->values();

    expect($rows)->not->toBeEmpty();

    $rows->each(function (array $cells) use ($domainRoot): void {
        [$module, $operation, $wrongTenant, $wrongCompany, $missingCapability, $unauthorizedActor, $testFiles] = $cells;

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
});
