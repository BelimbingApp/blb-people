<?php

/**
 * Architectural guard for the Claim → Payroll plug-out boundary
 * defined in docs/plans/people/14_claim-event-decoupling.md.
 *
 * After Plan 14 Phase 1, no file under app/Modules/People/Claim/ may
 * import anything under App\Modules\People\Payroll\. Claim communicates
 * with the Payroll plugin only via events it dispatches.
 */

use Symfony\Component\Finder\Finder;

const CLAIM_BOUNDARY_FORBIDDEN_NAMESPACE = 'App\\Modules\\People\\Payroll\\';

const CLAIM_BOUNDARY_MODULE_PATH = 'D:/repo/belimbing/app/Modules/People/Claim';

function claimBoundaryScanImports(): array
{
    $violations = [];
    if (! is_dir(CLAIM_BOUNDARY_MODULE_PATH)) {
        return $violations;
    }

    $finder = (new Finder)->files()->in(CLAIM_BOUNDARY_MODULE_PATH)->name('*.php');
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

test('Claim module does not import anything under People\Payroll', function (): void {
    $violations = claimBoundaryScanImports();

    expect($violations)->toBe(
        [],
        $violations === []
            ? ''
            : 'Claim must not import Payroll classes (use events instead). Offenders:'.PHP_EOL
                .implode(PHP_EOL, array_map(
                    fn (array $v): string => sprintf('  - %s: %s', $v['file'], $v['import']),
                    $violations,
                )),
    );
});
