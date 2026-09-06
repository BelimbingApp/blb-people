<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\ObservationDraft;
use App\Domains\People\Performance\Data\ReviewDraft;
use App\Domains\People\Performance\Enums\OverdueReviewReason;
use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Models\PerformanceReviewReminder;
use App\Domains\People\Performance\Services\OverdueReviewReminders;
use App\Domains\People\Performance\Services\PerformanceReviewStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 0009-c: a weekly nudge for reviews that have gone quiet, written once per
 * manager, review and week.
 *
 * The idempotency key is the whole point. A reminder job that runs twice
 * because a scheduler retried, or because an operator ran it by hand, must not
 * produce two rows — a reminder counted twice reads as a manager ignoring two
 * things.
 *
 * Self-contained: helpers are prefixed overdue and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/** @return array<string, mixed> */
function overdueFixture(string $label = 'Overdue'): array
{
    // companies.code is unique across the whole install, so a test that needs
    // two tenants has to name them apart.
    [$tenant, $company] = createTenantWithCompany(
        ['name' => $label.' Tenant'],
        ['name' => $label.' Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $manager = User::factory()->create(['company_id' => $companyId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $manager->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hod')->valueOrFail('id'),
    ]);
    $subject = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Reviewed Employee',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    return compact('tenantId', 'companyId', 'manager', 'subject');
}

function overdueDraft(array $f, string $createdAt): PerformanceReview
{
    Carbon::setTestNow($createdAt);
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

function overdueFinalized(array $f, string $finalizedAt): PerformanceReview
{
    $review = overdueDraft($f, $finalizedAt);
    Carbon::setTestNow($finalizedAt);
    $finalized = app(PerformanceReviewStore::class)->finalize($f['manager'], $f['companyId'], (int) $review->id);
    Carbon::setTestNow();

    return $finalized;
}

function overdueRun(array $f, array $options = []): int
{
    return Artisan::call('people:performance:overdue', array_replace([
        '--tenant' => $f['tenantId'],
        '--company' => $f['companyId'],
    ], $options));
}

function overdueRows(array $f): int
{
    return PerformanceReviewReminder::query()->forCompany($f['tenantId'], $f['companyId'])->count();
}

test('a draft older than thirty days produces one reminder', function (): void {
    $f = overdueFixture();
    overdueDraft($f, now()->subDays(31)->toDateTimeString());

    expect(overdueRun($f))->toBe(0)
        ->and(overdueRows($f))->toBe(1);
});

test('a draft younger than thirty days produces nothing', function (): void {
    $f = overdueFixture();
    overdueDraft($f, now()->subDays(29)->toDateTimeString());

    expect(overdueRun($f))->toBe(0)
        ->and(overdueRows($f))->toBe(0);
});

test('running twice in the same week still leaves one reminder', function (): void {
    $f = overdueFixture();
    overdueDraft($f, now()->subDays(31)->toDateTimeString());

    // Both runs must *succeed*. A second run that blows up on the unique key
    // also leaves one row behind, and that is not the same promise.
    expect(overdueRun($f))->toBe(0)
        ->and(overdueRun($f))->toBe(0)
        // A scheduler retry, or an operator running it by hand, is not a
        // second thing the manager has ignored.
        ->and(overdueRows($f))->toBe(1);
});

test('the next week earns a fresh reminder', function (): void {
    $f = overdueFixture();
    overdueDraft($f, now()->subDays(31)->toDateTimeString());
    overdueRun($f);

    Carbon::setTestNow(now()->addWeek());
    overdueRun($f);
    Carbon::setTestNow();

    expect(overdueRows($f))->toBe(2);
});

test('a finalized review with no employee response for more than fourteen days is reminded', function (): void {
    $f = overdueFixture();
    overdueFinalized($f, now()->subDays(15)->toDateTimeString());

    expect(overdueRun($f))->toBe(0)
        ->and(overdueRows($f))->toBe(1);
});

test('a finalized review answered by the employee is not reminded', function (): void {
    $f = overdueFixture();
    $review = overdueFinalized($f, now()->subDays(15)->toDateTimeString());
    app(PerformanceReviewStore::class)->recordEmployeeResponse(
        $f['companyId'], (int) $review->id, (int) $f['subject']->id, 'I accept this.',
    );

    // The nudge is for silence, not for the record existing.
    expect(overdueRun($f))->toBe(0)
        ->and(overdueRows($f))->toBe(0);
});

test('a dry run reports without writing', function (): void {
    $f = overdueFixture();
    overdueDraft($f, now()->subDays(31)->toDateTimeString());

    expect(overdueRun($f, ['--dry-run' => true]))->toBe(0)
        ->and(overdueRows($f))->toBe(0);
});

test('a review in another tenant is never reminded', function (): void {
    $f = overdueFixture();
    overdueDraft($f, now()->subDays(31)->toDateTimeString());
    [$otherTenant, $otherCompany] = createTenantWithCompany(['name' => 'Other Overdue Tenant']);

    overdueRun($f, ['--tenant' => (int) $otherTenant->id, '--company' => (int) $otherCompany->id]);

    expect(PerformanceReviewReminder::query()
        ->forCompany((int) $otherTenant->id, (int) $otherCompany->id)->count())->toBe(0)
        ->and(overdueRows($f))->toBe(0);
});

test('the database refuses a second reminder for the same manager, review and week', function (): void {
    $f = overdueFixture();
    $review = overdueDraft($f, now()->subDays(31)->toDateTimeString());
    overdueRun($f);

    // Wrapped in a transaction so the violation rolls back to a savepoint.
    // Postgres aborts the whole surrounding transaction on a failed statement,
    // so without this the count below dies with "current transaction is
    // aborted" instead of answering the question. SQLite does not, which is
    // why this passed locally and only postgres-mirror caught it.
    $duplicate = fn (): PerformanceReviewReminder => DB::transaction(fn (): PerformanceReviewReminder => PerformanceReviewReminder::query()->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'review_id' => (int) $review->id,
        'manager_user_id' => (int) $f['manager']->id,
        'reason' => OverdueReviewReason::StaleDraft,
        'week_key' => OverdueReviewReminders::weekKey(now()),
        'notified_at' => now(),
    ]));

    // The service checks first, but the key is what makes the promise: two
    // runs racing each other cannot both win.
    expect($duplicate)->toThrow(UniqueConstraintViolationException::class)
        ->and(overdueRows($f))->toBe(1);
});

test('the tenant option decides whose reviews are read', function (): void {
    $first = overdueFixture();
    overdueDraft($first, now()->subDays(31)->toDateTimeString());
    $second = overdueFixture('Second Overdue');
    overdueDraft($second, now()->subDays(31)->toDateTimeString());

    // Point the ambient context at the first tenant, so --tenant is the only
    // thing that can select the second.
    app(TenantContext::class)->set($first['tenantId']);

    // Both tenants are overdue; only the one named is run.
    overdueRun($second);

    expect(overdueRows($second))->toBe(1)
        ->and(overdueRows($first))->toBe(0);
});

test('a released review is never chased as a stale draft', function (): void {
    $f = overdueFixture();
    $review = overdueFinalized($f, now()->subDays(40)->toDateTimeString());
    app(PerformanceReviewStore::class)->recordEmployeeResponse(
        $f['companyId'], (int) $review->id, (int) $f['subject']->id, 'I accept this.',
    );

    // It is old enough to trip the draft rule by age alone. Status, not age,
    // is what says whose turn it is.
    expect(overdueRun($f))->toBe(0)
        ->and(overdueRows($f))->toBe(0);
});
