<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\ReassessmentRequestStatus;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Livewire\MyHistory\Index as MySkillHistory;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillReassessmentRequest;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Services\SkillReassessmentStore;
use App\Domains\People\Skills\Tests\Support\CompanyIsolationFixture;
use App\Domains\People\Training\Data\LearningTestResult;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\HrGovernance\Index as HrGovernance;
use App\Domains\People\Training\Models\TrainingEventAuditEvent;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * 0006-e: a confirmed, attended participation fact with a passed post-test
 * or a certificate opens one reassessment request per skill the course
 * covers, sourced from the fact. The score never moves here.
 *
 * Self-contained: helpers are prefixed trq and live here.
 */
afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

function trqFixture(?int $tenantId = null, ?Company $company = null, array $skillCodes = ['a', 'b']): array
{
    if ($tenantId === null || $company === null) {
        [$tenant, $company] = createTenantWithCompany(['name' => 'Training Reassessment Tenant'], ['name' => 'Training Reassessment Co', 'status' => 'active']);
        $tenantId = (int) $tenant->id;
    }
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'TRQ-OPS-'.$companyId, 'name' => 'Training reassessment operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->firstOrCreate(['code' => 'trq-ops'], [
        'name' => 'Training reassessment operations', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create(['company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active']);
    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => 'Training Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $unit->id]);
    $report = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id, 'supervisor_id' => $head->id,
        'full_name' => 'Training Report', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $report->id, 'organization_unit_id' => $unit->id]);

    $hr = User::factory()->create(['company_id' => $companyId, 'name' => 'Training HR']);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id, 'name' => 'Training HOD']);
    $self = User::factory()->create(['company_id' => $companyId, 'employee_id' => $report->id, 'name' => 'Training Self']);
    foreach ([[$head, $hod, 'Training Head'], [$report, $self, 'Training Report']] as [$employee, $user, $name]) {
        EmployeePortalAccess::query()->create([
            'employee_id' => $employee->id, 'user_id' => $user->id, 'display_name' => $name,
            'status' => EmployeePortalAccess::STATUS_ACTIVE,
        ]);
    }
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod'], [$self, 'people_employee']] as [$actor, $code]) {
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $actor->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, $companyId, (int) $head->id, 'review:trq-hod');
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $self, $companyId, (int) $report->id, 'review:trq-self');

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'trq-safety-'.$companyId, 'Training reassessment safety');
    $skillIds = [];
    foreach ($skillCodes as $code) {
        $skillIds[] = (int) $catalog->defineSkill($companyId, new SkillDraft(
            code: 'trq.'.$code, name: 'TRQ skill '.$code, definition: 'Covered by the course.',
            categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        ))->id;
    }
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'trq-course-'.$companyId, title: 'Isolation refresher', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: $skillIds, internalTrainerEmployeeEntityId: (int) $head->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $head->id,
    ));
    $subject = new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee,
        (string) $report->id, new ExternalReference(WorkforceResourceType::Employee, (string) $report->id));

    return compact('tenantId', 'companyId', 'hr', 'hod', 'self', 'head', 'report', 'skillIds', 'course', 'event', 'subject');
}

function trqDraft(array $overrides = []): ParticipationFactDraft
{
    return new ParticipationFactDraft(...array_replace([
        'attendance' => AttendanceStatus::Present, 'actualMinutes' => 90,
        'source' => 'manual', 'sourceReference' => (string) Str::uuid(),
        'preTest' => null, 'postTest' => new LearningTestResult(true, 85, 100, 70),
        'certificateReference' => 'certificate:trq-1',
        'certificateValidFrom' => new DateTimeImmutable('2026-09-02'),
        'certificateValidUntil' => new DateTimeImmutable('2027-09-02'),
        'evidenceReferences' => [],
    ], $overrides));
}

