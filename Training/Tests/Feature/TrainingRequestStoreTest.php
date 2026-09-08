<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Policies\GrantPolicy;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Data\TrainingRequestSubjectsDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestSubject;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

afterEach(fn () => app(TenantContext::class)->clear());

function requestFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $department = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::OrganizationUnit, (int) $company->id);
    setupAuthzRoles();
    $actors = [];
    foreach (['hr' => 'people_hr', 'hod' => 'people_hod', 'approver' => 'people_training_approver'] as $key => $roleCode) {
        $actors[$key] = User::factory()->create(['company_id' => $company->id]);
        PrincipalRole::query()->create([
            'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actors[$key]->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
        ]);
    }
    $subject = fn ($record, WorkforceResourceType $type) => new WorkforceSubject(
        (int) $tenant->id, (int) $company->id, $type, (string) $record->id,
    );

    return compact('tenant', 'company', 'employee', 'department', 'actors', 'subject');
}

function requestDraft(array $f, array $overrides = []): TrainingRequestDraft
{
    return new TrainingRequestDraft(...array_replace([
        'requestor' => $f['subject']($f['employee'], WorkforceResourceType::Employee),
        'department' => $f['subject']($f['department'], WorkforceResourceType::OrganizationUnit),
        'needSource' => TrainingNeedSource::NewMachineTechnology,
        'need' => 'Operators need safe control-system operation.',
        'learningObjective' => 'Operate the new control system safely.',
        'expectedResult' => 'Zero unsafe startup deviations.',
        'priority' => TrainingPriority::High,
        'skillGapAssessmentId' => null,
        'requirementVersion' => null,
    ], $overrides));
}

function forgetRequestAuthorization(): void
{
    foreach ([GrantPolicy::class, AuthorizationEngine::class, AuthorizationService::class,
        CompanyAttribution::class, TrainingRequestStore::class] as $binding) {
        app()->forgetInstance($binding);
    }
}

test('a request preserves its need and every recommendation through approval', function (): void {
    $f = requestFixture();
    $store = app(TrainingRequestStore::class);
    $request = $store->create($f['actors']['hr'], (int) $f['company']->id, requestDraft($f, [
        'estimatedCost' => '1250.5000',
        'proposedDeliveryMethod' => 'Instructor-led workshop',
        'proposedProvider' => 'Belimbing Safety Academy',
        'proposedStartDate' => '2026-10-12',
        'proposedEndDate' => '2026-10-14',
    ]));
    $store->submit($f['actors']['hr'], (int) $f['company']->id, (int) $request->id);
    $store->recommend($f['actors']['hod'], (int) $f['company']->id, (int) $request->id, 'Technically relevant.');
    $store->review($f['actors']['hr'], (int) $f['company']->id, (int) $request->id, 'Policy checked.');
    $approved = $store->approve($f['actors']['approver'], (int) $f['company']->id, (int) $request->id, 'Approved.');

    expect($approved->status)->toBe(TrainingRequestStatus::Approved)
        ->and($approved->need_source)->toBe(TrainingNeedSource::NewMachineTechnology)
        ->and($approved->priority)->toBe(TrainingPriority::High)
        ->and($approved->proposed_delivery_method)->toBe('Instructor-led workshop')
        ->and($approved->proposed_provider)->toBe('Belimbing Safety Academy')
        ->and($approved->proposed_start_date->toDateString())->toBe('2026-10-12')
        ->and($approved->proposed_end_date->toDateString())->toBe('2026-10-14')
        ->and($approved->approved_budget)->toBe('1250.5000')
        ->and($approved->decisions()->pluck('decision')->all())
        ->toBe(['created', 'submitted', 'hod_recommended', 'hr_reviewed', 'approved']);
    expect(fn () => $approved->update(['need' => 'Rewrite approved history.']))
        ->toThrow(InvalidTrainingRequestException::class, 'immutable');
    expect(fn () => $approved->update(['approved_budget' => '1.0000']))
        ->toThrow(InvalidTrainingRequestException::class, 'immutable');
    $decision = $approved->decisions()->firstOrFail();
    expect(fn () => $decision->update(['notes' => 'Rewrite decision.']))
        ->toThrow(InvalidTrainingRequestException::class, 'append-only');
    expect(fn () => DB::table('people_training_request_decisions')->where('id', $decision->id)->delete())
        ->toThrow(QueryException::class);
});

test('a proposed training window is either complete and ordered or absent', function (): void {
    $f = requestFixture();
    $store = app(TrainingRequestStore::class);

    expect(fn () => $store->create($f['actors']['hr'], (int) $f['company']->id, requestDraft($f, [
        'proposedStartDate' => '2026-10-12',
    ])))->toThrow(InvalidTrainingRequestException::class, 'both a start and an end')
        ->and(fn () => $store->create($f['actors']['hr'], (int) $f['company']->id, requestDraft($f, [
            'proposedStartDate' => '2026-10-14',
            'proposedEndDate' => '2026-10-12',
        ])))->toThrow(InvalidTrainingRequestException::class, 'cannot precede');
});

