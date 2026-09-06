<?php

namespace App\Domains\People\Training\Enums;

enum EffectivenessReviewState: string
{
    case Open = 'open';
    case OutcomeRecorded = 'outcome_recorded';
    case Closed = 'closed';
}
