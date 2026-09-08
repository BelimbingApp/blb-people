<?php

namespace App\Domains\People\Training\Data;

use App\Domains\People\Provider\Data\WorkforceSubject;

/**
 * Who a training request is for.
 *
 * Two shapes, because they are two different asks. A list of subjects is
 * "these people"; a cohort is "whoever is in this department", answered once
 * at draft time and then frozen. Storing the resolved roster either way means
 * an approval months later enrols the people the approver saw, not whoever
 * has since joined.
 */
final readonly class TrainingRequestSubjectsDraft
{
    /** @param  list<WorkforceSubject>  $subjects */
    private function __construct(
        public array $subjects,
        public bool $cohort,
    ) {}

    /** @param  list<WorkforceSubject>  $subjects */
    public static function forSubjects(array $subjects): self
    {
        return new self(array_values($subjects), false);
    }

    /** Everyone active in the request's own department, resolved at draft time. */
    public static function forDepartmentCohort(): self
    {
        return new self([], true);
    }
}
