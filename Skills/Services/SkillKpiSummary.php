<?php

namespace App\Domains\People\Skills\Services;

use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\SkillKpiSummaryResult;
use App\Domains\People\Skills\Enums\AssessmentResultBand;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Enums\HodVerification;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Models\TrainingEvent;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The #16 workbook KPIs per company and department (0007-e, #363).
 *
 * One pass over four sources — the workforce projection, the assessment
 * history, development actions and training events — each read with one
 * company-pinned query and attributed to a department in PHP through the
 * employee's organisation unit, the same mapping OrganisationSkillCoverage
 * uses. Requirements are resolved once per distinct department/position
 * cohort rather than per employee: selectors never look at the individual.
 *
 * Every date comparison happens on the Y-m-d string of a value the database
 * may have stored with a time part, so an assessment valid until "today
 * 00:30" is still valid today.
 */
final class SkillKpiSummary
{
    /** @var list<string> */
    private const OPEN_CLOSURES = [
        DevelopmentActionClosure::Open->value,
        DevelopmentActionClosure::PendingReassessment->value,
        DevelopmentActionClosure::FurtherActionRequired->value,
    ];

    /** @var list<string> */
    private const COMPETENT_BANDS = [AssessmentResultBand::Meets->value, AssessmentResultBand::Exceeds->value];

    /** @var list<string> */
    private const GAP_BANDS = [AssessmentResultBand::MajorGap->value, AssessmentResultBand::CriticalGap->value];

    public function __construct(
        private readonly WorkforceSubjects $workforce,
        private readonly ResolvesSkillRequirements $requirements,
    ) {}

