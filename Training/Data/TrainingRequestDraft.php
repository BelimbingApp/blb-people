<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;

final readonly class TrainingRequestDraft
{
    public function __construct(
        public WorkforceSubject $requestor,
        public WorkforceSubject $department,
        public TrainingNeedSource $needSource,
        public string $need,
        public string $learningObjective,
        public string $expectedResult,
        public TrainingPriority $priority,
        public ?int $skillGapAssessmentId = null,
        public ?int $requirementVersion = null,
        /** Decimal string with 4 places, or null when nobody has priced it. */
        public ?string $estimatedCost = null,
        public ?string $proposedDeliveryMethod = null,
        public ?string $proposedProvider = null,
        public ?string $proposedStartDate = null,
        public ?string $proposedEndDate = null,
    ) {}
}
