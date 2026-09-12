<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceRemapFact;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\People\Training\Data\AttendanceSheet;
use App\Domains\People\Training\Data\LearningTestResult;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Exceptions\InvalidTrainingParticipationException;
use App\Domains\People\Training\Livewire\Event\Index;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/**
 * Bulk attendance-sheet import (0011-c, #331): one CSV per session,
 * validated row by row before anything is written, recorded through the
 * participation store, idempotent by file hash and row. Self-contained:
 * every helper is prefixed attImp and lives here.
 */
afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

function attImpRole(User $user, string $code): void
{
    $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);
}

function attImpEmployee(int $companyId, string $number, string $name): Employee
{
    return Employee::factory()->create([
        'company_id' => $companyId, 'employee_number' => $number,
        'full_name' => $name, 'short_name' => null, 'supervisor_id' => null,
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
}

/** @return array<string, mixed> */
function attImpFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Sheet Tenant'], ['name' => 'Sheet Co']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $hr = User::factory()->create(['company_id' => $company->id]);
    attImpRole($hr, 'people_hr');

    $ann = attImpEmployee($companyId, 'SHEET-001', 'Ann Sheet');
    $bob = attImpEmployee($companyId, 'SHEET-002', 'Bob Sheet');
    $cid = attImpEmployee($companyId, 'SHEET-003', 'Cid Sheet');
    $trainerEmployee = attImpEmployee($companyId, 'SHEET-090', 'Trainer Sheet');

    $trainerUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $trainerEmployee->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $trainerEmployee->id, 'user_id' => $trainerUser->id,
        'display_name' => 'Trainer Sheet', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    attImpRole($trainerUser, 'people_training_trainer');

    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'sheet-safety', 'Sheet safety');
    $skill = $catalog->defineSkill($companyId, new SkillDraft(
        code: 'sheet.forklift', name: 'Sheet forklift', definition: 'Operate the sheet forklift.', categoryId: (int) $category->id,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'sheet.induction', title: 'Sheet induction', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $trainerEmployee->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDay(), endsAt: now()->addDay()->addHours(4),
        capacity: 20, organizerEmployeeEntityId: (int) $trainerEmployee->id,
    ));
    $session = app(TrainingParticipationStore::class)->defineSession(
        $hr, $companyId, (int) $event->id, 'sheet-1', $event->starts_at, $event->starts_at->addHours(2),
    );

    return compact('tenantId', 'companyId', 'hr', 'ann', 'bob', 'cid', 'trainerEmployee', 'trainerUser', 'event', 'session');
}

function attImpCsv(array $rows): string
{
    $lines = ['employee_number,attendance,actual_minutes,pre_test_score,post_test_score,certificate_reference,certificate_valid_until'];
    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return implode("\n", $lines)."\n";
}

function attImpFacts(array $f): int
{
    return TrainingParticipationFact::query()->forCompany($f['tenantId'], $f['companyId'])->count();
}

test('a three-row valid sheet creates three facts and the summary reports three attended', function (): void {
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());
    $csv = attImpCsv([
        ['SHEET-001', 'present', '110', '40', '85', 'CERT-001', '2027-06-01'],
        ['SHEET-002', 'present', '120', '', '', '', ''],
        ['SHEET-003', 'present', '90', '', '70', '', ''],
    ]);

    $result = app(TrainingParticipationStore::class)->importSheet($f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv));

    expect($result->created)->toBe(3)->and($result->skipped)->toBe(0)
        ->and($result->refused)->toBe(0)->and($result->defects)->toBe([]);
    expect(attImpFacts($f))->toBe(3);

    $facts = TrainingParticipationFact::query()->forCompany($f['tenantId'], $f['companyId'])->orderBy('id')->get();
    expect($facts->pluck('source')->all())->toBe(['attendance_sheet', 'attendance_sheet', 'attendance_sheet'])
        ->and($facts->pluck('recorded_by_user_id')->map(intval(...))->all())->toBe([(int) $f['hr']->id, (int) $f['hr']->id, (int) $f['hr']->id])
        ->and($facts[0]->post_test['score'])->toBe(85)
        ->and($facts[0]->post_test['passed'])->toBeNull()
        ->and($facts[0]->certificate_reference)->toBe('CERT-001');

    $summaries = app(SummarizesTrainingParticipation::class)->forEvents($f['companyId'], [(int) $f['event']->id]);
    expect($summaries[(int) $f['event']->id]->attended)->toBe(3);
});

