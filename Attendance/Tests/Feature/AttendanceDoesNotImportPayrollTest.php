<?php

/**
 * Architectural guard for the Attendance → Payroll plug-out boundary
 * defined in docs/plans/people/12_attendance-event-decoupling.md.
 *
 * After plan 12 Phase 1, no file under app/Modules/People/Attendance/
 * may import anything under App\Modules\People\Payroll\. Attendance
 * communicates with the Payroll plugin only via events it dispatches.
 *
 * This is stricter than the plan-10 intake-boundary test (which forbade
 * Payroll model imports while allowing the intake-contract imports).
 * Plan 12 closes the remaining gap so the Payroll plugin can be removed
 * from disk without breaking Attendance autoload.
 *
 * Leave and Claim are NOT covered here yet — they still go through the
 * intake contract directly until their own decoupling plans land.
 */

use Symfony\Component\Finder\Finder;

const ATTENDANCE_BOUNDARY_FORBIDDEN_NAMESPACE = 'App\\Modules\\People\\Payroll\\';

const ATTENDANCE_BOUNDARY_MODULE_PATH = 'D:/repo/belimbing/app/Modules/People/Attendance';

function attendanceBoundaryScanImports(): array
{
    $violations = [];
    if (! is_dir(ATTENDANCE_BOUNDARY_MODULE_PATH)) {
        return $violations;
    }

    $finder = (new Finder())->files()->in(ATTENDANCE_BOUNDARY_MODULE_PATH)->name('*.php');
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

test('Attendance module does not import anything under People\Payroll', function (): void {
    $violations = attendanceBoundaryScanImports();

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Attendance must not import Payroll classes (use events instead). Offenders:'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s: %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});
