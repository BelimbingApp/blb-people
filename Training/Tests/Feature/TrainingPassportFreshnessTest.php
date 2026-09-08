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
use App\Domains\People\Employees\Livewire\TrainingPassport as EmployeePassportPage;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceRemapFact;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Provider\Exceptions\WorkforceProjectionException;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use App\Domains\People\Training\Livewire\TeamPassports;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingPassportReader;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Passport workforce-context freshness (0014-d, #334): every passport
 * carries who the workforce record describes and how fresh that description
 * is, warns past a configured age, and stays usable when the provider
 * directory is down. Self-contained: every helper is prefixed ppf.
 */
afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

/** @return array<string, mixed> */
function ppfFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Fresh Tenant'], ['name' => 'Fresh Co']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'fresh-ops', 'name' => 'Fresh Operations', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $jobTitle = PeopleReferenceEntry::query()->create([
        'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_JOB_TITLE,
        'code' => 'fresh-operator', 'name' => 'Fresh Operator', 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $type = DepartmentType::query()->create([
        'code' => 'fresh-operations', 'name' => 'Fresh operations',
        'category' => 'operational', 'is_active' => true,
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id, 'department_type_id' => $type->id, 'status' => 'active',
    ]);

    $head = ppfEmployee($companyId, $department, $unit, null, 'Fresh HOD');
    $department->update(['head_id' => $head->id]);
    $member = ppfEmployee($companyId, $department, $unit, $jobTitle, 'Fresh Member');
    $member->update(['supervisor_id' => $head->id]);

    $hod = ppfUser($tenantId, $companyId, $head, 'people_hod');
    $memberUser = ppfUser($tenantId, $companyId, $member, 'people_employee');

    ppfTraining($tenantId, $companyId, $member, 'Fresh course', 'FRESH-CERT-1', $hod);

    return compact('tenantId', 'companyId', 'hod', 'head', 'member', 'memberUser', 'unit', 'jobTitle');
}

function ppfEmployee(int $companyId, Department $department, PeopleReferenceEntry $unit, ?PeopleReferenceEntry $jobTitle, string $name): Employee
{
    $employee = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $department->id,
        'full_name' => $name, 'short_name' => null, 'status' => 'active',
    ]);
    EmployeeWorkProfile::query()->create([
        'employee_id' => $employee->id, 'organization_unit_id' => $unit->id,
        'job_title_id' => $jobTitle?->id,
    ]);

    return $employee;
}

function ppfUser(int $tenantId, int $companyId, Employee $employee, string $role): User
{
    $user = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employee->id]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->sole()->id,
    ]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id, 'user_id' => $user->id,
        'display_name' => $employee->full_name, 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    SkillActorBinding::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'platform_user_id' => $user->id, 'employee_entity_id' => $employee->id,
        'user_entity_id' => $user->id, 'confirmed_by_user_id' => $user->id,
        'review_reference' => 'ppf-fixture', 'confirmed_at' => now(),
    ]);

    return $user;
}

function ppfTraining(int $tenantId, int $companyId, Employee $employee, string $title, string $certificate, User $recordedBy): void
{
    $tag = Str::lower(Str::random(10));
    $course = TrainingCourse::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'code' => "ppf-{$tag}", 'title' => $title, 'delivery_mode' => DeliveryMode::InternalClassroom,
        'internal_trainer_employee_entity_id' => $employee->id, 'active' => true,
    ]);
    $event = TrainingEvent::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId,
        'event_key' => (string) Str::uuid(), 'course_id' => $course->id,
        'course_code_snapshot' => $course->code, 'course_title_snapshot' => $title,
        'delivery_mode_snapshot' => DeliveryMode::InternalClassroom,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2),
        'capacity' => 10, 'status' => 'scheduled',
        'organizer_employee_entity_id' => $employee->id,
    ]);
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'provider_id' => ExternalReference::PROVIDER_ID, 'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
    $session = TrainingSession::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'session_reference' => "ppf-{$tag}", 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at,
        'created_by_user_id' => $recordedBy->id,
    ]);
    TrainingParticipationFact::query()->create([
        'tenant_id' => $tenantId, 'company_entity_id' => $companyId, 'event_id' => $event->id,
        'participant_id' => $participant->id, 'session_id' => $session->id,
        'attendance' => AttendanceStatus::Present, 'actual_minutes' => 60,
        'pre_test' => null, 'post_test' => null, 'certificate_reference' => $certificate,
        'certificate_valid_from' => null, 'certificate_valid_until' => null,
        'evidence_references' => [], 'source' => 'fixture', 'source_reference' => "ppf-{$tag}",
        'recorded_by_user_id' => $recordedBy->id, 'recorded_capability' => 'people.training.participation.manage',
        'recorded_at' => now(), 'confirmed_by_user_id' => null, 'confirmed_capability' => null, 'confirmed_at' => null,
    ]);
}

function ppfSubject(array $f, Employee $employee, ?int $companyId = null): WorkforceSubject
{
    return new WorkforceSubject(
        $f['tenantId'], $companyId ?? $f['companyId'], WorkforceResourceType::Employee, (string) $employee->id,
        new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id),
    );
}

