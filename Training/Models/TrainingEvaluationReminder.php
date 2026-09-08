<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** That a participant was reminded of their evaluation, once per day. */
final class TrainingEvaluationReminder extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_evaluation_reminders';

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'notified_at' => 'immutable_datetime',
        ];
    }
}