test('a skill-gap source requires its pinned requirement version', function (): void {
    $f = requestFixture();

    expect(fn () => app(TrainingRequestStore::class)->create(
        $f['actors']['hr'], (int) $f['company']->id,
        requestDraft($f, ['needSource' => TrainingNeedSource::SkillGap, 'skillGapAssessmentId' => 42]),
    ))->toThrow(InvalidTrainingRequestException::class, 'requirement version');
    expect(fn () => app(TrainingRequestStore::class)->create(
        $f['actors']['hr'], (int) $f['company']->id,
        requestDraft($f, ['needSource' => TrainingNeedSource::SkillGap,
            'skillGapAssessmentId' => 42, 'requirementVersion' => 3]),
    ))->toThrow(InvalidTrainingRequestException::class, 'exact finalized skill gap');
});

test('the lifecycle refuses skipped and terminal transitions', function (): void {
    $f = requestFixture();
    $store = app(TrainingRequestStore::class);
    $request = $store->create($f['actors']['hr'], (int) $f['company']->id, requestDraft($f));

    foreach ([
        fn () => $store->recommend($f['actors']['hod'], (int) $f['company']->id, (int) $request->id),
        fn () => $store->review($f['actors']['hr'], (int) $f['company']->id, (int) $request->id),
        fn () => $store->approve($f['actors']['approver'], (int) $f['company']->id, (int) $request->id),
        fn () => $store->reject($f['actors']['hod'], (int) $f['company']->id, (int) $request->id, 'No.'),
    ] as $illegal) {
        expect($illegal)->toThrow(InvalidTrainingRequestException::class);
    }
    $store->cancel($f['actors']['hr'], (int) $f['company']->id, (int) $request->id, 'Withdrawn.');
    foreach (['submit', 'cancel'] as $method) {
        expect(fn () => $store->{$method}($f['actors']['hr'], (int) $f['company']->id, (int) $request->id, ...($method === 'cancel' ? ['Again.'] : [])))
            ->toThrow(InvalidTrainingRequestException::class);
    }
});

test('each transition requires its own capability', function (string $method, string $role, string $status): void {
    $f = requestFixture();
    $request = app(TrainingRequestStore::class)->create($f['actors']['hr'], (int) $f['company']->id, requestDraft($f));
    $request->update(['status' => $status]);
    $capability = match ($method) {
        'submit', 'cancel' => TrainingRequestStore::SUBMIT,
        'recommend' => TrainingRequestStore::HOD_RECOMMEND,
        'review' => TrainingRequestStore::HR_REVIEW,
        'approve' => TrainingRequestStore::APPROVE,
        'reject' => match ($status) {
            'pending_hod' => TrainingRequestStore::HOD_RECOMMEND,
            'pending_hr' => TrainingRequestStore::HR_REVIEW,
            'pending_approval' => TrainingRequestStore::APPROVE,
        },
    };
    Role::query()->where('code', $role)->sole()->capabilities()->where('capability_key', $capability)->delete();
    forgetRequestAuthorization();

    expect(fn () => app(TrainingRequestStore::class)->{$method}(
        $f['actors'][array_search($role, ['hr' => 'people_hr', 'hod' => 'people_hod', 'approver' => 'people_training_approver'], true)],
        (int) $f['company']->id, (int) $request->id, ...in_array($method, ['recommend', 'review', 'approve'], true) ? [] : ['Reason.'],
    ))->toThrow(AuthorizationDeniedException::class);
})->with([
    ['submit', 'people_hr', 'draft'],
    ['recommend', 'people_hod', 'pending_hod'],
    ['review', 'people_hr', 'pending_hr'],
    ['approve', 'people_training_approver', 'pending_approval'],
    ['reject', 'people_hod', 'pending_hod'],
    ['reject', 'people_hr', 'pending_hr'],
    ['reject', 'people_training_approver', 'pending_approval'],
    ['cancel', 'people_hr', 'draft'],
]);

test('the shared boundary refuses missing tenant and sibling-company writes', function (): void {
    $f = requestFixture();
    [, $sibling] = createTenantWithCompany([], ['tenant_id' => $f['tenant']->id]);
    expect(fn () => app(TrainingRequestStore::class)->create($f['actors']['hr'], (int) $sibling->id, requestDraft($f)))
        ->toThrow(InvalidTrainingRequestException::class, 'company scope');
    app(TenantContext::class)->clear();
    expect(fn () => app(TrainingRequestStore::class)->submit($f['actors']['hr'], (int) $f['company']->id, 1))
        ->toThrow(InvalidTrainingRequestException::class, 'tenant context');
});

