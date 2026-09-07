<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\ObservationDraft;
use App\Domains\People\Performance\Data\ReviewDraft;
use App\Domains\People\Performance\Enums\EscalationAudience;
use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Models\PerformanceReviewEscalation;
use App\Domains\People\Performance\Models\PerformanceReviewReminder;
use App\Domains\People\Performance\Services\OverdueReviewEscalations;
use App\Domains\People\Performance\Services\PerformanceReviewStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 0009-d: a review still ignored after two weekly reminders stops being the
 * manager's problem alone.
 *
 * The reminder rows from 0009-c are the evidence. Two consecutive week keys
 * for one review and manager is the trigger, and the escalation is written
 * once per fortnight so a scheduler retry does not read as the manager
 * ignoring the same review twice over.
 *
 * Self-contained: helpers are prefixed escalation and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/** @return array<string, mixed> */
function escalationFixture(string $label = 'Escalation'): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => $label.' Tenant'],
        ['name' => $label.' Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    // The reporting line the escalation walks: the manager's employee record
    // points at their own supervisor, whose user is the escalation target.
    $seniorEmployee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Senior Manager',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $managerEmployee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Line Manager',
        'status' => 'active', 'employee_type' => 'full_time',
        'supervisor_id' => $seniorEmployee->id,
    ]);
    $senior = escalationUser($companyId, 'people_hod', (int) $seniorEmployee->id);
    $manager = escalationUser($companyId, 'people_hod', (int) $managerEmployee->id);
    $hr = escalationUser($companyId, 'people_hr', null);
    $subject = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Reviewed Employee',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    return compact('tenantId', 'companyId', 'manager', 'senior', 'hr', 'subject', 'managerEmployee', 'seniorEmployee');
}

function escalationUser(int $companyId, string $roleCode, ?int $employeeId): User
{
    $user = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employeeId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->valueOrFail('id'),
    ]);

    return $user;
}

/** A draft review created far enough back to be overdue. */
function escalationReview(array $f): PerformanceReview
{
    Carbon::setTestNow(now()->subDays(45)->toDateTimeString());
    $store = app(PerformanceReviewStore::class);
    $observation = $store->recordObservation($f['manager'], $f['companyId'], new ObservationDraft(
        employeeEntityId: (int) $f['subject']->id,
        windowStart: new DateTimeImmutable('2026-01-01'),
        windowEnd: new DateTimeImmutable('2026-03-31'),
        evidence: 'Observed the changeover.',
    ));
    $review = $store->draftReview($f['manager'], $f['companyId'], new ReviewDraft(
        employeeEntityId: (int) $f['subject']->id,
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-03-31'),
        cutoffAt: new DateTimeImmutable('2026-04-07T00:00:00+00:00'),
        observationIds: [(int) $observation->id],
        outcome: PerformanceOutcome::Met,
        rationale: 'Met the agreed expectation with attributable evidence.',
    ));
    Carbon::setTestNow();

    return $review;
}

function escalationRun(array $f, array $options = []): int
{
    return Artisan::call('people:performance:overdue', array_replace([
        '--tenant' => $f['tenantId'],
        '--company' => $f['companyId'],
    ], $options));
}

/** Run the command in each of the given weeks, so reminders accumulate. */
function escalationWeeks(array $f, int $count): void
{
    for ($week = $count - 1; $week >= 0; $week--) {
        Carbon::setTestNow(now()->subWeeks($week)->toDateTimeString());
        escalationRun($f);
        Carbon::setTestNow();
    }
}

function escalationRows(array $f): int
{
    return PerformanceReviewEscalation::query()->forCompany($f['tenantId'], $f['companyId'])->count();
}

test('two consecutive weekly reminders escalate the review to the manager\'s manager', function (): void {
    $f = escalationFixture();
    escalationReview($f);
    escalationWeeks($f, 2);

    $escalation = PerformanceReviewEscalation::query()->forCompany($f['tenantId'], $f['companyId'])->sole();

    // Delete the two-week condition and one week is enough, which is the whole
    // point of waiting.
    expect(escalationRows($f))->toBe(1)
        ->and((int) $escalation->escalated_to_user_id)->toBe((int) $f['senior']->id)
        ->and((int) $escalation->manager_user_id)->toBe((int) $f['manager']->id)
        ->and($escalation->audience)->toBe(EscalationAudience::Manager);
});

test('one week of reminders escalates nothing', function (): void {
    $f = escalationFixture();
    escalationReview($f);
    escalationWeeks($f, 1);

    expect(PerformanceReviewReminder::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1)
        ->and(escalationRows($f))->toBe(0);
});

test('running again in the same fortnight adds no second escalation', function (): void {
    $f = escalationFixture();
    escalationReview($f);
    escalationWeeks($f, 2);
    escalationRun($f);

    // A scheduler retry is not a second failure to act.
    expect(escalationRows($f))->toBe(1);
});

