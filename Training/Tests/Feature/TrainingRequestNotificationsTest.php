<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleNotificationDeliveryLog;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Notifications\TrainingRequestTransitionNotification;
use App\Domains\People\Training\Services\TrainingRequestNotifications;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Support\Facades\Notification;

/**
 * 0010-g: every training request transition tells the next person in the
 * workflow, once, through the People delivery log. Recipients come from the
 * workforce seam and the authz grants; the actor is never their own
 * recipient; notes stay off the payload. Self-contained: helpers are
 * prefixed reqNotif and live here.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function reqNotifRole(User $user, string $roleCode): void
{
    PrincipalRole::query()->create([
        'company_id' => $user->company_id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);
}

/**
 * One company side: a department with a head (the HOD user), a requestor in
 * it with an active portal user, an org unit for the request, HR and an approver.
 */
function reqNotifSide(int $tenantId, Company $company, string $label): array
{
    $companyId = (int) $company->id;
    $type = DepartmentType::query()->firstOrCreate(['code' => 'ops-reqnotif'], ['name' => 'Operations', 'category' => 'operational', 'is_active' => true]);
    $department = Department::query()->create(['company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active']);
    $head = Employee::factory()->create(['company_id' => $companyId, 'department_id' => $department->id, 'full_name' => $label.' Head', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    $department->update(['head_id' => $head->id]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id, 'name' => $label.' HOD']);
    reqNotifRole($hod, 'people_hod');

    $employee = Employee::factory()->create(['company_id' => $companyId, 'department_id' => $department->id, 'full_name' => $label.' Requestor', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    $requestor = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employee->id, 'name' => $label.' Requestor']);
    EmployeePortalAccess::query()->create(['employee_id' => $employee->id, 'user_id' => $requestor->id, 'display_name' => $label.' Requestor', 'status' => EmployeePortalAccess::STATUS_ACTIVE]);
    $unit = PeopleReferenceEntry::query()->create(['company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'code' => 'OPS-'.$label, 'name' => 'Operations '.$label, 'status' => PeopleReferenceEntry::STATUS_ACTIVE]);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);

    $hr = User::factory()->create(['company_id' => $companyId, 'name' => $label.' HR']);
    reqNotifRole($hr, 'people_hr');
    $approver = User::factory()->create(['company_id' => $companyId, 'name' => $label.' Approver']);
    reqNotifRole($approver, 'people_training_approver');

    return compact('company', 'companyId', 'tenantId', 'department', 'head', 'hod', 'employee', 'requestor', 'unit', 'hr', 'approver');
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function reqNotifFixture(string $label = 'ReqNotif'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Alpha', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Beta', 'status' => 'active']);

    return ['tenantId' => $tenantId, 'alpha' => reqNotifSide($tenantId, $alpha, $label.' A'), 'beta' => reqNotifSide($tenantId, $beta, $label.' B')];
}

/** A draft request created by HR for the side's requestor. */
function reqNotifDraft(array $s, string $need = 'Operate the new line safely.'): TrainingRequest
{
    return app(TrainingRequestStore::class)->create($s['hr'], $s['companyId'], new TrainingRequestDraft(
        requestor: new WorkforceSubject($s['tenantId'], $s['companyId'], WorkforceResourceType::Employee, (string) $s['employee']->id),
        department: new WorkforceSubject($s['tenantId'], $s['companyId'], WorkforceResourceType::OrganizationUnit, (string) $s['unit']->id),
        needSource: TrainingNeedSource::NewMachineTechnology, need: $need, learningObjective: 'Objective.', expectedResult: 'Result.', priority: TrainingPriority::Medium,
    ));
}

function reqNotifDecision(array $s, TrainingRequest $request, string $decision): TrainingRequestDecision
{
    return TrainingRequestDecision::query()->forCompany($s['tenantId'], $s['companyId'])
        ->where('training_request_id', $request->id)->where('decision', $decision)->orderByDesc('id')->firstOrFail();
}

/** @return list<array<string, mixed>> */
function reqNotifLogs(array $s, TrainingRequest $request): array
{
    return PeopleNotificationDeliveryLog::query()->where('company_id', $s['companyId'])
        ->where('notifiable_type', TrainingRequest::class)->where('notifiable_id', $request->id)
        ->orderBy('id')->get()->map(static fn (PeopleNotificationDeliveryLog $log): array => [
            'recipient' => (int) $log->recipient, 'subject' => (string) $log->subject, 'decision_id' => (int) ($log->metadata['decision_id'] ?? 0),
        ])->all();
}

test('submitting a draft notifies the department head once and logs one row keyed by the submitted decision', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    $request = reqNotifDraft($a);
    Notification::fake();

    app(TrainingRequestStore::class)->submit($a['hr'], $a['companyId'], (int) $request->id);
    $decision = reqNotifDecision($a, $request, 'submitted');

    expect(reqNotifLogs($a, $request))->toBe([
        ['recipient' => (int) $a['hod']->id, 'subject' => 'people.training.request.submitted', 'decision_id' => (int) $decision->id],
    ]);
    Notification::assertCount(1);
    Notification::assertSentTo($a['hod'], TrainingRequestTransitionNotification::class, function (TrainingRequestTransitionNotification $n) use ($request, $decision, $a): bool {
        return $n->trainingRequestId === (int) $request->id
            && $n->decisionId === (int) $decision->id
            && $n->decision === 'submitted'
            && $n->actorName === (string) $a['hr']->name
            && $n->url === route('people.training.requests.index');
    });
});

test('rejecting a pending request notifies the requestor with the decision and never the notes', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    $request = reqNotifDraft($a);
    $store = app(TrainingRequestStore::class);
    $store->submit($a['hr'], $a['companyId'], (int) $request->id);
    Notification::fake();

    $store->reject($a['hod'], $a['companyId'], (int) $request->id, 'Budget frozen this quarter.');

    Notification::assertCount(1);
    Notification::assertSentTo($a['requestor'], TrainingRequestTransitionNotification::class, function (TrainingRequestTransitionNotification $n) use ($a): bool {
        $payload = $n->toArray($a['requestor']);

        return $n->decision === 'rejected'
            && ! array_key_exists('notes', $payload)
            && ! str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'Budget frozen');
    });
    Notification::assertNotSentTo($a['hod'], TrainingRequestTransitionNotification::class);
});

