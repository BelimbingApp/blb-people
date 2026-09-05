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
use App\Domains\People\Skills\Exceptions\MissingCompanyScopeException;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingRequestException;
use App\Domains\People\Training\Models\TrainingRequest;
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
    $request = $store->create($f['actors']['hr'], (int) $f['company']->id, requestDraft($f));
    $store->submit($f['actors']['hr'], (int) $f['company']->id, (int) $request->id);
    $store->recommend($f['actors']['hod'], (int) $f['company']->id, (int) $request->id, 'Technically relevant.');
    $store->review($f['actors']['hr'], (int) $f['company']->id, (int) $request->id, 'Policy checked.');
    $approved = $store->approve($f['actors']['approver'], (int) $f['company']->id, (int) $request->id, 'Approved.');

    expect($approved->status)->toBe(TrainingRequestStatus::Approved)
        ->and($approved->need_source)->toBe(TrainingNeedSource::NewMachineTechnology)
        ->and($approved->priority)->toBe(TrainingPriority::High)
        ->and($approved->decisions()->pluck('decision')->all())
        ->toBe(['created', 'submitted', 'hod_recommended', 'hr_reviewed', 'approved']);
    expect(fn () => $approved->update(['need' => 'Rewrite approved history.']))
        ->toThrow(InvalidTrainingRequestException::class, 'immutable');
    $decision = $approved->decisions()->firstOrFail();
    expect(fn () => $decision->update(['notes' => 'Rewrite decision.']))
        ->toThrow(InvalidTrainingRequestException::class, 'append-only');
    expect(fn () => DB::table('people_training_request_decisions')->where('id', $decision->id)->delete())
        ->toThrow(QueryException::class);
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
