<?php

/**
 * Architectural guard for the producer → Payroll intake boundary defined in
 * docs/plans/people/10_payroll-intake-dependency-inversion.md.
 *
 * Production code in producer modules (Leave, Claim, Attendance) must never
 * import Payroll's internal models or its former intake contract. Cross-module
 * integration tests may exercise both sides of the boundary. All production
 * communication with Payroll now goes through producer events.
 *
 * If this test fails, do NOT add the offending import — that would re-introduce
 * the producer-writes-Payroll-tables direction the inversion eliminated. Move
 * the communication through a producer event instead.
 */

use App\Base\Foundation\ApplicationTopology;
use Symfony\Component\Finder\Finder;

const PAYROLL_INTAKE_FORBIDDEN_IMPORTS = [
    'App\\Domains\\People\\Payroll\\Models\\PayrollInput',
    'App\\Domains\\People\\Payroll\\Models\\PayrollRun',
    'App\\Domains\\People\\Payroll\\Models\\PayrollRunParticipant',
    'App\\Domains\\People\\Payroll\\Models\\PayrollPendingContribution',
    'App\\Domains\\People\\Payroll\\Models\\PayrollPayItem',
    'App\\Domains\\People\\Payroll\\Models\\PayrollPayItemClassification',
    'App\\Domains\\People\\Payroll\\Models\\PayrollPeriod',
    'App\\Domains\\People\\Payroll\\Models\\PayrollResultLine',
    'App\\Domains\\People\\Payroll\\Models\\PayrollRunAuditEvent',
    'App\\Domains\\People\\Payroll\\Models\\PayrollCalendar',
    'App\\Domains\\People\\Payroll\\Models\\PayrollEmployeeStatutoryProfile',
    'App\\Domains\\People\\Payroll\\Models\\PayrollEmployerStatutoryProfile',
    'App\\Domains\\People\\Payroll\\Models\\PayrollStatutoryRuleSet',
    'App\\Domains\\People\\Payroll\\Models\\PayrollStatutoryRuleRow',
    'App\\Domains\\People\\Payroll\\Models\\PayrollPdfArtifact',
    // After plans 12–14, the intake contract is also off-limits to
    // producers. All Payroll communication goes through events now.
    'App\\Domains\\People\\Payroll\\Services\\PayrollContributionIntake',
    'App\\Domains\\People\\Payroll\\Contracts\\Intake\\PayrollContributionPayload',
    'App\\Domains\\People\\Payroll\\Contracts\\Intake\\PayrollContributionOutcome',
    'App\\Domains\\People\\Payroll\\Contracts\\Intake\\PayrollContributionState',
];

function payrollIntakeProducerModulePaths(): array
{
    $peopleRoot = ApplicationTopology::domainPath('People');

    return [
        $peopleRoot.DIRECTORY_SEPARATOR.'Leave',
        $peopleRoot.DIRECTORY_SEPARATOR.'Claim',
        $peopleRoot.DIRECTORY_SEPARATOR.'Attendance',
    ];
}

function intakeBoundaryScanProducerImports(array $producerModules): array
{
    $violations = [];
    foreach ($producerModules as $module) {
        $finder = (new Finder)->files()->in($module)->exclude('Tests')->name('*.php');
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

test('producer production code does not import Payroll classes', function (): void {
    $producerModules = payrollIntakeProducerModulePaths();

    foreach ($producerModules as $modulePath) {
        expect($modulePath)->toBeDirectory();
    }

    $violations = intakeBoundaryScanProducerImports($producerModules);

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Payroll imports found in producer production code (these must go through producer events instead):'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s imports %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});
