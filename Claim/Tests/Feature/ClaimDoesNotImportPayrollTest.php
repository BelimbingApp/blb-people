<?php

/**
 * Architectural guard for the Claim → Payroll plug-out boundary
 * defined in docs/plans/people/14_claim-event-decoupling.md.
 *
 * After Plan 14 Phase 1, no production file under
 * app/Domains/People/Claim/ may import anything under
 * App\Domains\People\Payroll\. Claim communicates with the Payroll module
 * only via events it dispatches. Cross-module integration tests may exercise
 * both sides of the boundary.
 */

use App\Base\Foundation\ApplicationTopology;
use Symfony\Component\Finder\Finder;

const CLAIM_BOUNDARY_FORBIDDEN_NAMESPACE = 'App\\Domains\\People\\Payroll\\';

function claimBoundaryModulePath(): string
{
    return ApplicationTopology::domainPath('People').DIRECTORY_SEPARATOR.'Claim';
}

function claimBoundaryScanImports(string $modulePath): array
{
    $violations = [];

    $finder = (new Finder)->files()->in($modulePath)->exclude('Tests')->name('*.php');
    foreach ($finder as $file) {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            continue;
        }
        $pattern = '/^\s*use\s+'.preg_quote(CLAIM_BOUNDARY_FORBIDDEN_NAMESPACE, '/').'[A-Za-z0-9_\\\\]+\s*;/m';
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

test('Claim production code does not import anything under People\Payroll', function (): void {
    $modulePath = claimBoundaryModulePath();

    expect($modulePath)->toBeDirectory();

    $violations = claimBoundaryScanImports($modulePath);

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Claim production code must not import Payroll classes (use events instead). Offenders:'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s: %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});
