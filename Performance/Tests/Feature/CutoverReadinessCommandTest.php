<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Performance\Data\ObservationDraft;
use App\Domains\People\Performance\Data\ReviewDraft;
use App\Domains\People\Performance\Enums\EscalationAudience;
use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Models\PerformanceReviewEscalation;
use App\Domains\People\Performance\Services\CutoverReadiness;
use App\Domains\People\Performance\Services\PerformanceReviewStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * 0009-e: is this company ready to stop doing performance reviews by hand?
 *
 * Four questions, each answerable with a count, and the command is red when
 * any count is non-zero. A readiness check that reports "mostly ready" is not
 * a readiness check — the whole value is that somebody can run it and get a
 * yes or a no.
 *
 * Self-contained: helpers are prefixed cutover and live here.
 */
afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function cutoverReadinessUser(int $companyId, string $roleCode, ?int $employeeId = null): User
{
    $user = User::factory()->create(['company_id' => $companyId, 'employee_id' => $employeeId]);
    PrincipalRole::query()->create([
        'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->valueOrFail('id'),
    ]);

    return $user;
}

/**
 * A compliant company: one manager who holds the review capability, and one
 * report who has that manager.
 *
 * @return array<string, mixed>
 */
function cutoverReadinessFixture(string $label = 'Cutover'): array
{
    [$tenant, $company] = createTenantWithCompany(
        ['name' => $label.' Tenant'],
        ['name' => $label.' Company', 'status' => 'active'],
    );
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $managerEmployee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Cutover Manager',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $reportEmployee = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Cutover Report',
        'status' => 'active', 'employee_type' => 'full_time',
        'supervisor_id' => $managerEmployee->id,
    ]);
    // The manager is the top of the tree and has no supervisor, which is what
    // a real company looks like. An earlier version of this fixture gave them
    // themselves as a supervisor to reach green; that cycle was hiding a
    // defect rather than modelling anything.

    $manager = cutoverReadinessUser($companyId, 'people_hod', (int) $managerEmployee->id);
    $hr = cutoverReadinessUser($companyId, 'people_hr');

    return compact('tenantId', 'companyId', 'company', 'manager', 'hr', 'managerEmployee', 'reportEmployee');
}

function cutoverReadinessRun(array $f, array $options = []): int
{
    return Artisan::call('people:performance:cutover-check', array_replace([
        '--tenant' => $f['tenantId'], '--company' => $f['companyId'],
    ], $options));
}

/** @return array<string, int> check name => count */
function cutoverReadinessCounts(array $f): array
{
    app(TenantContext::class)->set($f['tenantId']);

    return collect(app(CutoverReadiness::class)->check($f['tenantId'], $f['companyId']))
        ->mapWithKeys(static fn (object $row): array => [$row->check => $row->count])
        ->all();
}

/** A draft review by the fixture's manager, created the given days ago. */
function cutoverReadinessDraft(array $f, int $daysAgo): void
{
    Carbon::setTestNow(now()->subDays($daysAgo)->toDateTimeString());
    $store = app(PerformanceReviewStore::class);
    $observation = $store->recordObservation($f['manager'], $f['companyId'], new ObservationDraft(
        employeeEntityId: (int) $f['reportEmployee']->id,
        windowStart: new DateTimeImmutable('2026-01-01'),
        windowEnd: new DateTimeImmutable('2026-03-31'),
        evidence: 'Observed the changeover.',
    ));
    $store->draftReview($f['manager'], $f['companyId'], new ReviewDraft(
        employeeEntityId: (int) $f['reportEmployee']->id,
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-03-31'),
        cutoffAt: new DateTimeImmutable('2026-04-07T00:00:00+00:00'),
        observationIds: [(int) $observation->id],
        outcome: PerformanceOutcome::Met,
        rationale: 'Met the agreed expectation with attributable evidence.',
    ));
    Carbon::setTestNow();
}

/** An escalation on the company's one draft review. */
function cutoverReadinessEscalate(array $f, int $notifiedDaysAgo): void
{
    $review = PerformanceReview::query()
        ->forCompany($f['tenantId'], $f['companyId'])->sole();
    PerformanceReviewEscalation::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'review_id' => (int) $review->id, 'manager_user_id' => (int) $f['manager']->id,
        'escalated_to_user_id' => null, 'audience' => EscalationAudience::Hr,
        'fortnight_key' => '2026-F18', 'notified_at' => now()->subDays($notifiedDaysAgo),
    ]);
}

