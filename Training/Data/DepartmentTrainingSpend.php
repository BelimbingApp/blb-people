<?php

namespace App\Domains\People\Training\Data;

/**
 * One department's year: what it committed, what it has asked for, and what is
 * left of what it was given.
 *
 * Every amount is a decimal string with four places, never a float. `budget`
 * and `remaining` are null when no budget has been set — "nothing allocated
 * yet" and "allocated nothing" are different facts, and only the second means
 * a department with approved spend is overspent.
 */
final readonly class DepartmentTrainingSpend
{
    public function __construct(
        public int $departmentEntityId,
        public string $departmentName,
        public string $approved,
        public string $pending,
        public ?string $budget,
        public ?string $remaining,
        public ?int $budgetId = null,
    ) {}
}
