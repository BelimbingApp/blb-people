<?php

use App\Domains\People\Training\Data\EffectivenessPerformanceReference;
use App\Domains\People\Training\Enums\PerformanceOutcomeUse;
use App\Domains\People\Training\Exceptions\InvalidEffectivenessPerformanceReferenceException;

test('JP-A13 KPI evidence keeps measure period source baseline and permission context', function (): void {
    $reference = new EffectivenessPerformanceReference(
        reviewReference: 'effectiveness:review-17',
        outcomeReference: 'performance:kpi-result-42',
        measure: 'Resolved cases within service target',
        periodStart: new DateTimeImmutable('2026-04-01'),
        periodEnd: new DateTimeImmutable('2026-06-30'),
        source: 'operations:case-ledger-v3',
        baseline: '71.5 percent',
        permissionReference: 'grant:performance-evidence-9',
        use: PerformanceOutcomeUse::EvidenceOnly,
    );

    expect($reference->reviewReference)->toBe('effectiveness:review-17')
        ->and($reference->outcomeReference)->toBe('performance:kpi-result-42')
        ->and($reference->measure)->toBe('Resolved cases within service target')
        ->and($reference->periodStart->format('Y-m-d'))->toBe('2026-04-01')
        ->and($reference->periodEnd->format('Y-m-d'))->toBe('2026-06-30')
        ->and($reference->source)->toBe('operations:case-ledger-v3')
        ->and($reference->baseline)->toBe('71.5 percent')
        ->and($reference->permissionReference)->toBe('grant:performance-evidence-9');
});

test('JP-A13 KPI evidence cannot claim training causation or change competence', function (string $use): void {
    expect(fn () => new EffectivenessPerformanceReference(
        reviewReference: 'effectiveness:review-17',
        outcomeReference: 'performance:kpi-result-42',
        measure: 'Resolved cases within service target',
        periodStart: new DateTimeImmutable('2026-04-01'),
        periodEnd: new DateTimeImmutable('2026-06-30'),
        source: 'operations:case-ledger-v3',
        baseline: '71.5 percent',
        permissionReference: 'grant:performance-evidence-9',
        use: PerformanceOutcomeUse::from($use),
    ))->toThrow(InvalidEffectivenessPerformanceReferenceException::class);
})->with([
    'training_caused_improvement',
    'change_competence',
]);

test('effectiveness rejects incomplete or reversed KPI evidence context', function (array $overrides): void {
    $input = array_replace([
        'reviewReference' => 'effectiveness:review-17',
        'outcomeReference' => 'performance:kpi-result-42',
        'measure' => 'Resolved cases within service target',
        'periodStart' => new DateTimeImmutable('2026-04-01'),
        'periodEnd' => new DateTimeImmutable('2026-06-30'),
        'source' => 'operations:case-ledger-v3',
        'baseline' => '71.5 percent',
        'permissionReference' => 'grant:performance-evidence-9',
    ], $overrides);

    expect(fn () => new EffectivenessPerformanceReference(...$input))
        ->toThrow(InvalidEffectivenessPerformanceReferenceException::class);
})->with([
    [['reviewReference' => '']],
    [['outcomeReference' => 'performance result 42']],
    [['measure' => '']],
    [['periodStart' => new DateTimeImmutable('2026-07-01')]],
    [['source' => '']],
    [['baseline' => '']],
    [['permissionReference' => str_repeat('x', 161)]],
]);