test('request queries require an explicit company axis', function (): void {
    requestFixture();
    expect(fn () => TrainingRequest::query()->count())->toThrow(MissingCompanyScopeException::class);
});

/** Put an employee in an organisation unit, which is what makes them a cohort member. */
function requestPlaceInUnit(array $f, $employee, $unit, bool $active = true): void
{
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id, 'organization_unit_id' => $unit->id,
    ]);
    if (! $active) {
        $employee->update(['status' => 'inactive']);
    }
}

test('a submitter may request training for themselves and for nobody else', function (): void {
    $f = requestFixture();
    $other = NativeWorkforceFixture::create((int) $f['tenant']->id, WorkforceResourceType::Employee, (int) $f['company']->id);
    $store = app(TrainingRequestStore::class);
    $companyId = (int) $f['company']->id;
    $before = TrainingRequest::query()->forCompany((int) $f['tenant']->id, $companyId)->count();

    // Self is the whole point of the submit capability.
    $own = $store->create($f['actors']['hr'], $companyId, requestDraft($f), TrainingRequestSubjectsDraft::forSubjects([
        $f['subject']($f['employee'], WorkforceResourceType::Employee),
    ]));
    expect(TrainingRequestSubject::query()->forCompany((int) $f['tenant']->id, $companyId)
        ->where('training_request_id', $own->id)->count())->toBe(1);

    // Naming somebody else is an instruction, not a request.
    expect(fn () => $store->create($f['actors']['hr'], $companyId, requestDraft($f), TrainingRequestSubjectsDraft::forSubjects([
        $f['subject']($other, WorkforceResourceType::Employee),
    ])))->toThrow(InvalidTrainingRequestException::class, 'ask the head of department');

    expect(TrainingRequest::query()->forCompany((int) $f['tenant']->id, $companyId)->count())->toBe($before + 1);
});

test('only a head of department may take the whole department', function (): void {
    $f = requestFixture();
    $store = app(TrainingRequestStore::class);
    $companyId = (int) $f['company']->id;
    $before = TrainingRequest::query()->forCompany((int) $f['tenant']->id, $companyId)->count();

    expect(fn () => $store->create($f['actors']['hr'], $companyId, requestDraft($f), TrainingRequestSubjectsDraft::forDepartmentCohort()))
        ->toThrow(InvalidTrainingRequestException::class, 'whole department');

    expect(TrainingRequest::query()->forCompany((int) $f['tenant']->id, $companyId)->count())->toBe($before);
});

test('a request naming nobody is refused', function (): void {
    $f = requestFixture();
    $store = app(TrainingRequestStore::class);
    $companyId = (int) $f['company']->id;
    $before = TrainingRequest::query()->forCompany((int) $f['tenant']->id, $companyId)->count();

    expect(fn () => $store->create($f['actors']['hr'], $companyId, requestDraft($f), TrainingRequestSubjectsDraft::forSubjects([])))
        ->toThrow(InvalidTrainingRequestException::class, 'at least one person');

    expect(TrainingRequest::query()->forCompany((int) $f['tenant']->id, $companyId)->count())->toBe($before);
});

test('a department cohort takes its active members and nobody else', function (): void {
    $f = requestFixture();
    $tenantId = (int) $f['tenant']->id;
    $companyId = (int) $f['company']->id;
    $otherUnit = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::OrganizationUnit, $companyId);

    // NativeWorkforceFixture::create(OrganizationUnit) also creates one
    // employee inside the unit, so the department is not empty to begin with.
    $existing = collect(app(WorkforceSubjects::class)->employees($companyId))
        ->filter(fn ($e): bool => $e->organizationReference?->externalId === (string) $f['department']->id)
        ->map(fn ($e): string => (string) $e->reference->externalId)
        ->values();

    $ada = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $grace = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $departed = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    foreach ([$ada, $grace, $departed] as $member) {
        requestPlaceInUnit($f, $member, $f['department']);
    }
    $departed->update(['status' => 'inactive']);
    $elsewhere = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    requestPlaceInUnit($f, $elsewhere, $otherUnit);

    $request = app(TrainingRequestStore::class)->create(
        $f['actors']['hod'], $companyId, requestDraft($f), TrainingRequestSubjectsDraft::forDepartmentCohort(),
    );

    $rows = TrainingRequestSubject::query()->forCompany($tenantId, $companyId)
        ->where('training_request_id', $request->id)->get();
    $ids = $rows->pluck('employee_subject_id')->all();

    expect($rows)->toHaveCount($existing->count() + 2)
        ->and($ids)->toContain((string) $ada->id, (string) $grace->id)
        ->and($ids)->not->toContain((string) $departed->id)
        ->and($ids)->not->toContain((string) $elsewhere->id)
        ->and($rows->pluck('source')->unique()->all())->toBe([TrainingRequestSubject::SOURCE_COHORT])
        ->and($rows->first()->cohort_reference)->toBe((string) $f['department']->id)
        ->and($rows->first()->workforce_observed_at)->not->toBeNull();
});

