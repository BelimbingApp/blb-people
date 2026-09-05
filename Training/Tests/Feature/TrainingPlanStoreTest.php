<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Authz\Policies\GrantPolicy;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use App\Domains\People\Training\Data\TrainingPlanDraft;
use App\Domains\People\Training\Data\TrainingPlanItemDraft;
use App\Domains\People\Training\Enums\TrainingDeliveryApproach;
use App\Domains\People\Training\Enums\TrainingPlanStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingPlanException;
use App\Domains\People\Training\Models\TrainingPlan;
use App\Domains\People\Training\Services\TrainingPlanStore;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array<string, mixed> */
function trainingPlanFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $entry = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-PLAN', 'name' => 'Operations planning', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'ops-plan', 'name' => 'Operations planning', 'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id, 'department_type_id' => $type->id, 'status' => 'active',
    ]);
    $head = Employee::factory()->create([
        'company_id' => $company->id, 'department_id' => $department->id,
        'full_name' => 'Plan HOD', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $department->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $entry->id]);
    $hr = User::factory()->create(['company_id' => $company->id]);
    $hod = User::factory()->create(['company_id' => $company->id, 'employee_id' => $head->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Plan HOD', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    setupAuthzRoles();
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
        PrincipalRole::query()->create([
            'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id, 'role_id' => $role->id,
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor(
        $hr, $hod, (int) $company->id, (int) $head->id, 'review:training-plan-hod',
    );

    return compact('tenant', 'company', 'entry', 'hr', 'hod');
}

function trainingPlanDraft(array $fixture, array $overrides = []): TrainingPlanDraft
{
    return new TrainingPlanDraft(...array_replace([
        'departmentEntityId' => (int) $fixture['entry']->id,
        'periodStart' => new DateTimeImmutable('2027-01-01'),
        'periodEnd' => new DateTimeImmutable('2027-12-31'),
        'objectives' => 'Close the approved operational capability gaps.',
        'financialTrackingEnabled' => true,
        'items' => [new TrainingPlanItemDraft(
            needTenantId: (int) $fixture['tenant']->id,
            needCompanyEntityId: (int) $fixture['company']->id,
            needReference: 'development-action:OPS-17',
            expectedResult: 'Operators demonstrate the governed safe-work procedure.',
            targetCohort: 'Operations technicians',
            deliveryApproach: TrainingDeliveryApproach::Mixed,
            responsibleOwnerReference: 'employee:'.$fixture['hod']->employee_id,
            intendedTiming: '2027-Q1',
            evaluationApproach: 'Observed task and retained assessor record.',
            budgetLine: ['currency' => 'MYR', 'estimated_minor' => 250000],
        )],
    ], $overrides));
}

test('a departmental plan preserves approved versions and controlled amendment history', function (): void {
    $f = trainingPlanFixture();
    $store = app(TrainingPlanStore::class);
    $first = $store->createDraft($f['hod'], (int) $f['company']->id, trainingPlanDraft($f));
    $store->submit($f['hod'], (int) $f['company']->id, (int) $first->id);
    $approved = $store->approve($f['hr'], (int) $f['company']->id, (int) $first->id);
    $second = $store->amend($f['hod'], (int) $f['company']->id, (int) $first->id, 'Add a newly governed need.');

    expect($approved->status)->toBe(TrainingPlanStatus::Approved)
        ->and($second->plan_key)->toBe($first->plan_key)
        ->and($second->version)->toBe(2)
        ->and($second->status)->toBe(TrainingPlanStatus::Draft)
        ->and($second->items()->count())->toBe(1);
    expect(fn () => $approved->update(['objectives' => 'Rewrite approved scope.']))
        ->toThrow(InvalidTrainingPlanException::class, 'immutable');
    $approvedItem = $approved->items()->sole();
    expect(fn () => $approvedItem->update(['expected_result' => 'Rewrite approved item.']))
        ->toThrow(InvalidTrainingPlanException::class, 'immutable');

    $store->submit($f['hod'], (int) $f['company']->id, (int) $second->id);
    $amended = $store->approve($f['hr'], (int) $f['company']->id, (int) $second->id);

    expect($amended->status)->toBe(TrainingPlanStatus::Amended)
        ->and($first->refresh()->status)->toBe(TrainingPlanStatus::Superseded)
        ->and((int) $amended->amends_plan_id)->toBe((int) $first->id)
        ->and($amended->amendment_reason)->toBe('Add a newly governed need.');
});

test('the write boundary refuses a sibling company even when ids are supplied directly', function (): void {
    $f = trainingPlanFixture();
    [, $sibling] = createTenantWithCompany([], ['tenant_id' => $f['tenant']->id]);

    // Mutation: removing CompanyAttribution::mayActFor from TrainingPlanStore::scope makes this create a sibling row.
    expect(fn () => app(TrainingPlanStore::class)->createDraft($f['hod'], (int) $sibling->id, trainingPlanDraft($f)))
        ->toThrow(InvalidTrainingPlanException::class, 'current company scope');
});

test('the write boundary fails closed without a current tenant', function (): void {
    $f = trainingPlanFixture();
    app(TenantContext::class)->clear();

    // Mutation: removing the explicit null-context refusal changes the failure to an unrelated company denial.
    expect(fn () => app(TrainingPlanStore::class)->createDraft($f['hod'], (int) $f['company']->id, trainingPlanDraft($f)))
        ->toThrow(InvalidTrainingPlanException::class, 'tenant context is required');
});

test('approval refuses every revision that was not submitted', function (): void {
    $f = trainingPlanFixture();
    $plan = app(TrainingPlanStore::class)->createDraft($f['hod'], (int) $f['company']->id, trainingPlanDraft($f));

    // Mutation: removing the submitted-state guard changes this draft into approved history.
    expect(fn () => app(TrainingPlanStore::class)->approve($f['hr'], (int) $f['company']->id, (int) $plan->id))
        ->toThrow(InvalidTrainingPlanException::class, 'submitted');
});

test('HR cannot use its approval authority to submit a departmental plan', function (): void {
    $f = trainingPlanFixture();

    expect(fn () => app(TrainingPlanStore::class)->createDraft(
        $f['hr'], (int) $f['company']->id, trainingPlanDraft($f),
    ))->toThrow(AuthorizationDeniedException::class);
});

test('a same-company actor without the functional capability cannot submit', function (): void {
    $f = trainingPlanFixture();
    $plan = app(TrainingPlanStore::class)->createDraft($f['hod'], (int) $f['company']->id, trainingPlanDraft($f));
    $role = Role::query()->where('code', 'people_hod')->sole();
    $role->capabilities()->where('capability_key', TrainingPlanStore::SUBMIT_CAPABILITY)->delete();
    foreach ([GrantPolicy::class, AuthorizationEngine::class, AuthorizationService::class,
        SkillAudience::class, TrainingPlanStore::class] as $binding) {
        app()->forgetInstance($binding);
    }

    // Mutation: removing SkillAudience authorization from authorizeHod makes this submission succeed.
    expect(fn () => app(TrainingPlanStore::class)->submit($f['hod'], (int) $f['company']->id, (int) $plan->id))
        ->toThrow(AuthorizationDeniedException::class);
});

test('an item cannot link a need attributed to another company', function (): void {
    $f = trainingPlanFixture();
    $item = trainingPlanDraft($f)->items[0];
    $foreign = new TrainingPlanItemDraft(
        $item->needTenantId, $item->needCompanyEntityId + 1, $item->needReference,
        $item->expectedResult, $item->targetCohort, $item->deliveryApproach,
        $item->responsibleOwnerReference, $item->intendedTiming, $item->evaluationApproach, $item->budgetLine,
    );

    // Mutation: removing the need tenant/company equality guard persists this foreign need reference.
    expect(fn () => app(TrainingPlanStore::class)->createDraft(
        $f['hod'], (int) $f['company']->id, trainingPlanDraft($f, ['items' => [$foreign]]),
    ))->toThrow(InvalidTrainingPlanException::class, 'same tenant and company');
});

test('training plan models require the explicit company axis', function (): void {
    $f = trainingPlanFixture();
    app(TrainingPlanStore::class)->createDraft($f['hod'], (int) $f['company']->id, trainingPlanDraft($f));

    expect(fn () => TrainingPlan::query()->count())->toThrow(MissingCompanyScopeException::class);
});
