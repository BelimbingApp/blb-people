<?php

namespace App\Modules\People\Claim\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Exceptions\ClaimCohortPredicateException;

/**
 * Evaluates a cohort_predicate JSON against an employee.
 *
 * Predicate shape (v1):
 *   { "employee_type": "regular", "department_id": [1, 2] }
 *
 * Each top-level key is an allowlisted Employee column. Value is either a scalar (exact
 * match) or a list (IN match). All clauses are AND-ed. Null or empty predicate matches
 * everyone. Unknown keys fail closed — a typo in a predicate should surface as an
 * exception rather than silently waving the cohort gate through.
 */
class ClaimCohortPredicateService
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'employee_type',
        'department_id',
        'supervisor_id',
        'status',
    ];

    /**
     * @param  array<string, mixed>|null  $predicate
     */
    public function matches(Employee $employee, ?array $predicate): bool
    {
        if ($predicate === null || $predicate === []) {
            return true;
        }

        foreach ($predicate as $key => $expected) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw ClaimCohortPredicateException::unknownKey($key, self::ALLOWED_KEYS);
            }

            $actual = $employee->getAttribute($key);

            if (is_array($expected)) {
                if (! in_array($actual, $expected, false)) {
                    return false;
                }

                continue;
            }

            if ($actual != $expected) { // intentional loose: ids may be int vs string
                return false;
            }
        }

        return true;
    }
}