test('one invalid minutes value writes zero facts and reports that row number', function (): void {
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());
    $csv = attImpCsv([
        ['SHEET-001', 'present', '110', '', '', '', ''],
        ['SHEET-002', 'present', 'nine', '', '', '', ''],
        ['SHEET-003', 'present', '120', '', '', '', ''],
    ]);

    $result = app(TrainingParticipationStore::class)->importSheet($f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv));

    expect($result->created)->toBe(0)->and($result->refused)->toBe(1)
        ->and(array_map(fn ($defect): int => $defect->row, $result->defects))->toBe([2]);
    expect(attImpFacts($f))->toBe(0);
});

test('importing the same file twice creates then skips, and the fact count stays put', function (): void {
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());
    $csv = attImpCsv([
        ['SHEET-001', 'present', '110', '', '', '', ''],
        ['SHEET-002', 'present', '120', '', '', '', ''],
        ['SHEET-003', 'absent', '0', '', '', '', ''],
    ]);
    $store = app(TrainingParticipationStore::class);

    $first = $store->importSheet($f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv));
    $second = $store->importSheet($f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv));

    expect($first->created)->toBe(3)->and($second->created)->toBe(0)
        ->and($second->skipped)->toBe(3)->and($second->refused)->toBe(0);
    expect(attImpFacts($f))->toBe(3);
});

test('an employee number from the sibling company is refused as unknown', function (): void {
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());
    $sibling = NativeWorkforceFixture::create($f['tenantId'], WorkforceResourceType::Company);
    $stranger = attImpEmployee((int) $sibling->id, 'SIBLING-001', 'Stranger Sheet');
    $csv = attImpCsv([['SIBLING-001', 'present', '110', '', '', '', '']]);

    // The number belongs to the sibling company in the same tenant, but the
    // acting company's directory does not know it: the row is refused as
    // unknown and nothing is written.
    $result = app(TrainingParticipationStore::class)->importSheet(
        $f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv),
    );

    expect($stranger->exists)->toBeTrue();
    expect($result->refused)->toBe(1)->and(attImpFacts($f))->toBe(0);
});

test('an assigned trainer imports while an unassigned trainer and a foreign HR are refused', function (): void {
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());
    $csv = attImpCsv([['SHEET-001', 'present', '110', '', '', '', '']]);
    $store = app(TrainingParticipationStore::class);

    $assigned = $store->importSheet($f['trainerUser'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv));
    expect($assigned->created)->toBe(1);

    $outsider = User::factory()->create(['company_id' => $f['companyId'], 'employee_id' => $f['bob']->id]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $f['bob']->id, 'user_id' => $outsider->id,
        'display_name' => 'Bob Sheet', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    attImpRole($outsider, 'people_training_trainer');
    expect(fn () => $store->importSheet($outsider, $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv)))
        ->toThrow(InvalidTrainingParticipationException::class);

    [$farTenant, $farCompany] = createTenantWithCompany(['name' => 'Far Tenant'], ['name' => 'Far Co']);
    app(TenantContext::class)->set((int) $farTenant->id);
    $farHr = User::factory()->create(['company_id' => $farCompany->id]);
    attImpRole($farHr, 'people_hr');
    app(TenantContext::class)->set($f['tenantId']);
    expect(fn () => $store->importSheet($farHr, $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv)))
        ->toThrow(InvalidTrainingParticipationException::class);
    expect(attImpFacts($f))->toBe(1);
});

test('a certificate valid until today with a time component counts as today, yesterday is refused', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-15T12:00:00+00:00'));
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());
    $store = app(TrainingParticipationStore::class);

    $today = $store->importSheet($f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv(attImpCsv(
        [['SHEET-001', 'present', '110', '', '', 'CERT-TODAY', '2026-09-16 18:30:00']],
    )));
    expect($today->created)->toBe(1);
    expect(TrainingParticipationFact::query()->forCompany($f['tenantId'], $f['companyId'])->sole()->certificate_valid_until->format('Y-m-d'))
        ->toBe('2026-09-16');

    $yesterday = $store->importSheet($f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv(attImpCsv(
        [['SHEET-002', 'present', '110', '', '', 'CERT-PAST', '2026-09-15']],
    )));
    expect($yesterday->refused)->toBe(1)->and(attImpFacts($f))->toBe(1);
});

