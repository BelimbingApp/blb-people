<?php

/**
 * Architectural guard for the Employees → Payroll plug-out boundary
 * defined in docs/plans/people/15_employees-event-decoupling.md.
 *
 * Employees production code reads payroll-side data (statutory profile,
 * readiness summary) but must not compile-time depend on Payroll classes.
 * The readiness service accesses the table via the DB facade with a
 * Schema::hasTable guard so it degrades gracefully when Payroll is
 * uninstalled. Cross-module integration tests may exercise both sides of
 * the boundary.
 */

use App\Base\Foundation\ApplicationTopology;
use Symfony\Component\Finder\Finder;

const EMPLOYEES_BOUNDARY_FORBIDDEN_NAMESPACE = 'App\\Domains\\People\\Payroll\\';

function employeesBoundaryModulePath(): string
{
    return ApplicationTopology::domainPath('People').DIRECTORY_SEPARATOR.'Employees';
}

function employeesBoundaryScanImports(string $modulePath): array
{
    $violations = [];

    $finder = (new Finder)->files()->in($modulePath)->exclude('Tests')->name('*.php');
    foreach ($finder as $file) {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            continue;
        }
        $pattern = '/^\s*use\s+'.preg_quote(EMPLOYEES_BOUNDARY_FORBIDDEN_NAMESPACE, '/').'[A-Za-z0-9_\\\\]+\s*;/m';
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

test('Employees production code does not import anything under People\Payroll', function (): void {
    $modulePath = employeesBoundaryModulePath();

    expect($modulePath)->toBeDirectory();

    $violations = employeesBoundaryScanImports($modulePath);

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Employees production code must not import Payroll classes (use DB facade with Schema::hasTable guard instead). Offenders:'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s: %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});
