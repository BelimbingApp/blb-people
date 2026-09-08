<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\PilotSignoffRole;
use App\Domains\People\Training\Exceptions\InvalidPilotSignoffException;

/**
 * That a HOD or HR signed a department's pilot readiness. Append-only: a
 * signature that can be edited or removed is not a signature.
 */
final class TrainingPilotSignoff extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_pilot_signoffs';

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new InvalidPilotSignoffException('A pilot sign-off cannot be modified.');
        });

        self::deleting(function (): void {
            throw new InvalidPilotSignoffException('A pilot sign-off cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'organization_unit_entity_id' => 'integer',
            'role' => PilotSignoffRole::class,
            'signed_by' => 'integer',
            'signed_at' => 'immutable_datetime',
            'readiness_snapshot' => 'array',
        ];
    }
}