test('a compliant company is green on every check and exits zero', function (): void {
    $f = cutoverReadinessFixture();

    expect(cutoverReadinessRun($f))->toBe(0)
        ->and(cutoverReadinessCounts($f))->toBe([
            'reporting_line' => 0,
            'manager_capability' => 0,
            'stale_drafts' => 0,
            'open_escalations' => 0,
        ]);
});

test('an employee with no manager is one red count and a non-zero exit', function (): void {
    $f = cutoverReadinessFixture();
    Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Unmanaged Employee',
        'status' => 'active', 'employee_type' => 'full_time', 'supervisor_id' => null,
    ]);

    // Delete the manager-presence check and the run is green, which is the
    // difference between a readiness report and a rubber stamp.
    expect(cutoverReadinessCounts($f)['reporting_line'])->toBe(1)
        ->and(cutoverReadinessRun($f))->toBe(1);
});

test('an inactive employee without a manager is not counted', function (): void {
    $f = cutoverReadinessFixture();
    Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Departed Employee',
        'status' => 'inactive', 'employee_type' => 'full_time', 'supervisor_id' => null,
    ]);

    // Cutover is about the people the process will run for.
    expect(cutoverReadinessCounts($f)['reporting_line'])->toBe(0)
        ->and(cutoverReadinessRun($f))->toBe(0);
});

test('a manager without the review capability is counted', function (): void {
    $f = cutoverReadinessFixture();
    $strandedEmployee = Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Stranded Manager',
        'status' => 'active', 'employee_type' => 'full_time',
        'supervisor_id' => $f['managerEmployee']->id,
    ]);
    Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Their Report',
        'status' => 'active', 'employee_type' => 'full_time',
        'supervisor_id' => $strandedEmployee->id,
    ]);
    // people_employee does not hold people.performance.review.view.
    cutoverReadinessUser($f['companyId'], 'people_employee', (int) $strandedEmployee->id);

    expect(cutoverReadinessCounts($f)['manager_capability'])->toBe(1)
        ->and(cutoverReadinessRun($f))->toBe(1);
});

test('a draft older than the stale threshold is counted and a fresh one is not', function (): void {
    $f = cutoverReadinessFixture();
    cutoverReadinessDraft($f, 31);

    expect(cutoverReadinessCounts($f)['stale_drafts'])->toBe(1)
        ->and(cutoverReadinessRun($f))->toBe(1);
});

test('a draft inside the threshold leaves the company green', function (): void {
    $f = cutoverReadinessFixture();
    cutoverReadinessDraft($f, 29);

    expect(cutoverReadinessCounts($f)['stale_drafts'])->toBe(0)
        ->and(cutoverReadinessRun($f))->toBe(0);
});

test('an escalation older than a fortnight is counted', function (): void {
    $f = cutoverReadinessFixture();
    cutoverReadinessDraft($f, 45);
    $review = PerformanceReview::query()
        ->forCompany($f['tenantId'], $f['companyId'])->sole();
    PerformanceReviewEscalation::query()->create([
        'tenant_id' => $f['tenantId'], 'company_entity_id' => $f['companyId'],
        'review_id' => (int) $review->id, 'manager_user_id' => (int) $f['manager']->id,
        'escalated_to_user_id' => null, 'audience' => EscalationAudience::Hr,
        'fortnight_key' => '2026-F18', 'notified_at' => now()->subDays(15),
    ]);

    expect(cutoverReadinessCounts($f)['open_escalations'])->toBe(1)
        ->and(cutoverReadinessRun($f))->toBe(1);
});

test("another tenant's employees never count", function (): void {
    $f = cutoverReadinessFixture();
    $other = cutoverReadinessFixture('Other Cutover');
    Employee::factory()->create([
        'company_id' => $other['companyId'], 'full_name' => 'Other Unmanaged',
        'status' => 'active', 'employee_type' => 'full_time', 'supervisor_id' => null,
    ]);

    expect(cutoverReadinessCounts($f)['reporting_line'])->toBe(0)
        ->and(cutoverReadinessRun($f))->toBe(0);
});

test('--json prints a machine-readable report', function (): void {
    $f = cutoverReadinessFixture();
    cutoverReadinessRun($f, ['--json' => true]);
    $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['ready'])->toBeTrue()
        ->and($decoded['checks'])->toHaveKey('reporting_line');
});

