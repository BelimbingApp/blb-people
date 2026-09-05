<?php

namespace App\Domains\People\Training\Contracts;

use App\Domains\People\Training\Data\TrainingParticipationSummary;

interface SummarizesTrainingParticipation
{
    /**
     * @param  list<int>  $trainingEventIds
     * @return array<int, TrainingParticipationSummary>
     */
    public function forEvents(int $companyEntityId, array $trainingEventIds): array;
}
