<?php

namespace App\Domains\People\Progression\Contracts;

use App\Domains\People\Progression\Data\PublishedProgressionPolicy;
use App\Domains\People\Progression\Enums\ProgressionPolicyRefusal;
use App\Domains\People\Provider\Data\WorkforceSubject;

interface ReadsPublishedProgressionPolicy
{
    public function read(WorkforceSubject $subject): PublishedProgressionPolicy|ProgressionPolicyRefusal;
}
