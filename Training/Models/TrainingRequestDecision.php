<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;

final class TrainingRequestDecision extends TenantOwnedModel
{
    use CompanyOwned;

    public $timestamps = false;

    protected $table = 'people_training_request_decisions';

    protected static function booted(): void
    {
        $immutable = fn (): never => throw new InvalidTrainingRequestException('Training request decisions are append-only.');
        self::updating($immutable);
        self::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
