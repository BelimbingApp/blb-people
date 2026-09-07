<?php

namespace App\Domains\People\Training\Data;

use DateTimeImmutable;

/**
 * The revised-workbook training controls for one company at one as-of date:
 * six company-level controls and eleven columns per department (0007-f,
 * #389). Every metric carries its value, the definition it was computed
 * under, the as-of and the drill-down that lists the records behind it, so
 * a number never travels without the sentence that says what it counts.
 *
 * Rates and means are null, never 0, when their denominator is 0: "nobody
 * attended" and "nobody completed" are different facts and the page shows
 * them differently.
 *
 * @phpstan-type Metric array{key: string, label: string, value: int|float|null, definition: string, as_of: DateTimeImmutable, drill: array{route: string, params: array<string, string>}}
 * @phpstan-type Metrics array<string, Metric>
 */
final readonly class TrainingKpiSummaryResult
{
    /** Company control key => [label, definition, kind]. Order is the page order. */
    public const COMPANY = [
        'pending_requests' => ['Pending Requests', 'Pending Requests counts training requests awaiting HOD, HR or final approval, raised on or before the as-of date.', 'count'],
        'approved_not_linked' => ['Approved — Not Linked', 'Approved — Not Linked counts approved requests that no scheduled training event satisfies yet (the register\'s approved_unlinked filter).', 'count'],
        'attended' => ['Attended Records', 'Attended Records counts enrolled participants with a current attendance fact of Present on an event that ended on or before the as-of date.', 'count'],
        'pending_evaluations' => ['Pending Evaluations', 'Pending Evaluations counts attended participants whose evaluation is due on or before the as-of date and is not completed.', 'count'],
        'overdue_effectiveness' => ['Overdue Effectiveness', 'Overdue Effectiveness counts 30/60/90-day checkpoints open on the as-of date that the HOD has not answered.', 'count'],
        'closed_effective' => ['Closed Effective Reviews', 'Closed Effective Reviews counts effectiveness reviews closed on or before the as-of date with outcome Effective.', 'count'],
    ];

    /** Department column key => [label, definition, kind]. Order is the page order. */
    public const DEPARTMENT = [
        'requests' => ['Requests', 'Requests counts every training request of the department raised on or before the as-of date, whatever its status.', 'count'],
        'approved' => ['Approved', 'Approved counts the department\'s requests whose status is Approved.', 'count'],
        'attended' => ['Attended', 'Attended counts the department\'s participants with a current attendance fact of Present on an event that ended on or before the as-of date.', 'count'],
        'evaluation_completion' => ['Evaluation Completion', 'Evaluation Completion = completed evaluations / attended participants.', 'rate'],
        'avg_evaluation' => ['Avg Evaluation', 'Avg Evaluation is the mean of the five criterion ratings over completed evaluations, on the 1–5 scale.', 'mean'],
        'effectiveness_reviews' => ['Effectiveness Reviews', 'Effectiveness Reviews counts reviews opened on or before the as-of date for the department\'s participants.', 'count'],
        'effective_pct' => ['Effective %', 'Effective % = closed reviews with outcome Effective / closed reviews.', 'rate'],
        'skill_improvement' => ['Skill Improvement', 'Skill Improvement counts closed reviews whose verified post-training level exceeds the baseline level.', 'count'],
        'training_hours' => ['Training Hours', 'Training Hours sums the actual minutes of current Present attendance facts, in hours.', 'hours'],
        'certificates' => ['Certificates', 'Certificates counts current attendance facts carrying a certificate reference.', 'count'],
        'open_followup' => ['Open Follow-up', 'Open Follow-up counts development actions opened by effectiveness reviews that are still Open, Pending Reassessment or Further Action Required.', 'count'],
    ];

    /**
     * @param  Metrics  $company
     * @param  list<array{department_entity_id: int|null, department: string, metrics: Metrics}>  $departments
     */
    public function __construct(
        public int $companyEntityId,
        public DateTimeImmutable $asOf,
        public array $company,
        public array $departments,
    ) {}

    public function department(?int $departmentEntityId): ?array
    {
        foreach ($this->departments as $row) {
            if ($row['department_entity_id'] === $departmentEntityId) {
                return $row;
            }
        }

        return null;
    }
}
