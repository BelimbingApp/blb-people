<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\ObservationDraft;
use App\Domains\People\Performance\Data\ReviewDraft;
use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Models\PerformanceReviewReminder;
use App\Domains\People\Performance\Services\PerformanceReviewStore;
use Illuminate\Support\Carbon;

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
function overdueFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Overdue Tenant'], ['name' => 'Overdue Company', 'status' => 'active']);
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
    return Illuminate\Support\Facades\Artisan::call('people:performance:overdue', array_replace([
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

    overdueRun($f);
    overdueRun($f);

    // A scheduler retry, or an operator running it by hand, is not a second
    // thing the manager has ignored.
    expect(overdueRows($f))->toBe(1);
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
        $f['manager'], $f['companyId'], (int) $review->id, (int) $f['subject']->id, 'I accept this.',
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
