<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TrainingRequest extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_requests';

    protected static function booted(): void
    {
        self::updating(function (self $request): void {
            $facts = ['tenant_id', 'company_entity_id', 'request_key', 'requestor_provider_id',
                'requestor_subject_id', 'department_provider_id', 'department_subject_id', 'need_source',
                'need', 'learning_objective', 'expected_result', 'priority', 'skill_gap_assessment_id',
                'requirement_version', 'created_by_user_id'];
            if ($request->isDirty($facts)) {
                throw new InvalidTrainingRequestException('Training request facts are immutable.');
            }
        });
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(TrainingRequestDecision::class, 'training_request_id')
            ->forCompany((int) $this->tenant_id, (int) $this->company_entity_id)->orderBy('id');
    }

    protected function casts(): array
    {
        return ['need_source' => TrainingNeedSource::class, 'priority' => TrainingPriority::class,
            'status' => TrainingRequestStatus::class, 'requirement_version' => 'integer'];
    }
}