test('a manager with no user account is counted as unable', function (): void {
    $f = cutoverReadinessFixture();
    $accountless = Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Accountless Manager',
        'status' => 'active', 'employee_type' => 'full_time',
        'supervisor_id' => $f['managerEmployee']->id,
    ]);
    Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Their Report',
        'status' => 'active', 'employee_type' => 'full_time',
        'supervisor_id' => $accountless->id,
    ]);

    // No account is not a milder problem than the wrong capability: either
    // way nobody can write the review this person owes.
    expect(cutoverReadinessCounts($f)['manager_capability'])->toBe(1)
        ->and(cutoverReadinessRun($f))->toBe(1);
});

test('a recent escalation is inside the grace period and not counted', function (): void {
    $f = cutoverReadinessFixture();
    // A draft inside the stale threshold, so this test turns on the
    // escalation grace alone and not on a second check going red.
    cutoverReadinessDraft($f, 10);
    cutoverReadinessEscalate($f, notifiedDaysAgo: 3);

    // Escalating is not itself the failure; going unanswered is.
    expect(cutoverReadinessCounts($f)['open_escalations'])->toBe(0)
        ->and(cutoverReadinessRun($f))->toBe(0);
});

test('an escalation is closed once its review is finalized', function (): void {
    $f = cutoverReadinessFixture();
    cutoverReadinessDraft($f, 45);
    $review = PerformanceReview::query()
        ->forCompany($f['tenantId'], $f['companyId'])->sole();
    cutoverReadinessEscalate($f, notifiedDaysAgo: 20);
    app(PerformanceReviewStore::class)->finalize($f['manager'], $f['companyId'], (int) $review->id);

    // Finalising is the act that answers the escalation, whatever the
    // escalation row still says.
    expect(cutoverReadinessCounts($f)['open_escalations'])->toBe(0)
        ->and(cutoverReadinessRun($f))->toBe(0);
});

test('a top-level manager with no supervisor does not block cutover', function (): void {
    $f = cutoverReadinessFixture();
    $f['managerEmployee']->update(['supervisor_id' => null]);

    expect(cutoverReadinessCounts($f)['reporting_line'])->toBe(0)
        ->and(cutoverReadinessRun($f))->toBe(0);
});

test('an employee with no manager and no reports is still a gap', function (): void {
    $f = cutoverReadinessFixture();
    Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Unmanaged Leaf',
        'status' => 'active', 'employee_type' => 'full_time', 'supervisor_id' => null,
    ]);

    // Distinct from the head above: this person manages nobody, so the tree
    // implies no reviewer for them at all.
    expect(cutoverReadinessCounts($f)['reporting_line'])->toBe(1)
        ->and(cutoverReadinessRun($f))->toBe(1);
});

test('an employee whose only report is inactive is not a manager', function (): void {
    $f = cutoverReadinessFixture();
    $lead = Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Lead Without Live Reports',
        'status' => 'active', 'employee_type' => 'full_time', 'supervisor_id' => null,
    ]);
    Employee::factory()->create([
        'company_id' => $f['companyId'], 'full_name' => 'Their Departed Report',
        'status' => 'inactive', 'employee_type' => 'full_time', 'supervisor_id' => $lead->id,
    ]);

    // Both questions turn on the same rule, so one case holds both: the lead
    // is a reporting-line gap, and is never asked the capability question
    // (they have no account, so being treated as a manager would count them
    // as unable as well).
    expect(cutoverReadinessCounts($f)['reporting_line'])->toBe(1)
        ->and(cutoverReadinessCounts($f)['manager_capability'])->toBe(0)
        ->and(cutoverReadinessRun($f))->toBe(1);
});

test('running without --tenant is refused before handle() runs', function (): void {
    $f = cutoverReadinessFixture();
    app(TenantContext::class)->clear();

    $exit = Artisan::call('people:performance:cutover-check', ['--company' => $f['companyId']]);
    $output = Artisan::output();

    // Refused by the TenantScopedCommand base, not by handle(): no report
    // line, only the base's option-required message.
    expect($exit)->not->toBe(0)
        ->and($output)->toContain('A --tenant=<id> option is required')
        ->and($output)->not->toContain('Ready:')
        ->and($output)->not->toContain('Not ready:');
});

test('an unknown tenant id is refused with the base message', function (): void {
    $f = cutoverReadinessFixture();

    $exit = cutoverReadinessRun($f, ['--tenant' => 999999]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('Tenant [999999] is unknown or not available.')
        ->and($output)->not->toContain('Ready:')
        ->and($output)->not->toContain('Not ready:');
});
