<?php

/**
 * Architectural guard for the Attendance → Payroll plug-out boundary
 * defined in docs/plans/people/12_attendance-event-decoupling.md.
 *
 * After plan 12 Phase 1, no production file under
 * app/Domains/People/Attendance/ may import anything under
 * App\Domains\People\Payroll\. Attendance communicates with the Payroll
 * module only via events it dispatches. Cross-module integration tests may
 * exercise both sides of the boundary.
 *
 * This is stricter than the plan-10 intake-boundary test (which forbade
 * Payroll model imports while allowing the intake-contract imports).
 * Plan 12 closes the remaining gap so the Payroll module can be removed
 * from disk without breaking Attendance autoload.
 *
 * Leave, Claim, and Employees have sibling guards for their own boundaries.
 */

use App\Base\Foundation\ApplicationTopology;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

const ATTENDANCE_BOUNDARY_FORBIDDEN_NAMESPACE = 'App\\Domains\\People\\Payroll\\';

function attendanceBoundaryModulePath(): string
{
    return ApplicationTopology::domainPath('People').DIRECTORY_SEPARATOR.'Attendance';
}

function attendanceBoundaryScanImports(string $modulePath): array
{
    $violations = [];

    $finder = (new Finder)->files()->in($modulePath)->exclude('Tests')->name('*.php');
    foreach ($finder as $file) {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            continue;
        }
        $pattern = '/^\s*use\s+'.preg_quote(ATTENDANCE_BOUNDARY_FORBIDDEN_NAMESPACE, '/').'[A-Za-z0-9_\\\\]+\s*;/m';
        if (preg_match_all($pattern, $contents, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $violations[] = [
                    'file' => str_replace('\\', '/', $file->getRealPath()),
                    'import' => trim($match),
                ];
            }
        }
    }

    return $violations;
}

test('Attendance production code does not import anything under People\Payroll', function (): void {
    $modulePath = attendanceBoundaryModulePath();

    expect($modulePath)->toBeDirectory();

    $violations = attendanceBoundaryScanImports($modulePath);

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Attendance production code must not import Payroll classes (use events instead). Offenders:'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s: %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});

test('Attendance boundary scanner detects production imports and ignores integration tests', function (): void {
    $filesystem = new Filesystem;
    $fixtureRoot = storage_path('framework/testing/attendance-boundary-'.bin2hex(random_bytes(8)));
    $productionPath = $fixtureRoot.DIRECTORY_SEPARATOR.'Services';
    $testsPath = $fixtureRoot.DIRECTORY_SEPARATOR.'Tests'.DIRECTORY_SEPARATOR.'Feature';
    $forbiddenImport = '<?php'.PHP_EOL.'use App\\Domains\\People\\Payroll\\Models\\PayrollRun;'.PHP_EOL;

    try {
        $filesystem->ensureDirectoryExists($productionPath);
        $filesystem->ensureDirectoryExists($testsPath);
        $filesystem->put($productionPath.DIRECTORY_SEPARATOR.'Violation.php', $forbiddenImport);
        $filesystem->put($testsPath.DIRECTORY_SEPARATOR.'AllowedIntegrationTest.php', $forbiddenImport);

        $violations = attendanceBoundaryScanImports($fixtureRoot);

        expect($violations)->toHaveCount(1)
            ->and($violations[0]['file'])->toEndWith('/Services/Violation.php')
            ->and($violations[0]['import'])->toBe('use App\\Domains\\People\\Payroll\\Models\\PayrollRun;');
    } finally {
        $filesystem->deleteDirectory($fixtureRoot);
    }
});
