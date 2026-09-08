<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Livewire\Requests\Register;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Models\TrainingRequestDecision;
use App\Domains\People\Training\Models\TrainingRequestReminder;
use App\Domains\People\Training\Notifications\TrainingRequestReminderNotification;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingRequestReminders;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/**
 * 0009-h: HR is reminded of approved training requests that still have no
 * event after a configured age, once per request, recipient and ISO week.
 *
 * The clock is the `approved` decision row, compared by calendar day. The
 * run moment is pinned so every boundary assertion is exact and no test
 * sleeps. Self-contained: helpers are prefixed reqDue and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/** Every run in this file happens at this moment: a Monday, ISO week 2026-W37. */
function reqDueNow(): Carbon
{
    return Carbon::parse('2026-09-07 08:00:00');
}

function reqDueUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** One company side: an HR user (the recipient), a unit, a requestor, and a scheduled event to link to. */
function reqDueSide(int $tenantId, Company $company, string $label): array
{
    $hr = reqDueUser($company, 'people_hr', $label.' HR');
    // A role holder without the review grant: never a recipient.
    $staff = reqDueUser($company, 'people_employee', $label.' Staff');
    $unit = PeopleReferenceEntry::query()->create([
        'company_id' => $company->id, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
        'code' => 'OPS-'.$label, 'name' => 'Operations '.$label, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
    ]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'full_name' => $label.' Requestor', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    EmployeeWorkProfile::query()->create(['employee_id' => $employee->id, 'organization_unit_id' => $unit->id]);
    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'isolation.energy', name: 'Energy isolation', definition: 'Isolate.', categoryId: (int) $category->id, defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: 'isolation.induction', title: 'Isolation induction '.$label, deliveryMode: DeliveryMode::InternalClassroom, skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $employee->id,
    ));
    $event = app(TrainingEventStore::class)->schedule((int) $company->id, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDays(5), endsAt: now()->addDays(6), capacity: 10, organizerEmployeeEntityId: (int) $employee->id, targetDepartmentEntityId: (int) $unit->id,
    ));

    return compact('hr', 'staff', 'unit', 'employee', 'event') + ['company' => $company, 'companyId' => (int) $company->id, 'tenantId' => $tenantId];
}

/** @return array{tenantId: int, alpha: array, beta: array} */
function reqDueFixture(string $label = 'Request Due'): array
{
    Carbon::setTestNow(reqDueNow());
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $alpha = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Alpha', 'status' => 'active']);
    $beta = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Beta', 'status' => 'active']);

    return ['tenantId' => $tenantId, 'alpha' => reqDueSide($tenantId, $alpha, 'Alpha'), 'beta' => reqDueSide($tenantId, $beta, 'Beta')];
}

/**
 * A request in $status whose `approved` decision (when approved) was recorded
 * at $approvedAt. The workflow's own rows stay; the approval row is appended
 * with the clock the test needs.
 */
function reqDueRequest(array $s, string $need, Carbon $approvedAt, TrainingRequestStatus $status = TrainingRequestStatus::Approved): TrainingRequest
{
    $store = app(TrainingRequestStore::class);
    $request = $store->create($s['hr'], $s['companyId'], new TrainingRequestDraft(
        requestor: new WorkforceSubject($s['tenantId'], $s['companyId'], WorkforceResourceType::Employee, (string) $s['employee']->id),
        department: new WorkforceSubject($s['tenantId'], $s['companyId'], WorkforceResourceType::OrganizationUnit, (string) $s['unit']->id),
        needSource: TrainingNeedSource::LegalCertification, need: $need, learningObjective: 'Objective.', expectedResult: 'Result.', priority: TrainingPriority::Medium,
    ));
    $request = $store->submit($s['hr'], $s['companyId'], (int) $request->id);
    $request->update(['status' => $status]);
    if ($status === TrainingRequestStatus::Approved) {
        TrainingRequestDecision::query()->forCompany($s['tenantId'], $s['companyId'])->create([
            'tenant_id' => $s['tenantId'], 'company_entity_id' => $s['companyId'], 'training_request_id' => $request->id,
            'decision' => 'approved', 'actor_user_id' => $s['hr']->id, 'notes' => null, 'occurred_at' => $approvedAt,
        ]);
    }

    return $request->fresh();
}

