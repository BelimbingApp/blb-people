<?php

namespace App\Domains\People\Progression\Enums;

/** Where a rule's evidence comes from. */
enum ProgressionRuleSource: string
{
    case Competence = 'competence';
    case Performance = 'performance';
}