test('a manager with no manager escalates to HR rather than dropping', function (): void {
    $f = escalationFixture();
    // Cut the reporting line: the line manager now reports to nobody.
    $f['managerEmployee']->update(['supervisor_id' => null]);
    escalationReview($f);
    escalationWeeks($f, 2);

    $escalation = PerformanceReviewEscalation::query()->forCompany($f['tenantId'], $f['companyId'])->sole();

    // Silence at the top of the line is the case that most needs a reader.
    expect(escalationRows($f))->toBe(1)
        ->and($escalation->escalated_to_user_id)->toBeNull()
        ->and($escalation->audience)->toBe(EscalationAudience::Hr);
});

test('a dry run reports escalations without writing them', function (): void {
    $f = escalationFixture();
    escalationReview($f);
    escalationWeeks($f, 2);
    PerformanceReviewEscalation::query()->forCompany($f['tenantId'], $f['companyId'])->delete();

    expect(escalationRun($f, ['--dry-run' => true]))->toBe(0)
        ->and(escalationRows($f))->toBe(0);
});

test("another tenant's reviews never escalate here", function (): void {
    $f = escalationFixture();
    $other = escalationFixture('Other Escalation');
    app(TenantContext::class)->set($f['tenantId']);
    escalationReview($f);
    escalationWeeks($f, 2);

    expect(escalationRows($other))->toBe(0)
        ->and(escalationRows($f))->toBe(1);
});

test('the database refuses a second escalation for the same review and fortnight', function (): void {
    $f = escalationFixture();
    $review = escalationReview($f);
    escalationWeeks($f, 2);

    $duplicate = fn (): PerformanceReviewEscalation => DB::transaction(
        fn (): PerformanceReviewEscalation => PerformanceReviewEscalation::query()->create([
            'tenant_id' => $f['tenantId'],
            'company_entity_id' => $f['companyId'],
            'review_id' => (int) $review->id,
            'manager_user_id' => (int) $f['manager']->id,
            'escalated_to_user_id' => (int) $f['senior']->id,
            'audience' => EscalationAudience::Manager,
            'fortnight_key' => OverdueReviewEscalations::fortnightKey(now()),
            'notified_at' => now(),
        ]),
    );

    // The service checks first, but the key is what makes the promise. Wrapped
    // in a transaction so the violation rolls back to a savepoint: Postgres
    // aborts the surrounding transaction otherwise and the count below would
    // die instead of answering.
    expect($duplicate)->toThrow(UniqueConstraintViolationException::class)
        ->and(escalationRows($f))->toBe(1);
});

test('company axis: a sibling company in the same tenant is not escalated here', function (): void {
    $f = escalationFixture();
    // Same tenant, second company. The two-tenant test above passes even with
    // the company filter removed, because the tenant filter separates it —
    // this is the test that actually exercises the company scope.
    $sibling = Company::factory()->create([
        'tenant_id' => $f['tenantId'], 'name' => 'Sibling Escalation Company', 'status' => 'active',
    ]);
    $siblingCompanyId = (int) $sibling->id;
    $siblingManagerEmployee = Employee::factory()->create([
        'company_id' => $siblingCompanyId, 'full_name' => 'Sibling Manager',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $siblingFixture = array_merge($f, [
        'companyId' => $siblingCompanyId,
        'manager' => escalationUser($siblingCompanyId, 'people_hod', (int) $siblingManagerEmployee->id),
        'subject' => Employee::factory()->create([
            'company_id' => $siblingCompanyId, 'full_name' => 'Sibling Reviewed',
            'status' => 'active', 'employee_type' => 'full_time',
        ]),
    ]);

    escalationReview($f);
    escalationReview($siblingFixture);
    escalationWeeks($f, 2);
    escalationWeeks($siblingFixture, 2);

    expect(escalationRows($f))->toBe(1)
        ->and(PerformanceReviewEscalation::query()
            ->forCompany($f['tenantId'], $siblingCompanyId)->count())->toBe(1)
        ->and(PerformanceReviewEscalation::query()
            ->forCompany($f['tenantId'], $f['companyId'])
            ->where('manager_user_id', $siblingFixture['manager']->id)->count())->toBe(0);
});

test('the cadence is a fortnight, not a week', function (): void {
    $f = escalationFixture();
    escalationReview($f);

    // Anchor on an even ISO week, so this week and the next share a fortnight
    // and the week after starts a new one. Without this the test would pass
    // whatever the key divided by, which is exactly what it is here to catch.
    $anchor = now();
    while ((int) $anchor->format('W') % 2 !== 0) {
        $anchor = $anchor->addWeek();
    }

    // Two consecutive weeks of reminders, ending on the anchor week.
    foreach ([-1, 0] as $offset) {
        Carbon::setTestNow($anchor->copy()->addWeeks($offset));
        escalationRun($f);
    }
    expect(escalationRows($f))->toBe(1);

    // The next week is still the same fortnight: reminded again, escalated no
    // further. A weekly key would write a second row here.
    Carbon::setTestNow($anchor->copy()->addWeek());
    escalationRun($f);
    expect(escalationRows($f))->toBe(1);

    // The week after that opens the next fortnight, and the silence has earned
    // a fresh escalation.
    Carbon::setTestNow($anchor->copy()->addWeeks(2));
    escalationRun($f);
    Carbon::setTestNow();

    expect(escalationRows($f))->toBe(2);
});
