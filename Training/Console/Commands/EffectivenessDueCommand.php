<?php

namespace App\Domains\People\Training\Console\Commands;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Training\Data\OpenEffectivenessCheckpoint;
use App\Domains\People\Training\Services\TrainingEffectivenessCheckpoints;
use Illuminate\Console\Command;

/**
 * List the effectiveness checkpoints waiting on a HOD, and record that they
 * were asked.
 *
 * A dry run only lists. The default writes one reminder per participant and
 * checkpoint, so running it twice in a day does not read as two questions
 * nobody answered.
 */
final class EffectivenessDueCommand extends Command
{
    protected $signature = 'people:training:effectiveness-due
                            {--tenant= : Tenant to run for; defaults to the current tenant context}
                            {--company= : Company workforce entity to run for}
                            {--dry-run : List what is due and record nothing}';

    protected $description = 'List open 30/60/90-day training effectiveness checkpoints per HOD';

    public function handle(TenantContext $tenants, TrainingEffectivenessCheckpoints $checkpoints): int
    {
        $tenantOption = $this->option('tenant');

        if ($tenantOption !== null && $tenantOption !== '') {
            $tenants->set((int) $tenantOption);
        }

        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('A checkpoint run is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $tenantId = $tenants->requireTenantId();
        $companyId = (int) $company;

        $due = array_values(array_filter(
            $checkpoints->open($tenantId, $companyId),
            static fn (OpenEffectivenessCheckpoint $row): bool => ! $row->answered,
        ));

        foreach ($due as $row) {
            // A checkpoint whose department has no head with an account is
            // listed rather than hidden: somebody has to notice that nobody
            // can be asked.
            $this->line(sprintf(
                'participant %d  %s  %s',
                $row->participantId,
                $row->checkpoint->label(),
                $row->hodUserId === null ? 'no department head' : 'hod user '.$row->hodUserId,
            ));
        }

        if ((bool) $this->option('dry-run')) {
            $this->line('Open: '.count($due).'. Nothing was recorded.');

            return self::SUCCESS;
        }

        $this->line('Reminded: '.count($checkpoints->remind($tenantId, $companyId)));

        return self::SUCCESS;
    }
}
