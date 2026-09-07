<?php

namespace App\Domains\People\Performance\Console\Commands;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Performance\Data\CutoverCheck;
use App\Domains\People\Performance\Services\CutoverReadiness;
use Illuminate\Console\Command;

/**
 * Report whether a company can stop running performance reviews by hand.
 *
 * Exits non-zero when any check is red, so it can gate a rollout step rather
 * than only inform one. That is the difference between a readiness check and
 * a report somebody has to read carefully.
 */
final class CutoverCheckCommand extends Command
{
    protected $signature = 'people:performance:cutover-check
                            {--tenant= : Tenant to check; defaults to the current tenant context}
                            {--company= : Company workforce entity to check}
                            {--json : Print the report as JSON for scripts}';

    protected $description = 'Report per-company readiness to cut over to the performance module';

    public function handle(TenantContext $tenants, CutoverReadiness $readiness): int
    {
        $tenantOption = $this->option('tenant');

        if ($tenantOption !== null && $tenantOption !== '') {
            $tenants->set((int) $tenantOption);
        }

        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('A cutover check is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $checks = $readiness->check($tenants->requireTenantId(), (int) $company);
        $ready = $readiness->ready($checks);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'ready' => $ready,
                'checks' => array_reduce(
                    $checks,
                    static fn (array $carry, CutoverCheck $check): array => $carry + [
                        $check->check => ['count' => $check->count, 'ready' => $check->ready()],
                    ],
                    [],
                ),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $ready ? self::SUCCESS : self::FAILURE;
        }

        $this->table(
            ['Check', 'Count', 'Status'],
            array_map(
                static fn (CutoverCheck $check): array => [
                    $check->label,
                    $check->count,
                    $check->ready() ? 'green' : 'RED',
                ],
                $checks,
            ),
        );

        $this->line($ready
            ? 'Ready: every check is green.'
            : 'Not ready: fix the red checks, then run this again.');

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
