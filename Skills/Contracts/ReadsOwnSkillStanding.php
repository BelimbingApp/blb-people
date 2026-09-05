<?php

namespace App\Domains\People\Skills\Contracts;

use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Skills\Data\OwnAssessmentOutcome;
use App\Domains\People\Skills\Data\OwnSkillStanding;
use DateTimeInterface;

interface ReadsOwnSkillStanding
{
    public function read(?User $actor, WorkforceSubject $subject, ?DateTimeInterface $asOf = null): OwnSkillStanding;

    public function assessment(?User $actor, WorkforceSubject $subject, int $assessmentId): OwnAssessmentOutcome;
}
