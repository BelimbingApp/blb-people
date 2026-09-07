<?php

namespace App\Domains\People\Skills\Console\Commands;

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Domains\People\Skills\Data\DeliveryRunResult;
use App\Domains\People\Skills\Models\SkillReminderDelivery;
use App\Domains\People\Skills\Services\ReminderDeliveries;
use App\Domains\People\Skills\Services\ReminderRules;

/**
 * Send this week's skill reminders for one company and print what happened.
 *
 * `people:reminders-due` stays the read-only report. This is the sending half:
 * one deduplicated delivery row per reminder, recipient and ISO week, failures
 * printed one per line so an operator can see them, and --retry to re-attempt
 * exactly those. --dry-run prints the counts a send would produce and writes
 * nothing.
 */
final class RemindersSendCommand extends TenantScopedCommand
{
    protected $signature = 'people:reminders-send
                            {--company= : Company workforce entity to send for}
                            {--days= : Days ahead to treat a certificate as expiring}
                            {--retry : Re-attempt this period\'s failed deliveries instead of sending new ones}
                            {--dry-run : Print what would be sent and write nothing}';

    protected $description = 'Send due skill reminders once per ISO week and record each delivery';

    public function handle(ReminderDeliveries $deliveries): int
    {
        $company = $this->option('company');

        if ($company === null || $company === '') {
            $this->error('A reminder run is per company: pass --company=<workforce company entity id>.');

            return self::FAILURE;
        }

        $companyId = (int) $company;
        $days = $this->option('days');
        $window = $days === null || $days === '' ? ReminderRules::DEFAULT_EXPIRING_WITHIN_DAYS : (int) $days;

        if ((bool) $this->option('retry')) {
            $result = $deliveries->retry($companyId);
        } elseif ((bool) $this->option('dry-run')) {
            $result = $deliveries->preview($companyId, null, $window);
        } else {
            $result = $deliveries->send($companyId, null, $window);
        }

        $this->report($result);

        if ((bool) $this->option('dry-run')) {
            $this->line('Dry run: nothing was written or sent.');
        }

        return self::SUCCESS;
    }

    private function report(DeliveryRunResult $result): void
    {
        $this->line("sent: {$result->sent}");
        $this->line("skipped: {$result->skipped}");
        $this->line("failed: {$result->failed}");

        foreach ($result->skipReasons as $reason => $count) {
            $this->line("  skipped ({$reason}): {$count}");
        }

        foreach ($result->failures as $row) {
            $this->line($this->failureLine($row));
        }
    }

    private function failureLine(SkillReminderDelivery $row): string
    {
        return sprintf(
            'failed #%d %s employee %d skill %d%s -> user %d: %s',
            $row->id,
            $row->rule->value,
            $row->employee_entity_id,
            $row->skill_id,
            $row->developmentActionId() === null ? '' : ' action '.$row->developmentActionId(),
            $row->recipient_user_id,
            (string) $row->failure,
        );
    }
}
