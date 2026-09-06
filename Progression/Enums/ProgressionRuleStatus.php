<?php

namespace App\Domains\People\Progression\Enums;

/**
 * Whether a rule is satisfied, and the third answer that matters most.
 *
 * Unknown is not a soft "not met". Nobody has assessed this person against this
 * rule, which is a different fact about them, and the plan is explicit that
 * unknown is never zero: reporting a failure where there is only an absence
 * puts something on a person's record that nobody observed.
 */
enum ProgressionRuleStatus: string
{
    case Met = 'met';
    case NotMet = 'not_met';
    case Unknown = 'unknown';
}
