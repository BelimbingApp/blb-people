<?php

namespace App\Domains\People\Employees\Services;

use App\Core\User\Models\User;
use App\Domains\People\Employees\Data\EmployeeStanding;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Skills\Contracts\ReadsOwnSkillStanding;
use App\Domains\People\Skills\Data\OwnAssessmentOutcome;
use DateTimeInterface;

final class EmployeeStandingReader
{
    public function __construct(private readonly ReadsOwnSkillStanding $skills) {}

    public function read(?User $actor, WorkforceSubject $subject, ?DateTimeInterface $asOf = null): EmployeeStanding
    {
        return new EmployeeStanding($this->skills->read($actor, $subject, $asOf));
    }

    public function assessment(?User $actor, WorkforceSubject $subject, int $assessmentId): OwnAssessmentOutcome
    {
        return $this->skills->assessment($actor, $subject, $assessmentId);
    }
}