/** Record one fact after the event has ended and confirm it as HR. */
function trqConfirm(array $f, ParticipationFactDraft $draft): TrainingParticipationFact
{
    $store = app(TrainingParticipationStore::class);
    $session = $store->defineSession($f['hr'], $f['companyId'], (int) $f['event']->id, 'trq-session',
        $f['event']->starts_at, $f['event']->starts_at->addHours(2));
    test()->travelTo($f['event']->ends_at->addHour());
    $fact = $store->recordAttendance($f['hr'], $f['companyId'], (int) $session->id, $f['subject'], $draft);

    return $store->confirm($f['hr'], $f['companyId'], (int) $fact->id);
}

function trqRequests(array $f): Collection
{
    return SkillReassessmentRequest::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('employee_entity_id', $f['report']->id)->orderBy('skill_id')->get();
}

function trqAudits(array $f): Collection
{
    return TrainingEventAuditEvent::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('event_type', TrainingParticipationStore::AUDIT_REASSESSMENT_REQUESTED)->get();
}

final class TrqRequirements implements ResolvesSkillRequirements
{
    /** @param list<ResolvedSkillRequirement> $rows */
    public function __construct(private array $rows) {}

    public function requirementsFor(array $employeeData, ?DateTimeInterface $asOf = null): array
    {
        return $this->rows;
    }
}

/** A released level 2 score on the first covered skill, through the governed lifecycle. */
function trqScore(array $f): EmployeeSkillScore
{
    $skillId = $f['skillIds'][0];
    app(SkillCatalogDefaults::class)->install($f['companyId']);
    $profiles = app(RequirementProfileStore::class);
    $profile = $profiles->publish($f['companyId'], (int) $profiles->draft($f['companyId'], new RequirementProfileDraft(
        code: 'fixture.trq-ops', name: 'TRQ operations',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [new RequirementItemDraft(skillId: $skillId, sequence: 1, requiredLevel: 4,
            criticality: RequirementCriticality::Critical, weightPercent: 100.0)],
    ))->id);
    app()->instance(ResolvesSkillRequirements::class, new TrqRequirements([new ResolvedSkillRequirement(
        requirementReference: 'fixture.trq', requirementVersion: 1, requirementProfileId: (int) $profile->id,
        skillId: $skillId, requiredLevel: 4, criticality: RequirementCriticality::Critical, mandatoryGate: true,
    )]));
    $store = app(AssessmentStore::class);
    $submitted = $store->submit($f['hr'], $f['companyId'], new AssessmentDraft(
        employeeEntityId: (int) $f['report']->id, skillId: $skillId, assessedLevel: 2,
        method: AssessmentMethod::DirectObservation, cycle: AssessmentCycle::Annual,
        assessedAt: now()->subDays(3), evidence: 'Observed task.',
    ));
    $pending = $store->requestHodVerification($f['hr'], $f['companyId'], (int) $submitted->id);
    $store->verifyHod($f['hod'], $f['companyId'], (int) $pending->id, 'Baseline verified.');
    $store->finalizeVerified($f['hod'], $f['companyId'], (int) $pending->id);

    return EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])
        ->where('employee_entity_id', $f['report']->id)->where('skill_id', $skillId)->firstOrFail();
}

test('a confirmed attended certificate opens one training-sourced request per covered skill and leaves the score untouched', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-01T12:00:00Z'));
    $f = trqFixture();
    trqScore($f);
    $before = DB::table('people_connector_skill_employee_scores')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

    $fact = trqConfirm($f, trqDraft());

    $requests = trqRequests($f);
    expect($requests->count())->toBe(2)
        ->and($requests->pluck('skill_id')->map(intval(...))->all())->toBe($f['skillIds'])
        ->and($requests->pluck('source')->unique()->all())->toBe([SkillReassessmentRequest::SOURCE_TRAINING])
        ->and($requests->pluck('source_participation_fact_id')->map(intval(...))->unique()->all())->toBe([(int) $fact->id])
        ->and($requests->pluck('status')->unique()->all())->toBe([ReassessmentRequestStatus::Pending])
        ->and(DB::table('people_connector_skill_employee_scores')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($before)
        ->and($before)->not->toBe([]);

    $audits = trqAudits($f);
    expect($audits->count())->toBe(1)
        ->and($audits->first()->metadata['participation_fact_id'])->toBe((int) $fact->id)
        ->and($audits->first()->metadata['requested_skill_ids'])->toBe($f['skillIds'])
        ->and($audits->first()->metadata['skipped_open_skill_ids'])->toBe([]);
});

