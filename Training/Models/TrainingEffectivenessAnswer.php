<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;

/** One HOD's answer at one checkpoint for one participant. */
final class TrainingEffectivenessAnswer extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_effectiveness_answers';

    protected function casts(): array
    {
        return [
            'checkpoint' => EffectivenessCheckpoint::class,
            'rating' => 'integer',
            'answered_at' => 'immutable_datetime',
        ];
    }
}
