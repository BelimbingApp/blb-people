<?php

namespace App\Domains\People\Employees\Data;

use App\Domains\People\Employees\Enums\TrainingHistoryAvailability;
use App\Domains\People\Skills\Data\OwnSkillStanding;

final readonly class EmployeeStanding
{
    public TrainingHistoryAvailability $trainingAvailability;

    public null $trainingParticipation;

    public null $trainingCertificates;

    public function __construct(public OwnSkillStanding $skills)
    {
        // People #34 has not supplied employee-level participation/certificate records.
        $this->trainingAvailability = TrainingHistoryAvailability::Unsupported;
        $this->trainingParticipation = null;
        $this->trainingCertificates = null;
    }
}
