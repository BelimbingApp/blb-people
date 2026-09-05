<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Training\Enums\TrainingDeliveryApproach;

final readonly class TrainingPlanItemDraft
{
    /** @param array<string, mixed>|null $budgetLine */
    public function __construct(
        public int $needTenantId,
        public int $needCompanyEntityId,
        public string $needReference,
        public string $expectedResult,
        public string $targetCohort,
        public TrainingDeliveryApproach $deliveryApproach,
        public string $responsibleOwnerReference,
        public string $intendedTiming,
        public string $evaluationApproach,
        public ?array $budgetLine = null,
    ) {}
}
