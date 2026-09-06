<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;

/** That a HOD was asked, once per participant and checkpoint. */
final class TrainingEffectivenessReminder extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_effectiveness_reminders';

    protected function casts(): array
    {
        return [
            'checkpoint' => EffectivenessCheckpoint::class,
            'notified_at' => 'immutable_datetime',
        ];
    }
}
