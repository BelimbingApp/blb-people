<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** That an HR user was reminded of an approved-but-unlinked request, once per ISO week. */
final class TrainingRequestReminder extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_request_reminders';

    protected function casts(): array
    {
        return [
            'training_request_id' => 'integer',
            'recipient_user_id' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
