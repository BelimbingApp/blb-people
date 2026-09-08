<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillReassessmentStore;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\AttendanceImportDefect;
use App\Domains\People\Training\Data\AttendanceImportResult;
use App\Domains\People\Training\Data\AttendanceSheet;
use App\Domains\People\Training\Data\AttendanceSheetRow;
use App\Domains\People\Training\Data\LearningTestResult;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;
use App\Domains\People\Training\Models\TrainingCourseSkill;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingEventAuditEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class TrainingParticipationStore
{
    public const MANAGE = 'people.training.participation.manage';

    public const CONFIRM = 'people.training.participation.verify';

    /**
     * Correcting a confirmed fact is a separate authority from recording one:
     * it rewrites what the company says happened, after somebody already
     * signed it off. HR holds it; a trainer with MANAGE does not.
     */
    public const REWORK = 'people.training.participation.rework';

    public const EVIDENCE = 'people.training.participation.evidence.assign';

    /**
     * Facts recorded through a bulk attendance-sheet import carry this
     * source, with `<sha256 of the file>:<session id>:<row number>` as the
     * reference: the triple names the exact cell a fact came from, so a
     * second import of the same file is recognised row by row instead of
     * duplicated. The session id is part of the key because the same sheet
     * may be imported for two sessions of one event — without it the second
     * import would collide on ptf_source_uq instead of recording.
     */
    public const SHEET_SOURCE = 'attendance_sheet';

    /** Audit row written once per confirmed fact that opened reassessment requests (0006-e). */
    public const AUDIT_REASSESSMENT_REQUESTED = 'reassessment_requested';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly ReadsWorkforceDirectory $directory,
        private readonly CompanyAttribution $companies,
        private readonly AuthorizationService $authorization,
        private readonly SkillAudience $audiences,
        private readonly TrainingAudience $calendar,
        private readonly WorkforceSubjects $subjects,
        private readonly SkillReassessmentStore $reassessments,
    ) {}

    public function defineSession(User $actor, int $companyId, int $eventId, string $reference, DateTimeInterface $startsAt, DateTimeInterface $endsAt): TrainingSession
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);
        $event = $this->event($tenant, $companyId, $eventId);
        $this->authorizeEvent($actor, $event, false);
        $start = CarbonImmutable::instance($startsAt);
        $end = CarbonImmutable::instance($endsAt);
        if (! $this->reference($reference) || $start->lessThan($event->starts_at)
            || $end->greaterThan($event->ends_at) || $end->lessThanOrEqualTo($start)) {
            throw new InvalidTrainingParticipationException('A session must have a stable reference and fit within its delivery event.');
        }

        return TrainingSession::query()->create([
            'tenant_id' => $tenant, 'company_entity_id' => $companyId, 'event_id' => $eventId,
            'session_reference' => $reference, 'starts_at' => $start, 'ends_at' => $end,
            'created_by_user_id' => $actor->getKey(),
        ]);
    }

    public function recordAttendance(User $actor, int $companyId, int $sessionId, WorkforceSubject $subject, ParticipationFactDraft $draft): TrainingParticipationFact
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);
        try {
            return DB::transaction(function () use ($actor, $companyId, $sessionId, $subject, $draft, $tenant): TrainingParticipationFact {
                $session = $this->session($tenant, $companyId, $sessionId);
                $event = $this->event($tenant, $companyId, (int) $session->event_id);
                $this->authorizeEvent($actor, $event, false);
                $employee = $this->employee($tenant, $companyId, $subject);
                if ($event->target_department_entity_id !== null
                    && $employee->organizationReference?->externalId !== (string) $event->target_department_entity_id) {
                    $this->deny();
                }
                $payload = $this->payload($actor, $session, $draft);
                $participant = TrainingParticipant::query()->forCompany($tenant, $companyId)->firstOrCreate([
                    'tenant_id' => $tenant, 'company_entity_id' => $companyId, 'event_id' => $event->id,
                    'provider_id' => $employee->reference->providerId,
                    'employee_subject_id' => $employee->reference->externalId,
                ], ['workforce_observed_at' => $employee->observedAt]);

                return TrainingParticipationFact::query()->create($payload + [
                    'tenant_id' => $tenant, 'company_entity_id' => $companyId,
                    'participant_id' => $participant->id, 'session_id' => $session->id, 'event_id' => $event->id,
                ]);
            });
        } catch (QueryException) {
            throw new InvalidTrainingParticipationException('The participation fact conflicts with retained session or source evidence.');
        }
    }

    /**
     * Record one attendance sheet for a session (0011-c): every row is
     * validated before anything is written, and on any defect the per-row
     * defect list is returned with nothing written.
     *
     * Each row travels the recordAttendance path, so every fact keeps
     * recorded_by_user_id and the manage capability, the session rules
     * (ended session, minutes within its length), and the trainer's event
     * assignment. A row whose source reference already exists — a second
     * import of the same file — is reported as skipped, not an error.
     * Capability problems stay denials, not defects: a trainer without the
     * evidence grant cannot import certificate rows, and that fails closed.
     */
    public function importSheet(User $actor, int $companyId, int $sessionId, AttendanceSheet $sheet): AttendanceImportResult
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);
        $session = $this->session($tenant, $companyId, $sessionId);
        $event = $this->event($tenant, $companyId, (int) $session->event_id);
        $this->authorizeEvent($actor, $event, false);

        $sessionMinutes = (int) $session->starts_at->diffInMinutes($session->ends_at);
        $employees = [];
        $ambiguous = [];
        foreach ($this->directory->employees((string) $companyId) as $employee) {
            if (! $employee->active || $employee->companyReference->externalId !== (string) $companyId
                || $employee->employeeNumber === null || $employee->employeeNumber === '') {
                continue;
            }
            if (isset($employees[$employee->employeeNumber])) {
                $ambiguous[$employee->employeeNumber] = true;
            }
            $employees[$employee->employeeNumber] = $employee;
        }
        $subjects = TrainingParticipant::query()->forCompany($tenant, $companyId)
            ->where('event_id', $event->id)->get()
            ->mapWithKeys(fn (TrainingParticipant $participant): array => [(string) $participant->id => $participant->employee_subject_id]);
        $recorded = TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->where('session_id', $session->id)->pluck('participant_id')
            ->map(fn ($participantId): ?string => $subjects->get((string) $participantId))
            ->filter()->values()->all();

        $drafts = [];
        $defects = [];
        $seen = [];
        $preskipped = 0;
        foreach ($sheet->rows as $sheetRow) {
            $sourceReference = $sheet->fileHash.':'.$sessionId.':'.$sheetRow->row;
            if ($this->sheetFactExists($tenant, $companyId, $sourceReference)) {
                $preskipped++;

                continue;
            }
            $draft = $this->sheetDraft($tenant, $companyId, $event, $sessionId, $sheet->fileHash, $sheetRow, $sessionMinutes, $employees, $ambiguous, $recorded, $seen);
            if ($draft instanceof AttendanceImportDefect) {
                $defects[] = $draft;
            } else {
                $drafts[] = $draft;
            }
        }
        if ($defects !== []) {
            return new AttendanceImportResult(0, 0, count($defects), $defects);
        }

        return DB::transaction(function () use ($actor, $companyId, $sessionId, $drafts, $preskipped, $tenant): AttendanceImportResult {
            $created = 0;
            $skipped = $preskipped;
            foreach ($drafts as $draft) {
                if ($this->sheetFactExists($tenant, $companyId, $draft['reference'])) {
                    $skipped++;

                    continue;
                }
                try {
                    $this->recordAttendance($actor, $companyId, $sessionId, $draft['subject'], $draft['draft']);
                } catch (InvalidTrainingParticipationException $refused) {
                    if (! $this->sheetFactExists($tenant, $companyId, $draft['reference'])) {
                        throw $refused;
                    }
                    $skipped++;

                    continue;
                }
                $created++;
            }

            return new AttendanceImportResult($created, $skipped, 0);
        });
    }

    /**
     * @param  array<string, WorkforceEmployee>  $employees  Active directory employees by number.
     * @param  array<string, bool>  $ambiguous  Numbers shared by more than one active employee.
     * @param  list<string>  $recorded  Employee subject ids with a fact for this session already.
     * @param  array<string, int>  $seen  Sheet numbers already validated, with their first row.
     * @return array{reference: string, subject: WorkforceSubject, draft: ParticipationFactDraft}|AttendanceImportDefect
     */
    private function sheetDraft(
        int $tenant,
        int $companyId,
        TrainingEvent $event,
        int $sessionId,
        string $fileHash,
        AttendanceSheetRow $sheetRow,
        int $sessionMinutes,
        array $employees,
        array $ambiguous,
        array $recorded,
        array &$seen,
    ): array|AttendanceImportDefect {
        $number = $sheetRow->employeeNumber;
        $employee = $employees[$number] ?? null;
        if ($employee === null) {
            return new AttendanceImportDefect($sheetRow->row, 'Unknown employee number ['.($number === '' ? 'blank' : $number).'].');
        }
        if (isset($ambiguous[$number])) {
            return new AttendanceImportDefect($sheetRow->row, 'Employee number ['.$number.'] matches more than one employee.');
        }
        if (isset($seen[$number])) {
            return new AttendanceImportDefect($sheetRow->row, 'Duplicate sheet row for employee ['.$number.'] (first at row '.$seen[$number].').');
        }
        $seen[$number] = $sheetRow->row;
        if (in_array($employee->reference->externalId, $recorded, true)) {
            return new AttendanceImportDefect($sheetRow->row, 'Employee ['.$number.'] already has a recorded fact for this session.');
        }
        if ($event->target_department_entity_id !== null
            && $employee->organizationReference?->externalId !== (string) $event->target_department_entity_id) {
            return new AttendanceImportDefect($sheetRow->row, 'Employee ['.$number.'] is outside the event target department.');
        }

        $attendance = AttendanceStatus::tryFrom(strtolower($sheetRow->attendance));
        if ($attendance === null) {
            return new AttendanceImportDefect($sheetRow->row, 'Unknown attendance ['.$sheetRow->attendance.']; use present, absent or cancelled.');
        }
        if ($sheetRow->actualMinutes === '') {
            $minutes = 0;
        } elseif (preg_match('/^\d+$/', $sheetRow->actualMinutes) !== 1) {
            return new AttendanceImportDefect($sheetRow->row, 'Actual minutes ['.$sheetRow->actualMinutes.'] is not a whole number.');
        } else {
            $minutes = (int) $sheetRow->actualMinutes;
        }
        if ($minutes > $sessionMinutes) {
            return new AttendanceImportDefect($sheetRow->row, 'Actual minutes ['.$minutes.'] exceed the session length of '.$sessionMinutes.' minutes.');
        }
        if ($attendance !== AttendanceStatus::Present && $minutes !== 0) {
            return new AttendanceImportDefect($sheetRow->row, 'Only a present attendance records minutes.');
        }

        $preTest = $this->sheetScore($sheetRow->row, 'pre-test', $sheetRow->preTestScore);
        $postTest = $this->sheetScore($sheetRow->row, 'post-test', $sheetRow->postTestScore);
        if ($preTest instanceof AttendanceImportDefect || $postTest instanceof AttendanceImportDefect) {
            return $preTest instanceof AttendanceImportDefect ? $preTest : $postTest;
        }

        $certificateReference = $sheetRow->certificateReference === '' ? null : $sheetRow->certificateReference;
        $certificateValidUntil = null;
        if ($sheetRow->certificateValidUntil !== '') {
            try {
                $certificateValidUntil = CarbonImmutable::parse($sheetRow->certificateValidUntil);
            } catch (\InvalidArgumentException) {
                return new AttendanceImportDefect($sheetRow->row, 'Certificate valid until ['.$sheetRow->certificateValidUntil.'] cannot be read as a date.');
            }
            // whereDate semantics: a value of today with a time component is
            // still today, so only a date before today lies in the past.
            if ($certificateValidUntil->startOfDay()->lessThan(CarbonImmutable::today())) {
                return new AttendanceImportDefect($sheetRow->row, 'Certificate valid until ['.$sheetRow->certificateValidUntil.'] lies in the past.');
            }
        }
        if ($certificateReference === null && $certificateValidUntil !== null) {
            return new AttendanceImportDefect($sheetRow->row, 'Certificate dates need a certificate reference.');
        }

        return [
            'reference' => $fileHash.':'.$sessionId.':'.$sheetRow->row,
            'subject' => new WorkforceSubject($tenant, $companyId, WorkforceResourceType::Employee,
                $employee->reference->externalId, $employee->reference),
            'draft' => new ParticipationFactDraft(
                attendance: $attendance,
                actualMinutes: $minutes,
                source: self::SHEET_SOURCE,
                sourceReference: $fileHash.':'.$sessionId.':'.$sheetRow->row,
                preTest: $preTest,
                postTest: $postTest,
                certificateReference: $certificateReference,
                certificateValidUntil: $certificateValidUntil,
            ),
        ];
    }

    /**
     * A blank score means no test; a present one is a percentage on the
     * 0–100 scale with no declared pass mark, so it carries no verdict.
     */
    private function sheetScore(int $row, string $label, string $raw): LearningTestResult|AttendanceImportDefect|null
    {
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
            return new AttendanceImportDefect($row, 'The '.$label.' score ['.$raw.'] is outside 0 to 100.');
        }

        return new LearningTestResult(true, (float) $raw, 100);
    }

    private function sheetFactExists(int $tenant, int $companyId, string $sourceReference): bool
    {
        return TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->where('source', self::SHEET_SOURCE)->where('source_reference', $sourceReference)->exists();
    }

    public function revise(User $actor, int $companyId, int $factId, ParticipationFactDraft $draft): TrainingParticipationFact
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);

        return DB::transaction(function () use ($actor, $companyId, $factId, $draft, $tenant): TrainingParticipationFact {
            $fact = $this->fact($tenant, $companyId, $factId);
            $session = $this->session($tenant, $companyId, (int) $fact->session_id);
            $this->authorizeEvent($actor, $this->event($tenant, $companyId, (int) $session->event_id), false);
            $this->requireUnconfirmed($fact);
            $this->authorizeEvidence($actor, $fact);
            $fact->update($this->payload($actor, $session, $draft));

            return $fact->refresh();
        });
    }

    public function confirm(User $actor, int $companyId, int $factId): TrainingParticipationFact
    {
        $tenant = $this->scope($actor, $companyId, self::CONFIRM);

        return DB::transaction(function () use ($actor, $companyId, $factId, $tenant): TrainingParticipationFact {
            $fact = $this->fact($tenant, $companyId, $factId);
            $session = $this->session($tenant, $companyId, (int) $fact->session_id);
            $event = $this->event($tenant, $companyId, (int) $session->event_id);
            $this->authorizeEvent($actor, $event, true);
            $this->requireUnconfirmed($fact);
            $this->authorizeEvidence($actor, $fact);
            $fact->update([
                'confirmed_by_user_id' => $actor->getKey(), 'confirmed_capability' => self::CONFIRM,
                'confirmed_at' => now(),
            ]);
            $fact->refresh();
            $this->openReassessments($actor, $tenant, $companyId, $event, $fact);

            return $fact;
        });
    }

    /**
     * A confirmed, attended fact with a passed post-test or a certificate
     * opens one reassessment request per skill the event's course covers
     * (0006-e). The request is the only thing that changes: attendance is
     * never proof of competence, so no score moves here. An open request for
     * the same employee and skill is skipped and counted, and one audit row
     * per fact records what was opened and what was already open.
     */
    private function openReassessments(User $actor, int $tenant, int $companyId, TrainingEvent $event, TrainingParticipationFact $fact): void
    {
        $passed = ($fact->post_test['passed'] ?? null) === true;
        if ($fact->attendance !== AttendanceStatus::Present || (! $passed && $fact->certificate_reference === null)) {
            return;
        }
        // The course is pinned to the company through the event; the mapping
        // inherits that ownership from course_id.
        $skillIds = TrainingCourseSkill::query()->forTenant($tenant)
            ->where('course_id', $event->course_id)->orderBy('skill_id')->pluck('skill_id')->map(intval(...))->all();
        if ($skillIds === []) {
            return;
        }
        $participant = TrainingParticipant::query()->forCompany($tenant, $companyId)->find($fact->participant_id);
        if ($participant === null || $participant->provider_id !== ExternalReference::PROVIDER_ID
            || ! ctype_digit((string) $participant->employee_subject_id)) {
            return;
        }
        $employee = $this->subjects->resolve($tenant, $companyId, WorkforceResourceType::Employee, (int) $participant->employee_subject_id);
        if (! $employee instanceof WorkforceEmployee || $employee->companyReference->externalId !== (string) $companyId) {
            return;
        }
        $employeeEntityId = (int) $employee->reference->externalId;

        $requested = $skipped = [];
        foreach ($skillIds as $skillId) {
            $request = $this->reassessments->requestFromTraining(
                $actor, $companyId, $employeeEntityId, $skillId, (int) $fact->id, $fact->confirmed_at,
            );
            if ($request === null) {
                $skipped[] = $skillId;
            } else {
                $requested[] = $skillId;
            }
        }

        TrainingEventAuditEvent::query()->create([
            'tenant_id' => $tenant, 'company_entity_id' => $companyId,
            'training_event_id' => $event->id, 'event_type' => self::AUDIT_REASSESSMENT_REQUESTED,
            'actor_user_id' => $actor->getKey(), 'actor_employee_entity_id' => $employeeEntityId,
            'metadata' => [
                'participation_fact_id' => (int) $fact->id, 'employee_entity_id' => $employeeEntityId,
                'requested_skill_ids' => $requested, 'skipped_open_skill_ids' => $skipped,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Enrol the acting user into a scheduled event as their own bound
     * employee. The event must be visible on the actor's calendar — the
     * same seam the page reads — so the calendar never offers an event
     * this store would refuse.
     */
    /**
     * Enrol every subject of an approved request onto the event it was linked
     * to, or none of them.
     *
     * Called by TrainingRequestStore::linkEvent(), which has already decided
     * the request may be linked; this owns the participation rules. Capacity
     * is checked once for the whole cohort: half a department enrolled and the
     * rest silently dropped is worse than a refused link somebody can act on.
     *
     * Idempotent per event and subject, so linking, unlinking and linking
     * again does not duplicate anybody.
     *
     * @param  list<array{provider_id: string, employee_subject_id: string}>  $subjects
     * @return list<TrainingParticipant>
     */
    public function enrolFromRequest(User $actor, int $companyId, int $eventId, array $subjects): array
    {
        $tenant = $this->scope($actor, $companyId, self::MANAGE);

        return DB::transaction(function () use ($companyId, $eventId, $subjects, $tenant): array {
            $event = TrainingEvent::query()->forCompany($tenant, $companyId)->lockForUpdate()->find($eventId)
                ?? throw new InvalidTrainingParticipationException('Training event was not found in this company.');

            $existing = TrainingParticipant::query()->forCompany($tenant, $companyId)
                ->where('event_id', $event->id)->get();
            $alreadyOn = $existing->filter(fn (TrainingParticipant $p): bool => $p->withdrawn_at === null)
                ->map(fn (TrainingParticipant $p): string => $p->provider_id.':'.$p->employee_subject_id)
                ->all();

            $wanted = [];
            foreach ($subjects as $subject) {
                $key = $subject['provider_id'].':'.$subject['employee_subject_id'];
                if (! in_array($key, $alreadyOn, true)) {
                    $wanted[$key] = $subject;
                }
            }

            $seats = (int) $event->capacity - count($alreadyOn);
            if (count($wanted) > $seats) {
                throw new InvalidTrainingParticipationException('The training event does not have room for everybody the request names.');
            }

            $enrolled = [];
            foreach ($wanted as $subject) {
                $enrolled[] = TrainingParticipant::query()->forCompany($tenant, $companyId)->updateOrCreate(
                    [
                        'tenant_id' => $tenant, 'company_entity_id' => $companyId, 'event_id' => $event->id,
                        'provider_id' => $subject['provider_id'],
                        'employee_subject_id' => $subject['employee_subject_id'],
                    ],
                    ['withdrawn_at' => null, 'workforce_observed_at' => now()],
                );
            }

            return $enrolled;
        });
    }

    public function enrolSelf(User $actor, int $companyId, int $eventId): TrainingParticipant
    {
        $tenant = $this->scope($actor, $companyId, TrainingAudience::CALENDAR_VIEW);

        return DB::transaction(function () use ($actor, $companyId, $eventId, $tenant): TrainingParticipant {
            $event = TrainingEvent::query()->forCompany($tenant, $companyId)->lockForUpdate()->find($eventId);
            if ($event === null || $event->status !== TrainingEventStatus::Scheduled) {
                throw new InvalidTrainingParticipationException('Enrolment is only available for scheduled training events.');
            }
            $visible = $this->calendar->visibleCalendarEvents($actor, $companyId)->pluck('id')->map(intval(...))->all();
            if (! in_array($eventId, $visible, true)) {
                $this->deny();
            }
            $bound = $this->audiences->boundEmployeeEntityId($actor, $companyId);
            if ($bound === null) {
                $this->deny();
            }
            $enrolled = TrainingParticipant::query()->forCompany($tenant, $companyId)
                ->where('event_id', $event->id)->whereNull('withdrawn_at')->count();
            if ($enrolled >= (int) $event->capacity) {
                throw new InvalidTrainingParticipationException('The training event is full.');
            }
            $existing = TrainingParticipant::query()->forCompany($tenant, $companyId)
                ->where('event_id', $event->id)
                ->where('provider_id', ExternalReference::PROVIDER_ID)
                ->where('employee_subject_id', (string) $bound)
                ->first();
            if ($existing !== null && $existing->withdrawn_at === null) {
                throw new InvalidTrainingParticipationException('The employee is already enrolled in the training event.');
            }

            try {
                if ($existing !== null) {
                    // Re-enrolment clears the withdrawal marker. The guards
                    // permit exactly this mutation; every other column stays
                    // identical, so history is preserved, not rewritten.
                    $existing->update(['withdrawn_at' => null, 'withdrawn_by_user_id' => null]);

                    return $existing->refresh();
                }

                return TrainingParticipant::query()->create([
                    'tenant_id' => $tenant, 'company_entity_id' => $companyId, 'event_id' => $event->id,
                    'provider_id' => ExternalReference::PROVIDER_ID,
                    'employee_subject_id' => (string) $bound,
                    'workforce_observed_at' => now(),
                ]);
            } catch (QueryException) {
                throw new InvalidTrainingParticipationException('The employee is already enrolled in the training event.');
            }
        });
    }

    /**
     * Withdraw the acting user's own enrolment by marking the participant
     * row. Participant rows are trigger-immutable and are never deleted:
     * the guards permit exactly this marker mutation. Recorded attendance
     * is a permanent fact: once any participation fact names the
     * participant, withdrawal is refused instead of rewriting history.
     */
    public function withdrawSelf(User $actor, int $companyId, int $eventId): void
    {
        $tenant = $this->scope($actor, $companyId, TrainingAudience::CALENDAR_VIEW);

        DB::transaction(function () use ($actor, $companyId, $eventId, $tenant): void {
            $bound = $this->audiences->boundEmployeeEntityId($actor, $companyId);
            if ($bound === null) {
                $this->deny();
            }
            $participant = TrainingParticipant::query()->forCompany($tenant, $companyId)
                ->where('event_id', $eventId)
                ->where('provider_id', ExternalReference::PROVIDER_ID)
                ->where('employee_subject_id', (string) $bound)
                ->whereNull('withdrawn_at')
                ->lockForUpdate()
                ->first();
            if ($participant === null) {
                $this->deny();
            }
            $hasFacts = TrainingParticipationFact::query()->forCompany($tenant, $companyId)
                ->where('participant_id', $participant->id)->exists();
            if ($hasFacts) {
                throw new InvalidTrainingParticipationException('Recorded attendance cannot be withdrawn; ask HR to correct the fact.');
            }

            $participant->update(['withdrawn_at' => now(), 'withdrawn_by_user_id' => $actor->getKey()]);
        });
    }

    private function scope(User $actor, int $companyId, string $capability): int
    {
        $tenant = $this->tenancy->currentTenantId();
        $currentActor = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($tenant === null || $currentActor === null || $currentActor->getCompanyId() !== $actor->getCompanyId()
            || (int) $currentActor->tenant_id !== $tenant
            || ! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $this->authorization->authorize(Actor::forUser($actor), $capability);

        return $tenant;
    }

    private function authorizeEvent(User $actor, TrainingEvent $event, bool $confirm): void
    {
        $capability = $confirm ? self::CONFIRM : self::MANAGE;
        try {
            if (in_array(SkillAudience::HR, $this->audiences->authorizeAudience($actor, $capability), true)) {
                return;
            }
        } catch (AuthorizationDeniedException) {
            // A scoped trainer need not hold an HR, HOD, assessor or self audience.
        }
        $employee = $this->directory->employeeForUser((string) $event->company_entity_id, (int) $actor->getKey());
        if ($confirm || $employee === null || ! $employee->active || $employee->userReferenceRevoked
            || $employee->companyReference->externalId !== (string) $event->company_entity_id
            || $employee->userReference?->providerId !== $employee->reference->providerId
            || $employee->userReference?->externalId !== (string) $actor->getKey()
            || ! in_array($employee->reference->externalId, [
                (string) $event->organizer_employee_entity_id, (string) $event->internal_trainer_employee_entity_id,
            ], true)) {
            $this->deny();
        }
    }

    private function employee(int $tenant, int $companyId, WorkforceSubject $subject): WorkforceEmployee
    {
        if ($subject->tenantId !== $tenant || $subject->companyId !== $companyId
            || $subject->type !== WorkforceResourceType::Employee || $subject->externalReference === null
            || $subject->externalReference->externalId !== $subject->stableId
            || strlen($subject->stableId) > 160 || strlen($subject->externalReference->providerId) > 80) {
            $this->deny();
        }
        foreach ($this->directory->employees((string) $companyId) as $employee) {
            if ($employee->active && $employee->reference == $subject->externalReference
                && $employee->companyReference->externalId === (string) $companyId) {
                return $employee;
            }
        }
        $this->deny();
    }

    private function payload(User $actor, TrainingSession $session, ParticipationFactDraft $draft): array
    {
        if ($session->ends_at->isFuture() || $draft->actualMinutes < 0
            || $draft->actualMinutes > (int) $session->starts_at->diffInMinutes($session->ends_at)
            || ($draft->attendance !== AttendanceStatus::Present && $draft->actualMinutes !== 0)
            || ! $this->reference($draft->source, 80) || ! $this->reference($draft->sourceReference)
            || ($draft->certificateReference !== null && ! $this->reference($draft->certificateReference))
            || ($draft->certificateReference === null && ($draft->certificateValidFrom !== null || $draft->certificateValidUntil !== null))
            || ($draft->certificateValidFrom !== null && $draft->certificateValidUntil !== null
                && CarbonImmutable::instance($draft->certificateValidUntil)->lessThan(CarbonImmutable::instance($draft->certificateValidFrom)))
            || ! array_is_list($draft->evidenceReferences)
            || count($draft->evidenceReferences) > 100) {
            throw new InvalidTrainingParticipationException('Participation facts do not fit the session or evidence contract.');
        }
        foreach ($draft->evidenceReferences as $reference) {
            if (! is_string($reference) || ! $this->reference($reference)) {
                throw new InvalidTrainingParticipationException('Evidence must use opaque governed-document references.');
            }
        }
        if ($draft->evidenceReferences !== [] || $draft->certificateReference !== null) {
            $this->authorization->authorize(Actor::forUser($actor), self::EVIDENCE);
        }

        return [
            'attendance' => $draft->attendance, 'actual_minutes' => $draft->actualMinutes,
            'pre_test' => $draft->preTest?->toArray(), 'post_test' => $draft->postTest?->toArray(),
            'certificate_reference' => $draft->certificateReference,
            'certificate_valid_from' => $draft->certificateValidFrom, 'certificate_valid_until' => $draft->certificateValidUntil,
            'evidence_references' => $draft->evidenceReferences, 'source' => $draft->source,
            'source_reference' => $draft->sourceReference, 'recorded_by_user_id' => $actor->getKey(),
            'recorded_capability' => self::MANAGE, 'recorded_at' => now(),
        ];
    }

    private function event(int $tenant, int $companyId, int $id): TrainingEvent
    {
        $event = TrainingEvent::query()->forCompany($tenant, $companyId)->find($id);
        if ($event === null || $event->status === TrainingEventStatus::Cancelled) {
            $this->deny();
        }

        return $event;
    }

    private function session(int $tenant, int $companyId, int $id): TrainingSession
    {
        return TrainingSession::query()->forCompany($tenant, $companyId)->find($id) ?? $this->deny();
    }

    private function fact(int $tenant, int $companyId, int $id): TrainingParticipationFact
    {
        return TrainingParticipationFact::query()->forCompany($tenant, $companyId)->lockForUpdate()->find($id) ?? $this->deny();
    }

    /**
     * Append a superseding fact, never an update.
     *
     * A confirmed fact is immutable at the database level, which is the point:
     * what the company said happened is a record, not a draft. A correction is
     * therefore a new row that names the one it replaces and carries the
     * reason, so the original and the correction are both readable afterwards.
     *
     * The correction is confirmed by construction — HR is the confirming
     * authority here, and an unconfirmed correction would leave the session
     * with neither a current answer nor a pending one.
     */
    public function correct(User $actor, int $companyId, int $factId, ParticipationFactDraft $draft, string $reason): TrainingParticipationFact
    {
        $tenant = $this->scope($actor, $companyId, self::REWORK);

        if (trim($reason) === '') {
            throw new InvalidTrainingParticipationException('A correction records why the confirmed fact was wrong.');
        }

        return DB::transaction(function () use ($actor, $companyId, $factId, $draft, $reason, $tenant): TrainingParticipationFact {
            $fact = $this->fact($tenant, $companyId, $factId);
            $session = $this->session($tenant, $companyId, (int) $fact->session_id);
            $this->authorizeEvent($actor, $this->event($tenant, $companyId, (int) $session->event_id), true);

            if ($fact->confirmed_at === null) {
                throw new InvalidTrainingParticipationException('Only a confirmed participation fact is corrected; revise the draft instead.');
            }
            if (TrainingParticipationFact::query()->forCompany($tenant, $companyId)
                ->where('supersedes_fact_id', (int) $fact->id)->exists()) {
                throw new InvalidTrainingParticipationException('This fact has already been corrected; correct the correction.');
            }

            $correction = TrainingParticipationFact::query()->forCompany($tenant, $companyId)->create([
                ...$this->payload($actor, $session, $draft),
                'tenant_id' => $tenant,
                'company_entity_id' => $companyId,
                'event_id' => (int) $fact->event_id,
                'participant_id' => (int) $fact->participant_id,
                'session_id' => (int) $fact->session_id,
                'supersedes_fact_id' => (int) $fact->id,
                'correction_reason' => trim($reason),
                'confirmed_by_user_id' => $actor->getKey(),
                'confirmed_capability' => self::REWORK,
                'confirmed_at' => now(),
            ]);

            return $correction->refresh();
        });
    }

    private function requireUnconfirmed(TrainingParticipationFact $fact): void
    {
        if ($fact->confirmed_at !== null) {
            throw new InvalidTrainingParticipationException('Confirmed participation requires a separate traceable correction.');
        }
    }

    private function authorizeEvidence(User $actor, TrainingParticipationFact $fact): void
    {
        if ($fact->evidence_references !== [] || $fact->certificate_reference !== null) {
            $this->authorization->authorize(Actor::forUser($actor), self::EVIDENCE);
        }
    }

    private function reference(string $value, int $maximum = 160): bool
    {
        return strlen($value) <= $maximum && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]*$/D', $value) === 1;
    }

    private function deny(): never
    {
        throw new InvalidTrainingParticipationException('The participation operation is unavailable in the current scope.');
    }
}
