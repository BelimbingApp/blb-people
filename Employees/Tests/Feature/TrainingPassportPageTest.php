<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Employees\Livewire\TrainingPassport;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Models\SkillActorBinding;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Models\TrainingSession;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{user: User, employee: Employee, other: Employee, tenantId: int, companyId: int} */
function trainingPassportPageFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Training passport company']);
    app(TenantContext::class)->set((int) $tenant->id);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'full_name' => 'Passport Employee',
        'status' => 'active',
    ]);
    $other = Employee::factory()->create([
        'company_id' => $company->id,
        'full_name' => 'Other Employee',
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
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $user->id,
        'display_name' => 'Passport employee',
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    SkillActorBinding::query()->create([
        'tenant_id' => $tenant->id,
        'company_entity_id' => $company->id,
        'platform_user_id' => $user->id,
        'employee_entity_id' => $employee->id,
        'user_entity_id' => $user->id,
        'confirmed_by_user_id' => $user->id,
        'review_reference' => 'training-passport-fixture',
        'confirmed_at' => now(),
    ]);

    trainingPassportPageRecord((int) $tenant->id, (int) $company->id, $employee, 'Own training', 'Own skill', 'CERT-OWN', $user);
    trainingPassportPageRecord((int) $tenant->id, (int) $company->id, $other, 'Other training', 'Other skill', 'CERT-OTHER', $user);

    return [
        'user' => $user,
        'employee' => $employee,
        'other' => $other,
        'tenantId' => (int) $tenant->id,
        'companyId' => (int) $company->id,
    ];
}

function trainingPassportPageRecord(
    int $tenantId,
    int $companyId,
    Employee $employee,
    string $title,
    string $skillName,
    string $certificate,
    User $recordedBy,
): void {
    $tag = Str::lower(Str::random(12));
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, "passport-{$tag}", 'Passport');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: "passport.{$tag}", name: $skillName, definition: 'A passport test skill.', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: "passport.{$tag}.course", title: $title, deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $employee->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $employee->id,
    ));
    $participant = TrainingParticipant::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'event_id' => $event->id,
        'provider_id' => ExternalReference::PROVIDER_ID,
        'employee_subject_id' => (string) $employee->id,
        'workforce_observed_at' => now(),
    ]);
    $session = TrainingSession::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'event_id' => $event->id,
        'session_reference' => "passport-session-{$tag}",
        'starts_at' => $event->starts_at,
        'ends_at' => $event->ends_at,
        'created_by_user_id' => $recordedBy->id,
    ]);
    TrainingParticipationFact::query()->create([
        'tenant_id' => $tenantId,
        'company_entity_id' => $companyId,
        'event_id' => $event->id,
        'participant_id' => $participant->id,
        'session_id' => $session->id,
        'attendance' => AttendanceStatus::Present,
        'actual_minutes' => 120,
        'pre_test' => null,
        'post_test' => null,
        'certificate_reference' => $certificate,
        'certificate_valid_from' => now()->subYear()->toDateString(),
        'certificate_valid_until' => now()->subDay()->toDateString(),
        'evidence_references' => [],
        'source' => 'fixture',
        'source_reference' => "passport-fact-{$tag}",
        'recorded_by_user_id' => $recordedBy->id,
        'recorded_capability' => 'people.training.participation.manage',
        'recorded_at' => now(),
        'confirmed_by_user_id' => $recordedBy->id,
        'confirmed_capability' => 'people.training.participation.verify',
        'confirmed_at' => now(),
    ]);
}

it('renders only the signed-in employee training passport', function (): void {
    $f = trainingPassportPageFixture();

    expect(route('people.training.passport', absolute: false))->toBe('/people/training/passport');

    Livewire::actingAs($f['user'])
        ->test(TrainingPassport::class)
        ->assertSee('Own training')
        ->assertSee('CERT-OWN')
        ->assertSee('Own skill')
        ->assertDontSee('Other training')
        ->assertDontSee('CERT-OTHER')
        ->assertDontSee('Other skill');
});

it('ignores a request-supplied employee id and keeps the authenticated subject', function (): void {
    $f = trainingPassportPageFixture();

    Livewire::withQueryParams(['employee_id' => $f['other']->id])
        ->actingAs($f['user'])
        ->test(TrainingPassport::class)
        ->assertSee('Own training')
        ->assertDontSee('Other training');
});

it('marks expired certificates explicitly', function (): void {
    $f = trainingPassportPageFixture();

    $html = Livewire::actingAs($f['user'])->test(TrainingPassport::class)->html();

    expect($html)->toContain('data-certificate-status="expired"')
        ->and($html)->toContain('Expired');
});
