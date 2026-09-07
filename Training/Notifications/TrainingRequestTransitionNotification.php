<?php

namespace App\Domains\People\Training\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One training request transition as its recipient sees it: which request,
 * what was decided, by whom, and the page to open. The decision's notes stay
 * off the payload on purpose: the decision row holds them under the request's
 * own authorization, and an inbox is not that.
 *
 * Database channel, synchronous, like TrainingRequestReminderNotification:
 * the delivery log row records that this call happened.
 */
class TrainingRequestTransitionNotification extends Notification
{
    use Queueable;

    /** @param  array<int, string>  $channels */
    public function __construct(
        public readonly int $trainingRequestId,
        public readonly int $companyEntityId,
        public readonly int $decisionId,
        public readonly string $decision,
        public readonly string $actorName,
        public readonly string $url,
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
            'training_request_id' => $this->trainingRequestId,
            'company_entity_id' => $this->companyEntityId,
            'decision_id' => $this->decisionId,
            'decision' => $this->decision,
            'actor' => $this->actorName,
            'url' => $this->url,
        ];
    }
}
