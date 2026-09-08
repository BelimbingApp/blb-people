<?php

namespace App\Domains\People\Skills\Notifications;

use App\Domains\People\Skills\Data\DueReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One reminder as the recipient sees it: the reason, the subject, the due
 * date, and the exact page where the remedy lives.
 *
 * Stored on the database channel like the workflow notifications, so the
 * in-app inbox is the one place a recipient reads People notices. Sent
 * synchronously and never queued: the delivery ledger records the outcome of
 * this call, and a queued send would report "sent" before anything was.
 */
class SkillReminderNotification extends Notification
{
    use Queueable;

    /** @param  array<int, string>  $channels */
    public function __construct(
        public readonly DueReminder $reminder,
        public readonly string $url,
        public readonly string $periodKey,
        public readonly array $channels = ['database'],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'rule' => $this->reminder->rule->value,
            'company_entity_id' => $this->reminder->companyEntityId,
            'employee_entity_id' => $this->reminder->employeeEntityId,
            'skill_id' => $this->reminder->skillId,
            'development_action_id' => $this->reminder->developmentActionId,
            'department_id' => $this->reminder->departmentId,
            'holders' => $this->reminder->holders,
            'minimum' => $this->reminder->minimum,
            'requirement_reference' => $this->reminder->requirementReference,
            'requirement_version' => $this->reminder->requirementVersion,
            'due_on' => $this->reminder->dueOn->format('Y-m-d'),
            'period_key' => $this->periodKey,
            'url' => $this->url,
        ];
    }
}