test('a passed post-test without a certificate also opens the requests', function (): void {
    $f = trqFixture();
    trqConfirm($f, trqDraft(['certificateReference' => null, 'certificateValidFrom' => null, 'certificateValidUntil' => null]));
    expect(trqRequests($f)->count())->toBe(2);
});

test('absence, cancellation and attendance without a pass or certificate open nothing', function (array $overrides): void {
    $f = trqFixture();
    // Absent and cancelled rows keep the pass and the certificate so only the
    // attendance gate can stop them; the attended rows drop both.
    trqConfirm($f, trqDraft($overrides));
    expect(trqRequests($f)->count())->toBe(0)->and(trqAudits($f)->count())->toBe(0);
})->with([
    'absent' => [['attendance' => AttendanceStatus::Absent, 'actualMinutes' => 0]],
    'cancelled' => [['attendance' => AttendanceStatus::Cancelled, 'actualMinutes' => 0]],
    'attended, failed post-test' => [['postTest' => new LearningTestResult(true, 40, 100, 70), 'certificateReference' => null, 'certificateValidFrom' => null, 'certificateValidUntil' => null]],
    'attended, no post-test' => [['postTest' => new LearningTestResult(false), 'certificateReference' => null, 'certificateValidFrom' => null, 'certificateValidUntil' => null]],
]);

test('a course covering no skills opens nothing', function (): void {
    $f = trqFixture(skillCodes: ['a']);
    // The catalog refuses an empty mapping at definition; a course whose
    // mapping was later emptied is the shape the store must tolerate.
    DB::table('people_connector_training_course_skills')->where('course_id', $f['course']->id)->delete();
    trqConfirm($f, trqDraft());
    expect(trqRequests($f)->count())->toBe(0)->and(trqAudits($f)->count())->toBe(0);
});

test('an existing open request for the same employee and skill is skipped and counted, not duplicated', function (): void {
    $f = trqFixture();
    $existing = app(SkillReassessmentStore::class)->request($f['hod'], $f['companyId'], (int) $f['report']->id, $f['skillIds'][0], 'HOD asked first.');

    trqConfirm($f, trqDraft());

    $requests = trqRequests($f);
    expect($requests->count())->toBe(2)
        ->and($requests->where('skill_id', $f['skillIds'][0])->count())->toBe(1)
        ->and((int) $requests->firstWhere('skill_id', $f['skillIds'][0])->id)->toBe((int) $existing->id)
        ->and($requests->firstWhere('skill_id', $f['skillIds'][0])->source)->toBe(SkillReassessmentRequest::SOURCE_HOD)
        ->and($requests->firstWhere('skill_id', $f['skillIds'][1])->source)->toBe(SkillReassessmentRequest::SOURCE_TRAINING)
        ->and(trqAudits($f)->first()->metadata['skipped_open_skill_ids'])->toBe([$f['skillIds'][0]])
        ->and(trqAudits($f)->first()->metadata['requested_skill_ids'])->toBe([$f['skillIds'][1]]);
});

