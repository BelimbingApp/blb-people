<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Employees\Services\EmployeeStandingReader;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\SelfStandingRefusal;
use App\Domains\People\Skills\Exceptions\SelfStandingDenied;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Skills\Services\AssessmentWorkflowContext;
use App\Domains\People\Skills\Services\SkillCatalogStore;

function standingReadFixture(string $role = 'people_employee'): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Standing tenant']);
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id, 'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->sole()->id,
    ]);
    EmployeePortalAccess::query()->create(['employee_id' => $employee->id, 'user_id' => $user->id, 'display_name' => 'Own employee', 'status' => EmployeePortalAccess::STATUS_ACTIVE]);
    $binding = SkillActorBinding::query()->create([
        'tenant_id' => $tenant->id, 'company_entity_id' => $company->id, 'platform_user_id' => $user->id,
        'employee_entity_id' => $employee->id, 'user_entity_id' => $user->id,
        'confirmed_by_user_id' => $user->id, 'review_reference' => 'standing-fixture', 'confirmed_at' => now(),
    ]);
    $subject = new WorkforceSubject((int) $tenant->id, (int) $company->id, WorkforceResourceType::Employee, (string) $employee->id, new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id));

    return [$user, $subject, $employee, $binding];
}

function standingReadAssessment(User $user, Employee $employee, bool $published = true, array $overrides = []): SkillAssessment
{
    $catalog = app(SkillCatalogStore::class);
    $category = SkillCategory::query()->forCompany((int) $user->tenant_id, (int) $user->company_id)->where('code', 'standing')->first() ?? $catalog->defineCategory((int) $user->company_id, 'standing', 'Standing');
    $skill = Skill::query()->forCompany((int) $user->tenant_id, (int) $user->company_id)->where('code', 'standing.skill')->first() ?? $catalog->defineSkill((int) $user->company_id, new SkillDraft('standing.skill', 'Standing skill', 'A measured skill.', (int) $category->id));
    $reviewer = User::factory()->create(['company_id' => $user->company_id]);

    return AssessmentWorkflowContext::runStoreMutation(function () use ($user, $employee, $skill, $reviewer, $published, $overrides) {
        $assessment = SkillAssessment::query()->create([
            'tenant_id' => $user->tenant_id, 'company_entity_id' => $user->company_id, 'employee_entity_id' => $employee->id,
            'skill_id' => $skill->id, 'requirement_reference' => 'published.requirement', 'requirement_version' => 7,
            'required_level' => 4, 'assessed_level' => 2, 'gap' => 2, 'criticality' => 'critical', 'mandatory_gate' => true,
            'method' => 'direct_observation', 'cycle' => 'annual', 'status' => $published ? 'submitted' : 'draft',
            'assessed_at' => now()->subDay(), 'valid_until' => now()->addMonth(), 'next_assessment_due' => now()->addMonth(),
            'assessor_user_id' => $user->id, 'hod_verification' => 'pending',
            'hod_verifier_user_id' => null, 'hod_verified_at' => null,
            'finalized_at' => null, 'finalized_by_user_id' => null,
            'notes' => 'PRIVATE ASSESSOR NOTES', 'hod_decision_notes' => 'PRIVATE HOD DELIBERATION',
            'evidence' => 'PRIVATE DOCUMENT LINK',
            ...$overrides,
        ]);
        if ($published) {
            $assessment->update(['status' => 'pending_hod_verification']);
            $assessment->update(['hod_verification' => 'verified', 'hod_verifier_user_id' => $reviewer->id, 'hod_verified_at' => now()]);
            $assessment->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by_user_id' => $reviewer->id]);
        }

        return $assessment;
    });
}

it('returns only own published outcomes with versions and no private fields', function () {
    [$user, $subject, $employee] = standingReadFixture();
    $published = standingReadAssessment($user, $employee);
    $draft = standingReadAssessment($user, $employee, false);
    $other = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    standingReadAssessment($user, $other);
    standingReadScore($published);
    $result = app(EmployeeStandingReader::class)->read($user, $subject);
    expect(array_column($result->skills->outcomes, 'assessmentId'))->toBe([(int) $published->id])
        ->and($result->skills->outcomes[0]->requirementVersion)->toBe(7)
        ->and(array_column($result->skills->standing, 'assessmentId'))->toBe([(int) $published->id])
        ->and($result->skills->asOf)->toEqual($result->skills->generatedAt)
        ->and($result->skills->cutoff)->toEqual($result->skills->generatedAt)
        ->and($result->trainingParticipation)->toBeNull()
        ->and($result->trainingCertificates)->toBeNull()
        ->and($result->trainingAvailability->value)->toBe('unsupported');
    $serialized = json_encode($result, JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('PRIVATE', 'hod_decision_notes', 'assessor_user_id', 'evidence');
});

it('refuses another employee even when the actor has HR authority', function () {
    [$user, $subject] = standingReadFixture('people_hr');
    $other = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    $subject = new WorkforceSubject($subject->tenantId, $subject->companyId, WorkforceResourceType::Employee, (string) $other->id);
    expect(fn () => app(EmployeeStandingReader::class)->read($user, $subject))->toThrow(SelfStandingDenied::class);
});

it('refuses missing tenant, wrong scope, wrong provider and revoked binding', function (string $failure) {
    [$user, $subject, $employee, $binding] = standingReadFixture();
    if ($failure === 'tenant') {
        app(TenantContext::class)->clear();
    } elseif ($failure === 'company') {
        $subject = new WorkforceSubject($subject->tenantId, $subject->companyId + 1, $subject->type, $subject->stableId);
    } elseif ($failure === 'provider') {
        $subject = new WorkforceSubject($subject->tenantId, $subject->companyId, $subject->type, $subject->stableId, new ExternalReference($subject->type, $subject->stableId, 'other.provider'));
    } elseif ($failure === 'binding') {
        $binding->update(['revoked_at' => now()]);
    } elseif ($failure === 'employee') {
        $employee->update(['status' => 'inactive']);
    } elseif ($failure === 'permission') {
        PrincipalRole::query()->where('principal_id', $user->id)->delete();
    } elseif ($failure === 'portal') {
        EmployeePortalAccess::query()->where('employee_id', $employee->id)->update(['status' => 'revoked']);
    } elseif ($failure === 'subject') {
        $subject = new WorkforceSubject($subject->tenantId, $subject->companyId, $subject->type, (string) ((int) $subject->stableId + 1));
    }
    $reason = match ($failure) {
        'tenant' => SelfStandingRefusal::MissingScope,
        'company', 'provider', 'subject' => SelfStandingRefusal::SubjectMismatch,
        'permission' => SelfStandingRefusal::Unauthorized,
        default => SelfStandingRefusal::BindingUnavailable,
    };
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->read($user, $subject), $reason);
})->with(['tenant', 'company', 'provider', 'binding', 'employee', 'permission', 'portal', 'subject']);