test('a head of department may not name somebody from another department', function (): void {
    $f = requestFixture();
    $tenantId = (int) $f['tenant']->id;
    $companyId = (int) $f['company']->id;
    $otherUnit = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::OrganizationUnit, $companyId);
    $elsewhere = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    requestPlaceInUnit($f, $elsewhere, $otherUnit);
    $before = TrainingRequest::query()->forCompany($tenantId, $companyId)->count();

    expect(fn () => app(TrainingRequestStore::class)->create(
        $f['actors']['hod'], $companyId, requestDraft($f),
        TrainingRequestSubjectsDraft::forSubjects([$f['subject']($elsewhere, WorkforceResourceType::Employee)]),
    ))->toThrow(InvalidTrainingRequestException::class, 'belong to the department');

    expect(TrainingRequest::query()->forCompany($tenantId, $companyId)->count())->toBe($before);
});
function requestRejected(array $f): TrainingRequest
{
    $store = app(TrainingRequestStore::class);
    $request = $store->create($f['actors']['hr'], (int) $f['company']->id, requestDraft($f));
    $store->submit($f['actors']['hr'], (int) $f['company']->id, (int) $request->id);

    return $store->reject($f['actors']['hod'], (int) $f['company']->id, (int) $request->id, 'Not this quarter.');
}

test('a rejected request revises back to draft with new substance and keeps its identity', function (): void {
    $f = requestFixture();
    $store = app(TrainingRequestStore::class);
    $rejected = requestRejected($f);

    // The revision draft names other live subjects: the store still keeps
    // the row's own requestor and department, because a revision is the
    // same request, not a new one.
    $tenantId = (int) $f['tenant']->id;
    $companyId = (int) $f['company']->id;
    $draft = requestDraft($f, [
        'requestor' => new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee,
            (string) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId)->id),
        'department' => new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::OrganizationUnit,
            (string) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::OrganizationUnit, $companyId)->id),
        'need' => 'Line 3 needs the same control-system course.',
    ]);

    $revised = $store->revise($f['actors']['hr'], $companyId, (int) $rejected->id, $draft, 'Narrowed to line 3.');

    expect($revised->id)->toBe((int) $rejected->id)
        ->and($revised->request_key)->toBe($rejected->request_key)
        ->and($revised->status)->toBe(TrainingRequestStatus::Draft)
        ->and($revised->need)->toBe('Line 3 needs the same control-system course.')
        ->and($revised->requestor_subject_id)->toBe($rejected->requestor_subject_id)
        ->and($revised->department_subject_id)->toBe($rejected->department_subject_id)
        ->and($revised->created_by_user_id)->toBe($rejected->created_by_user_id)
        ->and($revised->decisions()->pluck('decision')->all())->toBe(['created', 'submitted', 'rejected', 'revised'])
        ->and($revised->decisions()->where('decision', 'rejected')->sole()->notes)->toBe('Not this quarter.')
        ->and($revised->decisions()->where('decision', 'revised')->sole()->notes)->toBe('Narrowed to line 3.');

    // The revised request rejoins the lifecycle where a draft does.
    $store->submit($f['actors']['hr'], $companyId, (int) $revised->id);
    expect($revised->fresh()->status)->toBe(TrainingRequestStatus::PendingHod);
});

test('a revision refuses anything but a rejected request, and refuses silence', function (): void {
    $f = requestFixture();
    $store = app(TrainingRequestStore::class);
    $companyId = (int) $f['company']->id;
    $draft = $store->create($f['actors']['hr'], $companyId, requestDraft($f));

    expect(fn () => $store->revise($f['actors']['hr'], $companyId, (int) $draft->id, requestDraft($f), 'Notes.'))
        ->toThrow(InvalidTrainingRequestException::class, 'Only a rejected training request can be revised.');
    expect(fn () => $store->revise($f['actors']['hr'], $companyId, (int) $draft->id, requestDraft($f), '   '))
        ->toThrow(InvalidTrainingRequestException::class, 'Revision notes are required.');

    $rejected = requestRejected($f);
    expect(fn () => $store->revise($f['actors']['hr'], $companyId, (int) $rejected->id, requestDraft($f, ['need' => '']), 'Notes.'))
        ->toThrow(InvalidTrainingRequestException::class, 'need, learning objective');
    expect($rejected->fresh()->status)->toBe(TrainingRequestStatus::Rejected)
        ->and($rejected->fresh()->decisions()->pluck('decision')->all())->toBe(['created', 'submitted', 'rejected']);
});
