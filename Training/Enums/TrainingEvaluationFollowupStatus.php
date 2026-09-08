<?php

namespace App\Domains\People\Training\Enums;

/**
 * Where a follow-up stands. Closed is terminal: a new follow-up of the same
 * kind opens a new row rather than resurrecting this one, so the audit keeps
 * one entry per transition with no rewrites.
 */
enum TrainingEvaluationFollowupStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Closed = 'closed';
}
