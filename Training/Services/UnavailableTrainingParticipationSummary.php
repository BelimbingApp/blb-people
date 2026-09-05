<?php

namespace App\Domains\People\Training\Services;

use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;

/** Default until People #34 supplies authoritative participant facts. */
final class UnavailableTrainingParticipationSummary implements SummarizesTrainingParticipation
{
    public function forEvents(int $companyEntityId, array $trainingEventIds): array
    {
        return [];
    }
}
