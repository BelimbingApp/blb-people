<?php

namespace App\Domains\People\Training\Enums;

/**
 * Draft and completed are distinct states.
 *
 * The contract is explicit that an average, a due date passing, HR reading a
 * form, or an event becoming complete does not mark an evaluation completed.
 * Only submission against the pinned criteria version does.
 */
enum TrainingEvaluationStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
}
