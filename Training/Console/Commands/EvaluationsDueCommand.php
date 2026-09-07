<?php

namespace App\Domains\People\Training\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Training\Data\DueEvaluation;
use App\Domains\People\Training\Services\TrainingEvaluationReminders;

/**
 * Remind participants whose training evaluation is due within three days or
 * overdue, once per participant per day.
 *
 * Per company, because a reminder is somebody's inbox and inboxes belong to a
 * company. The tenant comes from --tenant, resolved and bound by
 * TenantScopedCommand before handle() runs (belimbing#710).
 */
final class EvaluationsDueCommand extends TenantScopedCommand
{
    protected $signature = 'people:training:evaluations-due
                            {--company= : Company workforce entity to run for}
                            {--dry-run : List what is due and record nothing}';

    protected $description = 'Remind participants of training evaluations due within three days or overdue';

    public function handle(TenantContext $tenants, TrainingEvaluationReminders $reminders): int
    {
        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('A reminder run is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $tenantId = $tenants->requireTenantId();
        $companyId = (int) $company;

        $due = $reminders->due($tenantId, $companyId);

        foreach ($due as $row) {
            $this->line(sprintf(
                'participant %d  %s  due %s  %s',
                $row->participantId,
                $row->eventTitle,
                $row->dueOn->toDateString(),
                self::state($row),
            ));
        }

        if ((bool) $this->option('dry-run')) {
            $this->line('Due: '.count($due).'. Nothing was recorded.');

            return self::SUCCESS;
        }

        $this->line('Reminded: '.count($reminders->remind($tenantId, $companyId)));

        return self::SUCCESS;
    }

    private static function state(DueEvaluation $row): string
    {
        return match (true) {
            $row->daysOverdue > 0 => "overdue by {$row->daysOverdue} day(s)",
            $row->daysOverdue === 0 => 'due today',
            default => 'due in '.abs($row->daysOverdue).' day(s)',
        };
    }
}