    public function forCompany(int $tenantId, int $companyEntityId, ?DateTimeInterface $asOf = null): SkillKpiSummaryResult
    {
        $asOf = $asOf === null ? now()->toImmutable() : DateTimeImmutable::createFromInterface($asOf);
        $asOfDate = $asOf->format('Y-m-d');
        $dueHorizon = $asOf->modify('+30 days')->format('Y-m-d');

        $employees = array_values(array_filter(
            $this->workforce->employees($companyEntityId),
            static fn (WorkforceEmployee $employee): bool => $employee->active && ctype_digit($employee->reference->externalId),
        ));
        $departmentOf = [];
        $expectedByEmployee = [];
        $cohorts = [];

        foreach ($employees as $employee) {
            $employeeId = (int) $employee->reference->externalId;
            $departmentId = $this->numeric($employee->organizationReference?->externalId);
            $positionId = $this->numeric($employee->positionReference?->externalId);
            $departmentOf[$employeeId] = $departmentId;
            $cohort = ($departmentId ?? 'none').':'.($positionId ?? 'none');
            $cohorts[$cohort] ??= count($this->requirements->requirementsFor([
                'company_entity_id' => $companyEntityId,
                'department_entity_id' => $departmentId,
                'position_entity_id' => $positionId,
                'employee_entity_id' => $employeeId,
            ], $asOf));
            $expectedByEmployee[$employeeId] = $cohorts[$cohort];
        }

        $employeeIds = array_keys($departmentOf);
        $tally = [];
        $bump = static function (?int $departmentId, string $metric, int $by = 1) use (&$tally): void {
            $tally[$departmentId ?? 'none'][$metric] = ($tally[$departmentId ?? 'none'][$metric] ?? 0) + $by;
        };

        foreach ($departmentOf as $employeeId => $departmentId) {
            $bump($departmentId, 'active_staff');
            $bump($departmentId, 'expected_assessments', $expectedByEmployee[$employeeId]);
        }

        $assessments = $employeeIds === [] ? collect() : SkillAssessment::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('employee_entity_id', $employeeIds)
            ->get(['id', 'employee_entity_id', 'status', 'result_band', 'hod_verification', 'valid_until', 'next_assessment_due', 'supersedes_assessment_id']);
        $superseded = $assessments->pluck('supersedes_assessment_id')->filter()->map(static fn (mixed $id): int => (int) $id)->flip();

        foreach ($assessments as $assessment) {
            if ($assessment->status !== AssessmentStatus::Finalized
                || $superseded->has((int) $assessment->id)
                || $assessment->result_band === null
                || $assessment->result_band === AssessmentResultBand::NotAssessed) {
                continue;
            }

            $departmentId = $departmentOf[(int) $assessment->employee_entity_id];
            $band = $assessment->result_band->value;
            $bump($departmentId, 'latest_records');

            if (in_array($band, self::COMPETENT_BANDS, true)
                && $assessment->hod_verification === HodVerification::Verified
                && ($assessment->valid_until === null || $assessment->valid_until->format('Y-m-d') >= $asOfDate)) {
                $bump($departmentId, 'verified_competent');
            }

            if (in_array($band, self::GAP_BANDS, true)) {
                $bump($departmentId, 'major_critical_gaps');
            }

            if ($assessment->next_assessment_due !== null && $assessment->next_assessment_due->format('Y-m-d') <= $dueHorizon) {
                $bump($departmentId, 'due_within_30_days');
            }
        }

        $actions = $employeeIds === [] ? collect() : DevelopmentAction::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('employee_entity_id', $employeeIds)
            ->whereIn('closure_status', self::OPEN_CLOSURES)
            ->get(['employee_entity_id', 'due_date']);

        foreach ($actions as $action) {
            $departmentId = $departmentOf[(int) $action->employee_entity_id];
            $bump($departmentId, 'open_actions');

            if ($action->due_date !== null && $action->due_date->format('Y-m-d') < $asOfDate) {
                $bump($departmentId, 'overdue_actions');
            }
        }

        $events = TrainingEvent::query()
            ->forCompany($tenantId, $companyEntityId)
            ->whereIn('status', [TrainingEventStatus::Scheduled->value, TrainingEventStatus::InProgress->value])
            ->get(['target_department_entity_id']);

        foreach ($events as $event) {
            $bump($event->target_department_entity_id === null ? null : (int) $event->target_department_entity_id, 'scheduled_training');
        }

        $names = [];
        foreach ($this->workforce->organizationUnits($companyEntityId) as $unit) {
            if (ctype_digit($unit->reference->externalId)) {
                $names[(int) $unit->reference->externalId] = $unit->name;
            }
        }

        $departments = [];
        foreach ($tally as $key => $counts) {
            $departmentId = $key === 'none' ? null : (int) $key;
            $departments[] = [
                'department_entity_id' => $departmentId,
                'department' => $departmentId === null ? (string) __('No department') : ($names[$departmentId] ?? (string) __('Unknown department')),
                'metrics' => $this->metrics($counts, $asOf),
            ];
        }

        usort($departments, static fn (array $a, array $b): int => [$a['department_entity_id'] === null, $a['department']] <=> [$b['department_entity_id'] === null, $b['department']]);

        $companyCounts = [];
        foreach ($tally as $counts) {
            foreach ($counts as $metric => $value) {
                $companyCounts[$metric] = ($companyCounts[$metric] ?? 0) + $value;
            }
        }

        return new SkillKpiSummaryResult($companyEntityId, $asOf, $this->metrics($companyCounts, $asOf), $departments);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, array{value: int|float|null, definition: string, as_of: DateTimeImmutable}>
     */
    private function metrics(array $counts, DateTimeImmutable $asOf): array
    {
        $metrics = [];

        foreach (SkillKpiSummaryResult::METRICS as $key => [, $definition, $kind]) {
            $value = match ($key) {
                'assessment_coverage' => $this->rate($counts['latest_records'] ?? 0, $counts['expected_assessments'] ?? 0),
                'verified_competency_rate' => $this->rate($counts['verified_competent'] ?? 0, $counts['latest_records'] ?? 0),
                default => $counts[$key] ?? 0,
            };
            $metrics[$key] = ['value' => $value, 'definition' => $definition, 'as_of' => $asOf];
        }

        return $metrics;
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : $numerator / $denominator;
    }

    private function numeric(?string $value): ?int
    {
        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