function reqDueRun(array $f, array $s, array $options = []): int
{
    return Artisan::call('people:training:requests-due', array_replace([
        '--tenant' => $f['tenantId'],
        '--company' => $s['companyId'],
    ], $options));
}

/** @return list<int> */
function reqDueIds(array $f, array $s): array
{
    return array_map(
        static fn ($row): int => $row->requestId,
        app(TrainingRequestReminders::class)->due($f['tenantId'], $s['companyId']),
    );
}

function reqDueRows(array $f, array $s): int
{
    return TrainingRequestReminder::query()->forCompany($f['tenantId'], $s['companyId'])->count();
}

test('an approval fifteen days old is due, thirteen days old is not, and exactly fourteen days old at 23:59 is due', function (): void {
    $f = reqDueFixture();
    $a = $f['alpha'];
    $fifteen = reqDueRequest($a, 'Fifteen days', reqDueNow()->subDays(15));
    reqDueRequest($a, 'Thirteen days', reqDueNow()->subDays(13));
    // The last minute of the fourteenth day back: a timestamp compare would
    // call this thirteen days and change; the rule counts calendar days.
    $boundary = reqDueRequest($a, 'Fourteen days late evening', reqDueNow()->subDays(14)->setTime(23, 59, 0));
    // The first minute of the thirteenth day back is the other side of the line.
    reqDueRequest($a, 'Thirteen days early morning', reqDueNow()->subDays(13)->setTime(0, 1, 0));

    expect(reqDueIds($f, $a))->toBe([(int) $fifteen->id, (int) $boundary->id]);
});

test('a linked, rejected or cancelled request is never due', function (): void {
    $f = reqDueFixture();
    $a = $f['alpha'];
    $linked = reqDueRequest($a, 'Linked', reqDueNow()->subDays(40));
    app(TrainingRequestStore::class)->linkEvent($a['hr'], $a['companyId'], (int) $linked->id, (int) $a['event']->id);
    reqDueRequest($a, 'Rejected', reqDueNow()->subDays(40), TrainingRequestStatus::Rejected);
    reqDueRequest($a, 'Cancelled', reqDueNow()->subDays(40), TrainingRequestStatus::Cancelled);
    $waiting = reqDueRequest($a, 'Waiting', reqDueNow()->subDays(40));

    expect(reqDueIds($f, $a))->toBe([(int) $waiting->id]);
});

test('remind() twice in the same ISO week writes one row and sends one notification; the next week sends again', function (): void {
    $f = reqDueFixture();
    $a = $f['alpha'];
    $request = reqDueRequest($a, 'Waiting', reqDueNow()->subDays(20));
    Notification::fake();
    $reminders = app(TrainingRequestReminders::class);

    $first = $reminders->remind($f['tenantId'], $a['companyId']);
    $second = $reminders->remind($f['tenantId'], $a['companyId']);

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(0)
        ->and(reqDueRows($f, $a))->toBe(1)
        ->and($first[0]->week_key)->toBe('2026-W37');
    Notification::assertCount(1);
    Notification::assertSentTo($a['hr'], TrainingRequestReminderNotification::class, function (TrainingRequestReminderNotification $n) use ($request, $a): bool {
        return $n->request->requestId === (int) $request->id
            && $n->companyEntityId === $a['companyId']
            && $n->weekKey === '2026-W37'
            && $n->url === route('people.training.requests.register', ['status' => Register::FILTER_APPROVED_UNLINKED]);
    });

    // Sunday of the same week: still W37, nothing new.
    Carbon::setTestNow(reqDueNow()->addDays(6));
    expect($reminders->remind($f['tenantId'], $a['companyId']))->toHaveCount(0)
        ->and(reqDueRows($f, $a))->toBe(1);

    // Monday of the next week: W38, one more row, one more notification.
    Carbon::setTestNow(reqDueNow()->addDays(7));
    $third = $reminders->remind($f['tenantId'], $a['companyId']);
    expect($third)->toHaveCount(1)
        ->and($third[0]->week_key)->toBe('2026-W38')
        ->and(reqDueRows($f, $a))->toBe(2);
    Notification::assertCount(2);
});

