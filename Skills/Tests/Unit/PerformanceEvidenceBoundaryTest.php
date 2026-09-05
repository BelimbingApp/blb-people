<?php

use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\PerformanceEvidenceReference;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\PerformanceCompetenceEffect;
use App\Domains\People\Skills\Exceptions\PerformanceCannotDecideCompetenceException;

test('JP-A08 performance evidence attaches without deciding competence', function (): void {
    $reference = new PerformanceEvidenceReference(
        reference: 'performance:kpi-result-42',
        effect: PerformanceCompetenceEffect::EvidenceOnly,
    );
    $draft = new AssessmentDraft(
        employeeEntityId: 7,
        skillId: 11,
        assessedLevel: 2,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Baseline,
        assessedAt: new DateTimeImmutable('2026-09-06T00:00:00Z'),
        evidence: $reference,
    );

    expect($draft->evidence)->toBe($reference)
        ->and($reference->reference)->toBe('performance:kpi-result-42')
        ->and($draft->assessedLevel)->toBe(2);
});

test('JP-A08 performance cannot set or revoke competence', function (string $effect): void {
    expect(fn () => new PerformanceEvidenceReference('performance:kpi-result-42', PerformanceCompetenceEffect::from($effect)))
        ->toThrow(PerformanceCannotDecideCompetenceException::class);
})->with([
    'set_proficiency_level',
    'satisfy_critical_gate',
    'revoke_competence',
]);

test('performance evidence accepts only an opaque governed reference', function (string $reference): void {
    expect(fn () => new PerformanceEvidenceReference($reference))
        ->toThrow(InvalidArgumentException::class);
})->with([
    '',
    'performance result 42',
    str_repeat('x', 161),
]);