function ppfObserve(Employee $employee, int $hoursAgo): void
{
    Employee::query()->whereKey($employee->id)->update(['updated_at' => now()->subHours($hoursAgo)]);
}

test('a freshly observed passport carries context and the page shows no warning', function (): void {
    $f = ppfFixture();
    ppfObserve($f['member'], 1);

    $context = app(TrainingPassportReader::class)->read($f['memberUser'], ppfSubject($f, $f['member']))->context;

    expect($context->stale)->toBeFalse()->and($context->unavailable)->toBeFalse()
        ->and($context->displayName)->toBe('Fresh Member')
        ->and($context->department)->toBe('Fresh Operations')
        ->and($context->manager)->toBe('Fresh HOD')
        ->and($context->position)->toBe((string) $f['jobTitle']->id);

    Livewire::actingAs($f['memberUser'])->test(EmployeePassportPage::class)->assertOk()
        ->assertSee('Workforce context as of')
        ->assertDontSee('may be out of date')
        ->assertDontSee('Workforce context unavailable');
});

test('an observation past the configured age marks the context stale and warns', function (): void {
    $f = ppfFixture();
    ppfObserve($f['member'], 25);

    $context = app(TrainingPassportReader::class)->read($f['memberUser'], ppfSubject($f, $f['member']))->context;
    expect($context->stale)->toBeTrue();

    Livewire::actingAs($f['memberUser'])->test(EmployeePassportPage::class)->assertOk()
        ->assertSee('Workforce context as of')
        ->assertSee('may be out of date');
});

test('raising the threshold flips a stale observation back to fresh', function (): void {
    $f = ppfFixture();
    ppfObserve($f['member'], 25);
    config()->set('people-training.passport.workforce_context_max_age_hours', 48);

    $context = app(TrainingPassportReader::class)->read($f['memberUser'], ppfSubject($f, $f['member']))->context;

    expect($context->stale)->toBeFalse();
});

test('a dead directory still returns the passport marked unavailable, and the page answers 200', function (): void {
    $f = ppfFixture();
    $real = app(ReadsWorkforceDirectory::class);
    app()->instance(ReadsWorkforceDirectory::class, new class($real) implements ReadsWorkforceDirectory
    {
        public function __construct(private readonly ReadsWorkforceDirectory $inner) {}

        public function companyForPlatform(int $platformCompanyId): ?WorkforceCompany
        {
            return $this->inner->companyForPlatform($platformCompanyId);
        }

        public function company(string $companyStableId): ?WorkforceCompany
        {
            return $this->inner->company($companyStableId);
        }

        /** @return list<WorkforceEmployee> */
        public function employees(string $companyStableId): array
        {
            throw new WorkforceProjectionException('Provider outage.');
        }

        /** @return list<WorkforceOrganizationUnit> */
        public function organizationUnits(string $companyStableId): array
        {
            throw new WorkforceProjectionException('Provider outage.');
        }

        public function employeeForUser(string $companyStableId, int $platformUserId): ?WorkforceEmployee
        {
            throw new WorkforceProjectionException('Provider outage.');
        }

        public function remap(WorkforceResourceType $type, string $fromStableId, string $toStableId): ?WorkforceRemapFact
        {
            throw new WorkforceProjectionException('Provider outage.');
        }
    });

    $passport = app(TrainingPassportReader::class)->read($f['memberUser'], ppfSubject($f, $f['member']));

    expect($passport->context->unavailable)->toBeTrue()
        ->and($passport->events)->not->toBe([])
        ->and($passport->certificates)->not->toBe([]);

    Livewire::actingAs($f['memberUser'])->test(EmployeePassportPage::class)->assertOk()
        ->assertSee('Workforce context unavailable');
});

test('the team page shows the subject workforce context, not the acting HOD', function (): void {
    $f = ppfFixture();
    ppfObserve($f['member'], 1);
    ppfObserve($f['head'], 25);

    Livewire::actingAs($f['hod'])->test(TeamPassports::class, ['employeeId' => (string) $f['member']->id])->assertOk()
        ->assertSee('Fresh Member')
        ->assertSee('Fresh Operations')
        ->assertDontSee('may be out of date');
});

test('a sibling-company subject and a foreign-tenant actor stay refused', function (): void {
    $f = ppfFixture();
    $siblingCompanyId = (int) Company::factory()->create(['tenant_id' => $f['tenantId']])->id;
    $stranger = Employee::factory()->create(['company_id' => $siblingCompanyId, 'status' => 'active']);

    expect(fn () => app(TrainingPassportReader::class)->read($f['hod'], ppfSubject($f, $stranger, $siblingCompanyId)))
        ->toThrow(TrainingPassportDenied::class);

    [$farTenant, $farCompany] = createTenantWithCompany(['name' => 'Far Tenant'], ['name' => 'Far Co']);
    app(TenantContext::class)->set((int) $farTenant->id);
    $farUser = User::factory()->create(['company_id' => $farCompany->id]);
    app(TenantContext::class)->set($f['tenantId']);

    expect(fn () => app(TrainingPassportReader::class)->read($farUser, ppfSubject($f, $f['member'])))
        ->toThrow(TrainingPassportDenied::class);
});
