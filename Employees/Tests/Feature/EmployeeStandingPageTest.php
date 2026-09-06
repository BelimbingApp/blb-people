<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Employees\Livewire\MyStanding;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ReadsOwnSkillStanding;
use App\Domains\People\Skills\Data\OwnAssessmentOutcome;
use App\Domains\People\Skills\Data\OwnSkillStanding;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

function employeeStandingPageUser(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Standing company']);
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'full_name' => 'Amina Employee',
        'status' => 'active',
    ]);
    $user = User::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_employee')->sole()->id,
    ]);

    return [$user, $employee];
}

function employeeStandingPageReader(User $user, Employee $employee): void
{
    $outcome = new OwnAssessmentOutcome(
        assessmentId: 81,
        skillId: 15,
        requirementReference: 'safety.inspection',
        requirementVersion: 3,
        requiredLevel: 4,
        assessedLevel: 2,
        gap: 2,
        resultBand: 'major_gap',
        assessedAt: '2026-09-01T09:00:00+08:00',
        finalizedAt: '2026-09-02T10:00:00+08:00',
        validUntil: '2027-09-01',
        nextAssessmentDue: '2027-08-01',
    );
    $generatedAt = new DateTimeImmutable('2026-09-06T11:30:00+08:00');
    $reader = Mockery::mock(ReadsOwnSkillStanding::class);
    $reader->shouldReceive('read')
        ->withArgs(function (?User $actor, WorkforceSubject $subject, mixed $asOf) use ($user, $employee): bool {
            return $actor?->is($user)
                && $subject->tenantId === (int) $user->tenant_id
                && $subject->companyId === (int) $user->company_id
                && $subject->stableId === (string) $employee->id
                && $asOf === null;
        })
        ->andReturn(new OwnSkillStanding(
            new WorkforceSubject((int) $user->tenant_id, (int) $user->company_id, WorkforceResourceType::Employee, (string) $employee->id),
            $generatedAt,
            $generatedAt->modify('-5 minutes'),
            [$outcome],
            [$outcome],
        ));
    app()->instance(ReadsOwnSkillStanding::class, $reader);
}

it('renders the signed-in employees published standing without another-subject route', function () {
    [$user, $employee] = employeeStandingPageUser();
    employeeStandingPageReader($user, $employee);

    expect(route('people.employee-standing.show', absolute: false))->toBe('/people/my-standing');

    Livewire::actingAs($user)
        ->test(MyStanding::class)
        ->assertSee('My skill standing')
        ->assertSee('safety.inspection')
        ->assertSee('Below requirement')
        ->assertSee('Training history is not available');
});

it('refuses a crafted Livewire update that targets another employee', function () {
    [$user, $employee] = employeeStandingPageUser();
    $other = Employee::factory()->create(['company_id' => $user->company_id, 'status' => 'active']);
    employeeStandingPageReader($user, $employee);

    expect(fn () => Livewire::actingAs($user)
        ->test(MyStanding::class)
        ->set('subjectId', (string) $other->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
