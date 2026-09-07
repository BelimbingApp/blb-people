<?php

namespace App\Domains\People\Training\Services;

use App\Domains\People\Skills\Enums\DevelopmentActionClosure;
use App\Domains\People\Skills\Models\DevelopmentAction;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\DueEvaluation;
use App\Domains\People\Training\Data\OpenEffectivenessCheckpoint;
use App\Domains\People\Training\Data\TrainingKpiSummaryResult;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\EffectivenessOutcome;
use App\Domains\People\Training\Enums\EffectivenessReviewState;
use App\Domains\People\Training\Enums\TrainingEvaluationStatus;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Livewire\Requests\Register;
use App\Domains\People\Training\Models\TrainingEffectivenessReview;
use App\Domains\People\Training\Models\TrainingEvaluation;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingRequest;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * The revised-workbook training controls per company and department
 * (0007-f, #389).
 *
 * One pass over four sources — requests, participation facts, evaluations
 * and effectiveness reviews — each read with one company-pinned query and
 * attributed to a department in PHP: a request through the department it
 * names, everything else through the participant's employee and the
 * organisation unit of the workforce projection, the same mapping the
 * request register and the evaluations dashboard filter by.
 *
 * "As of" is a date. Date columns are read with whereDate or compared on
 * their Y-m-d after loading, never as a bare string predicate: SQLite
 * stores a date column with whatever time part it was given, and a fact
 * dated "today 00:30" is still today's fact. Sources that take a moment
 * (evaluation reminders, effectiveness checkpoints) get the end of the
 * as-of day, so a question that opened during that day counts.
 */
final class TrainingKpiSummary
{
    /** @var list<string> */
    private const PENDING_STATUSES = [
        TrainingRequestStatus::PendingHod->value,
        TrainingRequestStatus::PendingHr->value,
        TrainingRequestStatus::PendingApproval->value,
    ];

    /** @var list<string> */
    private const OPEN_CLOSURES = [
        DevelopmentActionClosure::Open->value,
        DevelopmentActionClosure::PendingReassessment->value,
        DevelopmentActionClosure::FurtherActionRequired->value,
    ];

    /** @var list<string> */
    private const RATINGS = ['relevance', 'trainer_effectiveness', 'materials_exercises', 'pace_duration', 'practical_usefulness'];

    public function __construct(
        private readonly WorkforceSubjects $workforce,
        private readonly TrainingRequestStore $requests,
        private readonly DatabaseTrainingParticipationSummary $participation,
        private readonly TrainingEvaluationReminders $evaluationReminders,
        private readonly TrainingEffectivenessCheckpoints $checkpoints,
    ) {}

    public function forCompany(int $tenantId, int $companyEntityId, DateTimeImmutable $asOf): TrainingKpiSummaryResult
    {
        $asOfDate = $asOf->format('Y-m-d');
        $moment = CarbonImmutable::instance($asOf)->endOfDay();
        $unitOf = $this->unitOfEmployee($companyEntityId);
        $tally = [];
        $bump = static function (?int $departmentId, string $metric, int|float $by = 1) use (&$tally): void {
            $tally[$departmentId ?? 'none'][$metric] = ($tally[$departmentId ?? 'none'][$metric] ?? 0) + $by;
        };
        $company = [];

        // Requests: attributed to the department the request names.
        $requests = TrainingRequest::query()->forCompany($tenantId, $companyEntityId)
            ->whereDate('created_at', '<=', $asOfDate)
            ->get(['id', 'department_subject_id', 'status']);
        foreach ($requests as $request) {
            $departmentId = ctype_digit((string) $request->department_subject_id) ? (int) $request->department_subject_id : null;
            $bump($departmentId, 'requests');
            if ($request->status === TrainingRequestStatus::Approved) {
                $bump($departmentId, 'approved');
            }
            if (in_array($request->status->value, self::PENDING_STATUSES, true)) {
                $company['pending_requests'] = ($company['pending_requests'] ?? 0) + 1;
            }
        }
        $company['approved_not_linked'] = $this->requests->approvedUnlinkedQuery($tenantId, $companyEntityId)
            ->whereDate('created_at', '<=', $asOfDate)->count();

        // Participation: events ended by the as-of date, their enrolled
        // participants and the current facts of each.
        $eventIds = TrainingEvent::query()->forCompany($tenantId, $companyEntityId)
            ->whereDate('ends_at', '<=', $asOfDate)->pluck('id')->map(intval(...))->all();
        $company['attended'] = array_sum(array_map(
            static fn (object $summary): int => $summary->attended,
            $this->participation->forEvents($companyEntityId, $eventIds),
        ));
        $participants = $eventIds === [] ? collect() : TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('event_id', $eventIds)->whereNull('withdrawn_at')->get(['id', 'employee_subject_id']);
        $departmentOfParticipant = [];
        foreach ($participants as $participant) {
            $departmentOfParticipant[(int) $participant->id] = $unitOf[(int) $participant->employee_subject_id] ?? null;
        }
        $participantIds = array_keys($departmentOfParticipant);
        $facts = $participantIds === [] ? collect() : TrainingParticipationFact::query()->forCompany($tenantId, $companyEntityId)
            ->current()->whereIn('participant_id', $participantIds)
            ->get(['participant_id', 'attendance', 'actual_minutes', 'certificate_reference']);
        $attendedParticipants = [];
        foreach ($facts as $fact) {
            $departmentId = $departmentOfParticipant[(int) $fact->participant_id];
            if ($fact->certificate_reference !== null && trim((string) $fact->certificate_reference) !== '') {
                $bump($departmentId, 'certificates');
            }
            if ($fact->attendance !== AttendanceStatus::Present) {
                continue;
            }
            $bump($departmentId, 'minutes', (int) $fact->actual_minutes);
            if (! isset($attendedParticipants[(int) $fact->participant_id])) {
                $attendedParticipants[(int) $fact->participant_id] = true;
                $bump($departmentId, 'attended');
            }
        }

        // Evaluations: only the rating and state columns are selected, so
        // the participant's free text never leaves the table for a summary.
        $evaluations = $participantIds === [] ? collect() : TrainingEvaluation::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('participant_id', $participantIds)
            ->where('status', TrainingEvaluationStatus::Completed->value)
            ->whereDate('completed_at', '<=', $asOfDate)
            ->get(['participant_id', ...self::RATINGS]);
        foreach ($evaluations as $evaluation) {
            $departmentId = $departmentOfParticipant[(int) $evaluation->participant_id];
            $ratings = array_values(array_filter(array_map(static fn (string $c): ?int => $evaluation->{$c} === null ? null : (int) $evaluation->{$c}, self::RATINGS), static fn (?int $r): bool => $r !== null));
            $bump($departmentId, 'completed_evaluations');
            if ($ratings !== []) {
                $bump($departmentId, 'rated_evaluations');
                $bump($departmentId, 'rating_sum', array_sum($ratings) / count($ratings));
            }
        }
        $company['pending_evaluations'] = count(array_filter(
            $this->evaluationReminders->due($tenantId, $companyEntityId, $moment),
            static fn (DueEvaluation $row): bool => $row->daysOverdue >= 0,
        ));

        // Effectiveness: reviews of the company's participants, and the
        // checkpoints open on the as-of date that nobody answered.
        $reviews = TrainingEffectivenessReview::query()->forCompany($tenantId, $companyEntityId)
            ->whereDate('created_at', '<=', $asOfDate)
            ->get(['training_participant_id', 'state', 'outcome', 'closed_at', 'baseline_level', 'post_level', 'development_action_id']);
        $reviewParticipantIds = $reviews->pluck('training_participant_id')->map(intval(...))->unique()->diff($participantIds)->all();
        $reviewParticipants = $reviewParticipantIds === [] ? collect() : TrainingParticipant::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $reviewParticipantIds)->get(['id', 'employee_subject_id']);
        foreach ($reviewParticipants as $participant) {
            $departmentOfParticipant[(int) $participant->id] = $unitOf[(int) $participant->employee_subject_id] ?? null;
        }
        $linkedActions = $reviews->pluck('development_action_id')->filter()->map(intval(...))->unique()->all();
        $openActions = $linkedActions === [] ? [] : DevelopmentAction::query()->forCompany($tenantId, $companyEntityId)
            ->whereIn('id', $linkedActions)->whereIn('closure_status', self::OPEN_CLOSURES)->pluck('id')->map(intval(...))->flip()->all();
        $countedActions = [];
        foreach ($reviews as $review) {
            $departmentId = $departmentOfParticipant[(int) $review->training_participant_id] ?? null;
            $bump($departmentId, 'effectiveness_reviews');
            $actionId = $review->development_action_id === null ? null : (int) $review->development_action_id;
            if ($actionId !== null && isset($openActions[$actionId]) && ! isset($countedActions[$actionId])) {
                $countedActions[$actionId] = true;
                $bump($departmentId, 'open_followup');
            }
            if ($review->state !== EffectivenessReviewState::Closed
                || $review->closed_at === null || $review->closed_at->format('Y-m-d') > $asOfDate) {
                continue;
            }
            $bump($departmentId, 'closed_reviews');
            if ($review->outcome === EffectivenessOutcome::Effective) {
                $bump($departmentId, 'closed_effective');
                $company['closed_effective'] = ($company['closed_effective'] ?? 0) + 1;
            }
            if ($review->post_level !== null && $review->baseline_level !== null && $review->post_level > $review->baseline_level) {
                $bump($departmentId, 'skill_improvement');
            }
        }
        $company['overdue_effectiveness'] = count(array_filter(
            $this->checkpoints->open($tenantId, $companyEntityId, $moment),
            static fn (OpenEffectivenessCheckpoint $row): bool => ! $row->answered,
        ));

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
                'metrics' => $this->metrics(TrainingKpiSummaryResult::DEPARTMENT, $counts, $asOf, $departmentId),
            ];
        }
        usort($departments, static fn (array $a, array $b): int => [$a['department_entity_id'] === null, $a['department']] <=> [$b['department_entity_id'] === null, $b['department']]);

        return new TrainingKpiSummaryResult(
            $companyEntityId, $asOf, $this->metrics(TrainingKpiSummaryResult::COMPANY, $company, $asOf, null), $departments,
        );
    }

    /**
     * @param  array<string, array{0: string, 1: string, 2: string}>  $catalog
     * @param  array<string, int|float>  $counts
     * @return array<string, array{key: string, label: string, value: int|float|null, definition: string, as_of: DateTimeImmutable, drill: array{route: string, params: array<string, string>}}>
     */
    private function metrics(array $catalog, array $counts, DateTimeImmutable $asOf, ?int $departmentId): array
    {
        $metrics = [];
        foreach ($catalog as $key => [$label, $definition]) {
            $value = match ($key) {
                'evaluation_completion' => $this->rate($counts['completed_evaluations'] ?? 0, $counts['attended'] ?? 0),
                'avg_evaluation' => $this->rate($counts['rating_sum'] ?? 0, $counts['rated_evaluations'] ?? 0),
                'effective_pct' => $this->rate($counts['closed_effective'] ?? 0, $counts['closed_reviews'] ?? 0),
                'training_hours' => round(($counts['minutes'] ?? 0) / 60, 1),
                default => (int) ($counts[$key] ?? 0),
            };
            $metrics[$key] = ['key' => $key, 'label' => $label, 'value' => $value, 'definition' => $definition, 'as_of' => $asOf, 'drill' => $this->drill($key, $departmentId)];
        }

        return $metrics;
    }

    /**
     * The existing page and filter that lists the records behind a metric.
     *
     * @return array{route: string, params: array<string, string>}
     */
    private function drill(string $key, ?int $departmentId): array
    {
        [$route, $params] = match ($key) {
            'pending_requests' => ['people.training.requests.register', ['status' => implode(',', self::PENDING_STATUSES)]],
            'approved_not_linked' => ['people.training.requests.register', ['status' => Register::FILTER_APPROVED_UNLINKED]],
            'requests' => ['people.training.requests.register', []],
            'approved' => ['people.training.requests.register', ['status' => TrainingRequestStatus::Approved->value]],
            'attended', 'pending_evaluations', 'evaluation_completion', 'avg_evaluation', 'training_hours', 'certificates' => ['people.training.evaluations.index', []],
            default => ['people.training.effectiveness.summary', []],
        };
        if ($departmentId !== null) {
            $params['department'] = (string) $departmentId;
        }

        return ['route' => $route, 'params' => $params];
    }

    private function rate(int|float $numerator, int|float $denominator): ?float
    {
        return $denominator == 0 ? null : (float) ($numerator / $denominator);
    }

    /**
     * Organisation unit per active employee of the company, from the
     * workforce projection. Keyed by employee entity id.
     *
     * @return array<int, int|null>
     */
    private function unitOfEmployee(int $companyEntityId): array
    {
        $units = [];
        foreach ($this->workforce->employees($companyEntityId) as $employee) {
            if (! ctype_digit($employee->reference->externalId)) {
                continue;
            }
            $unit = $employee->organizationReference?->externalId;
            $units[(int) $employee->reference->externalId] = $unit !== null && ctype_digit($unit) ? (int) $unit : null;
        }

        return $units;
    }
}
