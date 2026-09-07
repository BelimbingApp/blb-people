<?php

namespace App\Domains\People\Skills\Data;

use DateTimeImmutable;

/**
 * The #16 contractual KPIs for one company at one as-of moment: a company
 * row and one row per department. Every metric carries its value, the
 * definition it was computed under and the as-of, so a number never travels
 * without the sentence that says what it counts (0007-e, #363).
 *
 * Rates are null, never 0, when their denominator is 0: "nobody was expected"
 * and "nobody was covered" are different facts and the page shows them
 * differently.
 *
 * @phpstan-type Metric array{value: int|float|null, definition: string, as_of: DateTimeImmutable}
 * @phpstan-type Metrics array<string, Metric>
 */
final readonly class SkillKpiSummaryResult
{
    /** Metric key => [label, #16 definition, kind]. Order is the page order. */
    public const METRICS = [
        'active_staff' => ['Active Staff', 'Active Staff counts active employees in the current workforce projection.', 'count'],
        'expected_assessments' => ['Expected Staff-Skill Assessments', 'Expected Staff-Skill Assessments (sum of active staff required-skill counts).', 'count'],
        'latest_records' => ['Latest Assessment Records', 'Latest Assessment Records are finalized assessments that no later assessment supersedes, scored with a result band other than Not Assessed.', 'count'],
        'assessment_coverage' => ['Assessment Coverage', 'Assessment Coverage = latest scored assessment records / expected assessments.', 'rate'],
        'verified_competent' => ['Verified Competent Records', 'Verified Competent Records are latest records that are Meets / Exceeds + HOD Verified + Current (valid-until empty or on/after the as-of date).', 'count'],
        'verified_competency_rate' => ['Verified Competency Rate', 'Verified Competency Rate = latest records that are Meets / Exceeds + HOD Verified + Current / latest scored assessment records.', 'rate'],
        'major_critical_gaps' => ['Major/Critical Gaps', 'Major/Critical Gaps count only latest results classified Major Gap or Critical Gap.', 'count'],
        'open_actions' => ['Open Development Actions', 'Open Actions include Open, Pending Reassessment, and Further Action Required.', 'count'],
        'overdue_actions' => ['Overdue Actions', 'Open Actions include Open, Pending Reassessment, and Further Action Required; overdue means Days Overdue > 0.', 'count'],
        'due_within_30_days' => ['Assessments Due/Expired within 30 days', 'Due/Expired ≤30d counts latest scored assessments whose next-due date is on/before today + 30 days.', 'count'],
        'scheduled_training' => ['Scheduled/Active Training', 'Scheduled/Active Training counts Scheduled and In Progress records.', 'count'],
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

    /** @return list<string> */
    public static function countMetrics(): array
    {
        return array_keys(array_filter(self::METRICS, static fn (array $metric): bool => $metric[2] === 'count'));
    }

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
