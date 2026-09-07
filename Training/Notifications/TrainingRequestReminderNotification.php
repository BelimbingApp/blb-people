<?php

namespace App\Domains\People\Training\Notifications;

use App\Domains\People\Training\Data\DueTrainingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One approved-but-unlinked request as HR sees it: which request, how long it
 * has waited, and the register page already filtered to the ones like it.
 *
 * Stored on the database channel like the Skills reminders and sent
 * synchronously: the reminder row records that this call happened, and a
 * queued send would record "sent" before anything was.
 */
class TrainingRequestReminderNotification extends Notification
{
    use Queueable;

    /** @param  array<int, string>  $channels */
    public function __construct(
        public readonly DueTrainingRequest $request,
        public readonly int $companyEntityId,
        public readonly string $url,
        public readonly string $weekKey,
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
            'training_request_id' => $this->request->requestId,
            'company_entity_id' => $this->companyEntityId,
            'need' => $this->request->need,
            'approved_on' => $this->request->approvedOn->toDateString(),
            'days_unlinked' => $this->request->daysUnlinked,
            'week_key' => $this->weekKey,
            'url' => $this->url,
        ];
    }
}
