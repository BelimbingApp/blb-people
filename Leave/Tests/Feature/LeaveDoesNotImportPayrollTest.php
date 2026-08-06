<?php

/**
 * Architectural guard for the Leave → Payroll plug-out boundary
 * defined in docs/plans/people/13_leave-event-decoupling.md.
 *
 * After Plan 13 Phase 1, no production file under
 * app/Domains/People/Leave/ may import anything under
 * App\Domains\People\Payroll\. Leave communicates with the Payroll module
 * only via events it dispatches. Cross-module integration tests may exercise
 * both sides of the boundary.
 */

use App\Base\Foundation\ApplicationTopology;
use Symfony\Component\Finder\Finder;

const LEAVE_BOUNDARY_FORBIDDEN_NAMESPACE = 'App\\Domains\\People\\Payroll\\';

function leaveBoundaryModulePath(): string
{
    return ApplicationTopology::domainPath('People').DIRECTORY_SEPARATOR.'Leave';
}

function leaveBoundaryScanImports(string $modulePath): array
{
    $violations = [];

    $finder = (new Finder)->files()->in($modulePath)->exclude('Tests')->name('*.php');
    foreach ($finder as $file) {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            continue;
        }
        $pattern = '/^\s*use\s+'.preg_quote(LEAVE_BOUNDARY_FORBIDDEN_NAMESPACE, '/').'[A-Za-z0-9_\\\\]+\s*;/m';
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

test('Leave production code does not import anything under People\Payroll', function (): void {
    $modulePath = leaveBoundaryModulePath();

    expect($modulePath)->toBeDirectory();

    $violations = leaveBoundaryScanImports($modulePath);

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Leave production code must not import Payroll classes (use events instead). Offenders:'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s: %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});