it('refuses an own unpublished assessment with a typed reason', function () {
    [$user, $subject, $employee] = standingReadFixture();
    $draft = standingReadAssessment($user, $employee, false);
    try {
        app(EmployeeStandingReader::class)->assessment($user, $subject, (int) $draft->id);
        test()->fail('Unpublished assessment was returned.');
    } catch (SelfStandingDenied $exception) {
        expect($exception->reason)->toBe(SelfStandingRefusal::Unpublished);
    }
});

function standingReadDenied(Closure $read, SelfStandingRefusal $reason): void
{
    try {
        $read();
        test()->fail('The self read was not refused.');
    } catch (SelfStandingDenied $exception) {
        expect($exception->reason)->toBe($reason)
            ->and($exception->getMessage())->toBe('The requested self record cannot be read.');
    }
}

function standingReadScore(SkillAssessment $assessment): EmployeeSkillScore
{
    return EmployeeSkillScore::query()->create([
        ...$assessment->only(['tenant_id', 'company_entity_id', 'employee_entity_id', 'skill_id', 'requirement_reference', 'requirement_version', 'required_level', 'gap', 'mandatory_gate', 'criticality', 'assessed_at', 'valid_until', 'next_assessment_due']),
        'source_assessment_id' => $assessment->id, 'current_level' => $assessment->assessed_level,
    ]);
}

it('refuses a score projection whose source is still unpublished', function () {
    [$user, $subject, $employee] = standingReadFixture();
    standingReadScore(standingReadAssessment($user, $employee, false));
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->read($user, $subject), SelfStandingRefusal::Unpublished);
});

it('keeps an authorized empty skill record distinct from unsupported training history', function () {
    [$user, $subject] = standingReadFixture();
    $result = app(EmployeeStandingReader::class)->read($user, $subject);
    expect($result->skills->standing)->toBe([])->and($result->skills->outcomes)->toBe([])
        ->and($result->trainingParticipation)->toBeNull()->and($result->trainingCertificates)->toBeNull();
});

it('does not reuse a previously authorized result after revocation', function () {
    [$user, $subject, $employee, $binding] = standingReadFixture();
    $reader = app(EmployeeStandingReader::class);
    expect($reader->read($user, $subject)->skills->outcomes)->toBe([]);
    $binding->update(['revoked_at' => now()]);
    standingReadDenied(fn () => $reader->read($user, $subject), SelfStandingRefusal::BindingUnavailable);
});

it('does not expose another employees outcome through a known assessment ID', function () {
    [$user, $subject] = standingReadFixture();
    $other = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    $assessment = standingReadAssessment($user, $other);
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->assessment($user, $subject, (int) $assessment->id), SelfStandingRefusal::Unavailable);
});

it('refuses historical requests instead of reconstructing an old grant from current bindings', function () {
    [$user, $subject] = standingReadFixture();
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->read($user, $subject, now()->subYear()), SelfStandingRefusal::UnsupportedPeriod);
});

it('reports directory failure as unavailable without leaking the exception', function () {
    [$user, $subject] = standingReadFixture();
    $directory = Mockery::mock(ReadsWorkforceDirectory::class);
    $directory->shouldReceive('companyForPlatform')->andThrow(new RuntimeException('PRIVATE PROVIDER FAILURE'));
    app()->instance(ReadsWorkforceDirectory::class, $directory);
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->read($user, $subject), SelfStandingRefusal::Unavailable);
});

it('requires an authenticated actor and explicit self audience', function () {
    [$user, $subject] = standingReadFixture('people_hr');
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->read(null, $subject), SelfStandingRefusal::Unauthorized);
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->read($user, $subject), SelfStandingRefusal::Unauthorized);
});

it('does not treat an inconsistent draft timestamp as publication', function () {
    [$user, $subject, $employee] = standingReadFixture();
    standingReadAssessment($user, $employee, false, ['finalized_at' => now()]);
    expect(app(EmployeeStandingReader::class)->read($user, $subject)->skills->outcomes)->toBe([]);
});

it('does not publish future finalized records before their cutoff', function () {
    [$user, $subject, $employee] = standingReadFixture();
    $this->travel(1)->days();
    $future = standingReadAssessment($user, $employee);
    $this->travelBack();
    expect(app(EmployeeStandingReader::class)->read($user, $subject)->skills->outcomes)->toBe([]);
    standingReadDenied(fn () => app(EmployeeStandingReader::class)->assessment($user, $subject, (int) $future->id), SelfStandingRefusal::Unpublished);
});
