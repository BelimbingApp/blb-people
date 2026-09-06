<?php

namespace App\Domains\People\Training\Enums;

/**
 * Kept separate so a non-assessable close never counts as verified competence
 * in an outcome metric. The contract asks for a "separately identifiable"
 * state, not a second meaning for the effective one.
 */
enum EffectivenessClosureRoute: string
{
    case Reassessment = 'reassessment';
    case NonAssessable = 'non_assessable';
}
