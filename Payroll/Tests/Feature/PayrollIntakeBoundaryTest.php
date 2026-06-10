<?php

/**
 * Architectural guard for the producer → Payroll intake boundary defined in
 * docs/plans/people/10_payroll-intake-dependency-inversion.md.
 *
 * Producer modules (Leave, Claim, Attendance) must never import Payroll's
 * internal models. Their only allowed dependencies on Payroll are the typed
 * intake contract: PayrollContributionIntake, PayrollContributionStatus,
 * PayrollContributionPayload, PayrollContributionOutcome, PayrollContributionState.
 *
 * If this test fails, do NOT add the offending import — that would re-introduce
 * the producer-writes-Payroll-tables direction the inversion eliminated. Move
 * the write through `PayrollContributionIntake::ingest()` instead.
 */

use Symfony\Component\Finder\Finder;

const PAYROLL_INTAKE_FORBIDDEN_IMPORTS = [
    'App\\Modules\\People\\Payroll\\Models\\PayrollInput',
    'App\\Modules\\People\\Payroll\\Models\\PayrollRun',
    'App\\Modules\\People\\Payroll\\Models\\PayrollRunParticipant',
    'App\\Modules\\People\\Payroll\\Models\\PayrollPendingContribution',
    'App\\Modules\\People\\Payroll\\Models\\PayrollPayItem',
    'App\\Modules\\People\\Payroll\\Models\\PayrollPayItemClassification',
    'App\\Modules\\People\\Payroll\\Models\\PayrollPeriod',
    'App\\Modules\\People\\Payroll\\Models\\PayrollResultLine',
    'App\\Modules\\People\\Payroll\\Models\\PayrollRunAuditEvent',
    'App\\Modules\\People\\Payroll\\Models\\PayrollCalendar',
    'App\\Modules\\People\\Payroll\\Models\\PayrollEmployeeStatutoryProfile',
    'App\\Modules\\People\\Payroll\\Models\\PayrollEmployerStatutoryProfile',
    'App\\Modules\\People\\Payroll\\Models\\PayrollStatutoryRuleSet',
    'App\\Modules\\People\\Payroll\\Models\\PayrollStatutoryRuleRow',
    'App\\Modules\\People\\Payroll\\Models\\PayrollPdfArtifact',
    // After plans 12–14, the intake contract is also off-limits to
    // producers. All Payroll communication goes through events now.
    'App\\Modules\\People\\Payroll\\Services\\PayrollContributionIntake',
    'App\\Modules\\People\\Payroll\\Contracts\\Intake\\PayrollContributionPayload',
    'App\\Modules\\People\\Payroll\\Contracts\\Intake\\PayrollContributionOutcome',
    'App\\Modules\\People\\Payroll\\Contracts\\Intake\\PayrollContributionState',
];

const PAYROLL_INTAKE_PRODUCER_MODULES = [
    'D:/repo/belimbing/app/Modules/People/Leave',
    'D:/repo/belimbing/app/Modules/People/Claim',
    'D:/repo/belimbing/app/Modules/People/Attendance',
];

function intakeBoundaryScanProducerImports(): array
{
    $violations = [];
    foreach (PAYROLL_INTAKE_PRODUCER_MODULES as $module) {
        if (! is_dir($module)) {
            continue;
        }
        $finder = (new Finder())->files()->in($module)->name('*.php');
        foreach ($finder as $file) {
            $contents = file_get_contents($file->getRealPath());
            if ($contents === false) {
                continue;
            }
            foreach (PAYROLL_INTAKE_FORBIDDEN_IMPORTS as $forbidden) {
                $pattern = '/^\s*use\s+'.preg_quote($forbidden, '/').'\s*;/m';
                if (preg_match($pattern, $contents) === 1) {
                    $violations[] = [
                        'file' => str_replace('\\', '/', $file->getRealPath()),
                        'import' => $forbidden,
                    ];
                }
            }
        }
    }

    return $violations;
}

test('producer modules do not import Payroll internal models', function (): void {
    $violations = intakeBoundaryScanProducerImports();

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Producer-to-Payroll model imports found (these must go through PayrollContributionIntake instead):'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s imports %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});
