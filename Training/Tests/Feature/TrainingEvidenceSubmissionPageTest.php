<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Media\Models\MediaAsset;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\Evidence\Index;
use App\Domains\People\Training\Models\TrainingEvidenceSubmission;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
});

function evidenceRole(User $user, string $code): void
{
    setupAuthzRoles();
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->sole()->id,
    ]);
}

function evidenceFixture(): array
{
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $trainer = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $employee = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $otherEmployee = NativeWorkforceFixture::create((int) $tenant->id, WorkforceResourceType::Employee, (int) $company->id);
    $hr = User::factory()->create(['company_id' => $company->id]);
    evidenceRole($hr, 'people_hr');
    $user = User::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id]);
    evidenceRole($user, 'people_employee');
    EmployeePortalAccess::query()->create([
        'employee_id' => $employee->id,
        'user_id' => $user->id,
        'display_name' => $employee->full_name,
        'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, Str::lower(Str::random(12)), 'Evidence');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        code: Str::lower(Str::random(12)), name: 'Evidence', definition: 'Evidence submission', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: Str::lower(Str::random(12)), title: 'Forklift safety', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 10, organizerEmployeeEntityId: (int) $trainer->id,
    ));

    return compact('tenant', 'company', 'employee', 'otherEmployee', 'hr', 'user', 'course', 'event');
}

function evidenceAttendance(array $fixture, AttendanceStatus $attendance, bool $confirmed = false, ?Employee $employee = null): mixed
{
    $store = app(TrainingParticipationStore::class);
    $session = $store->defineSession(
        $fixture['hr'], (int) $fixture['company']->id, (int) $fixture['event']->id,
        (string) Str::uuid(), $fixture['event']->starts_at, $fixture['event']->ends_at,
    );
    test()->travelTo($fixture['event']->ends_at->addHour());
    $employee ??= $fixture['employee'];
    $subject = new WorkforceSubject(
        (int) $fixture['tenant']->id, (int) $fixture['company']->id, WorkforceResourceType::Employee,
        (string) $employee->id,
        new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id),
    );
    $fact = $store->recordAttendance($fixture['hr'], (int) $fixture['company']->id, (int) $session->id, $subject, new ParticipationFactDraft(
        attendance: $attendance,
        actualMinutes: $attendance === AttendanceStatus::Present ? 120 : 0,
        source: 'manual', sourceReference: (string) Str::uuid(),
    ));

    return $confirmed ? $store->confirm($fixture['hr'], (int) $fixture['company']->id, (int) $fact->id) : $fact;
}

function evidenceDocument(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    ));
}

function evidenceSubmissions(array $fixture): mixed
{
    return TrainingEvidenceSubmission::query()
        ->forCompany((int) $fixture['tenant']->id, (int) $fixture['company']->id);
}

test('an employee submits evidence only for their own attended event and it remains pending HR confirmation', function (): void {
    $fixture = evidenceFixture();
    $otherFact = evidenceAttendance($fixture, AttendanceStatus::Present, employee: $fixture['otherEmployee']);
    $fact = evidenceAttendance($fixture, AttendanceStatus::Present);
    $file = evidenceDocument('forklift-certificate.png');

    Livewire::withQueryParams(['participant_id' => $otherFact->participant_id])
        ->actingAs($fixture['user'])
        ->test(Index::class)
        ->assertSee('Forklift safety')
        ->set('reflection', 'I can now inspect and operate the forklift safely.')
        ->set('certificateNumber', 'FL-2026-0042')
        ->set('certificateExpiresOn', '2027-09-07')
        ->set('document', $file)
        ->call('submit', (int) $fixture['event']->id)
        ->assertHasNoErrors()
        ->assertSee('Pending HR confirmation');

    $submission = evidenceSubmissions($fixture)->sole();
    expect($submission->tenant_id)->toBe((int) $fixture['tenant']->id)
        ->and($submission->company_entity_id)->toBe((int) $fixture['company']->id)
        ->and($submission->event_id)->toBe((int) $fixture['event']->id)
        ->and($submission->participant_id)->toBe((int) $fact->participant_id)
        ->and($submission->reflection)->toBe('I can now inspect and operate the forklift safely.')
        ->and($submission->certificate_number)->toBe('FL-2026-0042')
        ->and($submission->certificate_expires_on->format('Y-m-d'))->toBe('2027-09-07')
        ->and($submission->status)->toBe('pending')
        ->and($submission->submitted_by_user_id)->toBe($fixture['user']->id)
        ->and($submission->document_asset_id)->not->toBeNull();
    $asset = MediaAsset::query()->findOrFail($submission->document_asset_id);
    expect($asset->metadata['purpose'])->toBe('people.training.participation.evidence')
        ->and($asset->metadata['participant_id'])->toBe((int) $fact->participant_id);
    Storage::disk('local')->assertExists($asset->storage_key);
});

test('a non-attended event cannot receive employee evidence', function (): void {
    $fixture = evidenceFixture();
    evidenceAttendance($fixture, AttendanceStatus::Absent);

    Livewire::actingAs($fixture['user'])->test(Index::class)
        ->set('reflection', 'This must not be accepted.')
        ->set('document', evidenceDocument('claim.png'))
        ->call('submit', (int) $fixture['event']->id)
        ->assertHasErrors('evidence')
        ->assertSee('attended');

    expect(evidenceSubmissions($fixture)->count())->toBe(0);
});

test('confirmed participation refuses employee evidence with visible recovery guidance', function (): void {
    $fixture = evidenceFixture();
    evidenceAttendance($fixture, AttendanceStatus::Present, confirmed: true);

    Livewire::actingAs($fixture['user'])->test(Index::class)
        ->set('reflection', 'Late certificate.')
        ->set('document', evidenceDocument('late.png'))
        ->call('submit', (int) $fixture['event']->id)
        ->assertHasErrors('evidence')
        ->assertSee('already been confirmed');

    expect(evidenceSubmissions($fixture)->count())->toBe(0);
});

test('the route is available to employees and refused to users outside the self-service audience', function (): void {
    $fixture = evidenceFixture();
    $outsider = User::factory()->create(['company_id' => $fixture['company']->id]);
    evidenceRole($outsider, 'people_training_trainer');

    $this->actingAs($fixture['user'])->get(route('people.training.evidence.index'))->assertOk()->assertSee('Training evidence');
    $this->actingAs($outsider)->get(route('people.training.evidence.index'))->assertForbidden();
});