test('the due date is the confirmation date plus the configured days, thirty by default', function (?int $configured, string $due): void {
    $this->travelTo(new DateTimeImmutable('2026-09-01T12:00:00Z'));
    $f = trqFixture();
    if ($configured !== null) {
        app(SettingsService::class)->set(SkillReassessmentStore::DUE_AFTER_TRAINING_SETTING, $configured, Scope::tenant($f['tenantId']));
    }

    $fact = trqConfirm($f, trqDraft());

    expect($fact->confirmed_at->toDateString())->toBe('2026-09-02')
        ->and(SkillReassessmentRequest::query()->forCompany($f['tenantId'], $f['companyId'])->whereDate('due_at', $due)->count())->toBe(2)
        ->and(SkillReassessmentRequest::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(2);
})->with([
    'default 30' => [null, '2026-10-02'],
    'setting 45' => [45, '2026-10-17'],
]);

test('a sibling company in the same tenant receives nothing and another tenant rows are never loaded', function (): void {
    $two = CompanyIsolationFixture::twoCompaniesInOneTenant();
    $f = trqFixture($two->tenantId, $two->alphaCompany);
    $betaEmployee = Employee::factory()->create(['company_id' => $two->betaCompanyEntityId, 'status' => 'active']);

    // An open request in another tenant for the same employee and skill ids
    // must not block ours: the one-open-request rule reads this tenant only.
    [$otherTenant, $otherCompany] = createTenantWithCompany(['name' => 'Other Tenant'], ['name' => 'Other Co', 'status' => 'active']);
    SkillReassessmentRequest::query()->create([
        'tenant_id' => (int) $otherTenant->id, 'company_entity_id' => (int) $otherCompany->id,
        'employee_entity_id' => (int) $f['report']->id, 'skill_id' => $f['skillIds'][0],
        'reason' => 'Other tenant request.', 'requested_by_user_id' => (int) $f['hr']->id,
        'due_at' => today()->toDateString(), 'status' => ReassessmentRequestStatus::Pending->value,
    ]);

    trqConfirm($f, trqDraft());

    expect(trqRequests($f)->count())->toBe(2)
        ->and(SkillReassessmentRequest::query()->forCompany($two->tenantId, $two->betaCompanyEntityId)->count())->toBe(0)
        ->and(DB::table('people_connector_skill_reassessment_requests')->where('employee_entity_id', $betaEmployee->id)->count())->toBe(0)
        ->and(DB::table('people_connector_skill_reassessment_requests')->where('tenant_id', $otherTenant->id)->count())->toBe(1);
});

test('performing the request closes it with the source kept, and the HR queue and history page show the source', function (): void {
    $this->withoutVite();
    $this->travelTo(new DateTimeImmutable('2026-09-01T12:00:00Z'));
    $f = trqFixture();
    trqScore($f);
    $fact = trqConfirm($f, trqDraft());
    $request = trqRequests($f)->firstWhere('skill_id', $f['skillIds'][0]);

    Livewire::actingAs($f['hr'])->test(HrGovernance::class)
        ->assertSee('From training Isolation refresher')
        ->assertSee('Confirmed training with a pass or certificate.');
    Livewire::actingAs($f['self'])->test(MySkillHistory::class)
        ->assertSee('Reassessment requests opened for you')
        ->assertSee('From training Isolation refresher')
        ->assertSee('TRQ skill a')
        ->assertSee('Pending');

    $performed = app(SkillReassessmentStore::class)->perform(
        $f['hr'], $f['companyId'], (int) $request->id, 3, now()->toDateString(), 'Observed after the refresher.',
    );

    expect($performed->status)->toBe(ReassessmentRequestStatus::Resolved)
        ->and($performed->source)->toBe(SkillReassessmentRequest::SOURCE_TRAINING)
        ->and((int) $performed->source_participation_fact_id)->toBe((int) $fact->id)
        ->and((int) EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])
            ->where('employee_entity_id', $f['report']->id)->where('skill_id', $f['skillIds'][0])->value('current_level'))->toBe(3);

    Livewire::actingAs($f['self'])->test(MySkillHistory::class)->assertSee('Resolved');
});