test('an employee number shared by two directory records is refused rather than guessed', function (): void {
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());

    // Core employees cannot share a number, but the provider seam makes no
    // such promise for connector workforces: double one record through a
    // delegating directory double.
    $real = app(ReadsWorkforceDirectory::class);
    $ann = collect($real->employees((string) $f['companyId']))->firstWhere('employeeNumber', 'SHEET-001');
    $ghost = new WorkforceEmployee(
        reference: new ExternalReference(WorkforceResourceType::Employee, 'att-imp-ghost'),
        companyReference: $ann->companyReference,
        displayName: 'Ghost Sheet',
        active: true,
        effectiveAt: $ann->effectiveAt,
        observedAt: $ann->observedAt,
        employeeNumber: 'SHEET-001',
    );
    app()->instance(ReadsWorkforceDirectory::class, new class($real, $ghost) implements ReadsWorkforceDirectory
    {
        public function __construct(private readonly ReadsWorkforceDirectory $inner, private readonly WorkforceEmployee $extra) {}

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
            return [...$this->inner->employees($companyStableId), $this->extra];
        }

        /** @return list<WorkforceOrganizationUnit> */
        public function organizationUnits(string $companyStableId): array
        {
            return $this->inner->organizationUnits($companyStableId);
        }

        public function employeeForUser(string $companyStableId, int $platformUserId): ?WorkforceEmployee
        {
            return $this->inner->employeeForUser($companyStableId, $platformUserId);
        }

        public function remap(WorkforceResourceType $type, string $fromStableId, string $toStableId): ?WorkforceRemapFact
        {
            return $this->inner->remap($type, $fromStableId, $toStableId);
        }
    });

    $result = app(TrainingParticipationStore::class)->importSheet(
        $f['hr'], $f['companyId'], (int) $f['session']->id,
        AttendanceSheet::fromCsv(attImpCsv([['SHEET-001', 'present', '110', '', '', '', '']])),
    );

    expect($result->refused)->toBe(1)
        ->and($result->defects[0]->message)->toContain('more than one employee');
    expect(attImpFacts($f))->toBe(0);
});

test('scores outside 0 to 100 and unknown attendance values are refused per row', function (): void {
    $f = attImpFixture();
    $this->travelTo($f['event']->ends_at->addHour());
    $csv = attImpCsv([
        ['SHEET-001', 'maybe', '110', '', '', '', ''],
        ['SHEET-002', 'present', '120', '', '101', '', ''],
    ]);

    $result = app(TrainingParticipationStore::class)->importSheet($f['hr'], $f['companyId'], (int) $f['session']->id, AttendanceSheet::fromCsv($csv));

    expect($result->refused)->toBe(2)
        ->and(array_map(fn ($defect): int => $defect->row, $result->defects))->toBe([1, 2]);
    expect(attImpFacts($f))->toBe(0);
});

test('a recorded score without a declared pass mark carries no verdict', function (): void {
    expect((new LearningTestResult(true, 80, 100))->toArray())->toMatchArray(['score' => 80.0, 'passed' => null])
        ->and(fn () => new LearningTestResult(true, 50))->toThrow(InvalidTrainingParticipationException::class);
});

test('HR uploads a sheet from the event page and sees the outcome', function (): void {
    $f = attImpFixture();

    $page = Livewire::actingAs($f['hr'])->test(Index::class)->assertOk()
        ->assertSeeHtml('wire:click="importAttendance('.$f['event']->id.')"')
        ->set('importSession.'.$f['event']->id, (int) $f['session']->id)
        ->set('attendanceSheet', UploadedFile::fake()->createWithContent('attendance.csv', attImpCsv([
            ['SHEET-001', 'present', '110', '', '', '', ''],
            ['SHEET-002', 'present', '120', '', '', '', ''],
        ])));

    // The upload is stored before travelling: Livewire garbage-collects a
    // temporary file older than a day by the travelled clock on upload.
    $this->travelTo($f['event']->ends_at->addHour());
    $page->call('importAttendance', (int) $f['event']->id)->assertHasNoErrors()
        ->assertSee('2 created');

    expect(attImpFacts($f))->toBe(2);
});

test('a refused sheet renders its defects as a captioned table on the event page', function (): void {
    $f = attImpFixture();

    $page = Livewire::actingAs($f['hr'])->test(Index::class)->assertOk()
        ->set('importSession.'.$f['event']->id, (int) $f['session']->id)
        ->set('attendanceSheet', UploadedFile::fake()->createWithContent('attendance.csv', attImpCsv([
            ['NOPE-000', 'present', '120', '', '', '', ''],
        ])));

    $this->travelTo($f['event']->ends_at->addHour());
    $page->call('importAttendance', (int) $f['event']->id)->assertHasNoErrors()
        ->assertSee('Attendance sheet defects')
        ->assertSee('NOPE-000');

    expect(attImpFacts($f))->toBe(0);
});