test('approving a request the HOD recommended notifies the requestor and the HOD once each; HR and approvers are told on the way', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    $request = reqNotifDraft($a);
    $store = app(TrainingRequestStore::class);
    $store->submit($a['hr'], $a['companyId'], (int) $request->id);

    Notification::fake();
    $store->recommend($a['hod'], $a['companyId'], (int) $request->id, 'Relevant.');
    Notification::assertCount(1);
    Notification::assertSentTo($a['hr'], TrainingRequestTransitionNotification::class, fn (TrainingRequestTransitionNotification $n): bool => $n->decision === 'hod_recommended');

    Notification::fake();
    $store->review($a['hr'], $a['companyId'], (int) $request->id, 'Checked.');
    Notification::assertCount(1);
    Notification::assertSentTo($a['approver'], TrainingRequestTransitionNotification::class, fn (TrainingRequestTransitionNotification $n): bool => $n->decision === 'hr_reviewed');

    Notification::fake();
    $store->approve($a['approver'], $a['companyId'], (int) $request->id, 'Approved.');
    $approved = reqNotifDecision($a, $request, 'approved');

    Notification::assertCount(2);
    Notification::assertSentToTimes($a['requestor'], TrainingRequestTransitionNotification::class, 1);
    Notification::assertSentToTimes($a['hod'], TrainingRequestTransitionNotification::class, 1);
    $logs = array_values(array_filter(reqNotifLogs($a, $request), static fn (array $row): bool => $row['decision_id'] === (int) $approved->id));
    expect(array_map(static fn (array $row): int => $row['recipient'], $logs))->toBe([(int) $a['hod']->id, (int) $a['requestor']->id]);
});