test('the command reminds once per week and a dry run lists without writing or sending', function (): void {
    $f = reqDueFixture();
    $a = $f['alpha'];
    $request = reqDueRequest($a, 'Waiting for an event', reqDueNow()->subDays(20));
    Notification::fake();

    $before = reqDueRows($f, $a);
    expect(reqDueRun($f, $a, ['--dry-run' => true]))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('request '.$request->id.'  approved 2026-08-18  unlinked for 20 day(s)  Waiting for an event')
        ->and($output)->toContain('Due: 1. Nothing was recorded.')
        ->and(reqDueRows($f, $a))->toBe($before);
    Notification::assertNothingSent();

    expect(reqDueRun($f, $a))->toBe(0)
        ->and(Artisan::output())->toContain('Reminded: 1');
    expect(reqDueRun($f, $a))->toBe(0)
        ->and(Artisan::output())->toContain('Reminded: 0')
        ->and(reqDueRows($f, $a))->toBe(1);
    Notification::assertCount(1);
});

test('the sibling company is neither listed nor reminded, and another tenant is never loaded', function (): void {
    $f = reqDueFixture();
    $a = $f['alpha'];
    $b = $f['beta'];
    $mine = reqDueRequest($a, 'Alpha waiting', reqDueNow()->subDays(20));
    reqDueRequest($b, 'Beta waiting', reqDueNow()->subDays(20));

    $g = reqDueFixture('Away');
    reqDueRequest($g['alpha'], 'Away waiting', reqDueNow()->subDays(20));
    app(TenantContext::class)->set($f['tenantId']);
    Notification::fake();

    expect(reqDueIds($f, $a))->toBe([(int) $mine->id])
        ->and(app(TrainingRequestReminders::class)->remind($f['tenantId'], $a['companyId']))->toHaveCount(1)
        ->and(reqDueRows($f, $a))->toBe(1)
        ->and(reqDueRows($f, $b))->toBe(0)
        ->and(TrainingRequestReminder::query()->forCompany($g['tenantId'], $g['alpha']['companyId'])->count())->toBe(0);
    Notification::assertSentTo($a['hr'], TrainingRequestReminderNotification::class);
    Notification::assertNotSentTo($a['staff'], TrainingRequestReminderNotification::class);
    Notification::assertNotSentTo($b['hr'], TrainingRequestReminderNotification::class);
    Notification::assertNotSentTo($g['alpha']['hr'], TrainingRequestReminderNotification::class);
});

test('the command refuses to run without a tenant scope and without --company', function (): void {
    $f = reqDueFixture();
    $a = $f['alpha'];
    reqDueRequest($a, 'Waiting', reqDueNow()->subDays(20));
    Notification::fake();

    $this->artisan('people:training:requests-due', ['--company' => $a['companyId']])
        ->expectsOutputToContain('A --tenant=<id> option is required before this command can run.')
        ->assertExitCode(1);
    $this->artisan('people:training:requests-due', ['--tenant' => $f['tenantId']])
        ->expectsOutputToContain('A reminder run is per company: pass --company=<workforce company entity id>.')
        ->assertExitCode(1);
    app(TenantContext::class)->set($f['tenantId']);

    expect(reqDueRows($f, $a))->toBe(0);
    Notification::assertNothingSent();
});

test('a configured age of thirty days moves the fifteen-day case out of due()', function (): void {
    $f = reqDueFixture();
    $a = $f['alpha'];
    $fifteen = reqDueRequest($a, 'Fifteen days', reqDueNow()->subDays(15));
    $forty = reqDueRequest($a, 'Forty days', reqDueNow()->subDays(40));

    expect(reqDueIds($f, $a))->toBe([(int) $forty->id, (int) $fifteen->id]);

    config()->set(TrainingRequestReminders::AGE_CONFIG, 30);

    expect(reqDueIds($f, $a))->toBe([(int) $forty->id]);
});