test('a refused repeat of the same transition sends nothing and leaves the delivery log unchanged', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    $request = reqNotifDraft($a);
    $store = app(TrainingRequestStore::class);
    $store->submit($a['hr'], $a['companyId'], (int) $request->id);
    $store->recommend($a['hod'], $a['companyId'], (int) $request->id);
    $store->review($a['hr'], $a['companyId'], (int) $request->id);
    $store->approve($a['approver'], $a['companyId'], (int) $request->id);
    $before = PeopleNotificationDeliveryLog::query()->count();
    Notification::fake();

    expect(fn () => $store->approve($a['approver'], $a['companyId'], (int) $request->id))
        ->toThrow(InvalidTrainingRequestException::class, 'not awaiting');

    expect(PeopleNotificationDeliveryLog::query()->count())->toBe($before);
    Notification::assertNothingSent();
});

test('a transition in company A tells nobody in sibling company B or in another tenant, even holders of the same grant', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    $g = reqNotifFixture('Away');
    app(TenantContext::class)->set($f['tenantId']);
    $request = reqNotifDraft($a);
    $store = app(TrainingRequestStore::class);
    $store->submit($a['hr'], $a['companyId'], (int) $request->id);
    Notification::fake();

    $store->recommend($a['hod'], $a['companyId'], (int) $request->id);

    Notification::assertCount(1);
    Notification::assertSentTo($a['hr'], TrainingRequestTransitionNotification::class);
    Notification::assertNotSentTo($b['hr'], TrainingRequestTransitionNotification::class);
    Notification::assertNotSentTo($g['alpha']['hr'], TrainingRequestTransitionNotification::class);
    expect(PeopleNotificationDeliveryLog::query()->where('company_id', $b['companyId'])->count())->toBe(0)
        ->and(PeopleNotificationDeliveryLog::query()->where('company_id', $g['alpha']['companyId'])->count())->toBe(0);
});

test('a head of department who no longer resolves is skipped without a log row, and the submission still stands', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    $request = reqNotifDraft($a);
    $a['department']->update(['head_id' => null]);
    Notification::fake();

    $submitted = app(TrainingRequestStore::class)->submit($a['hr'], $a['companyId'], (int) $request->id);

    expect($submitted->status->value)->toBe('pending_hod')
        ->and(reqNotifLogs($a, $request))->toBe([]);
    Notification::assertNothingSent();
});

test('the actor is never their own recipient: a requestor cancelling their request tells only the HOD who recommended it', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    reqNotifRole($a['requestor'], 'people_employee');
    $request = reqNotifDraft($a);
    $store = app(TrainingRequestStore::class);
    $store->submit($a['hr'], $a['companyId'], (int) $request->id);
    $store->recommend($a['hod'], $a['companyId'], (int) $request->id);
    Notification::fake();

    $store->cancel($a['requestor'], $a['companyId'], (int) $request->id, 'No longer needed.');

    Notification::assertCount(1);
    Notification::assertSentTo($a['hod'], TrainingRequestTransitionNotification::class, fn (TrainingRequestTransitionNotification $n): bool => $n->decision === 'cancelled');
    Notification::assertNotSentTo($a['requestor'], TrainingRequestTransitionNotification::class);
});

test('replaying the dispatcher for the same decision row writes no second log row and sends nothing more', function (): void {
    $f = reqNotifFixture();
    $a = $f['alpha'];
    $request = reqNotifDraft($a);
    $store = app(TrainingRequestStore::class);
    Notification::fake();
    $submitted = $store->submit($a['hr'], $a['companyId'], (int) $request->id);
    $decision = reqNotifDecision($a, $request, 'submitted');
    $before = reqNotifLogs($a, $request);

    $written = app(TrainingRequestNotifications::class)->notify($submitted, $decision, $a['hr']);

    expect($written)->toBe([])
        ->and(reqNotifLogs($a, $request))->toBe($before)
        ->and(count($before))->toBe(1);
    Notification::assertCount(1);
});
