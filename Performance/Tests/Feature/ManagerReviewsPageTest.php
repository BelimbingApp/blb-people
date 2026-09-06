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
use App\Domains\People\Performance\Livewire\Reviews\Index;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Services\PerformanceReviewStore;
use Livewire\Livewire;

/**
 * 0009-b: a manager reads the reviews they authored, with the correction chain,
 * read-only. The subject set comes from the store's author scope, never from
 * the request, and a released rationale reads afterwards exactly as released.
 *
 * Self-contained: helpers are prefixed mgr and live here.
 *
 * @return array<string, mixed>
 */
function mgrFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Manager Reviews Tenant'], ['name' => 'Manager Reviews Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $managers = [];
    foreach (['mine', 'peer'] as $key) {
        $managers[$key] = User::factory()->create(['company_id' => $companyId]);
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $managers[$key]->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hod')->valueOrFail('id'),
        ]);
    }
    $nobody = User::factory()->create(['company_id' => $companyId]);
    $subject = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Reviewed Report',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    return compact('tenantId', 'companyId', 'managers', 'nobody', 'subject');
}

function mgrReview(array $f, User $author, string $rationale = 'Met the agreed expectation with attributable evidence.'): PerformanceReview
{
    $store = app(PerformanceReviewStore::class);
    $observation = $store->recordObservation($author, $f['companyId'], new ObservationDraft(
        employeeEntityId: (int) $f['subject']->id,
        windowStart: new DateTimeImmutable('2026-01-01'),
        windowEnd: new DateTimeImmutable('2026-03-31'),
        evidence: 'Delivered the governed changeover.',
    ));
    $draft = $store->draftReview($author, $f['companyId'], new ReviewDraft(
        employeeEntityId: (int) $f['subject']->id,
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-03-31'),
        cutoffAt: new DateTimeImmutable('2026-04-07T00:00:00+00:00'),
        observationIds: [(int) $observation->id],
        outcome: PerformanceOutcome::Met,
        rationale: $rationale,
    ));

    return $store->finalize($author, $f['companyId'], (int) $draft->id);
}

test('a manager sees the reviews they authored and not a peer manager\'s', function (): void {
    $f = mgrFixture();
    $mine = mgrReview($f, $f['managers']['mine'], 'My released rationale for this period.');
    $peer = mgrReview($f, $f['managers']['peer'], 'A peer manager\'s rationale, not mine to read.');

    $ids = collect(Livewire::actingAs($f['managers']['mine'])->test(Index::class)->viewData('reviews'))
        ->pluck('id')->map(fn ($id): int => (int) $id)->all();

    expect($ids)->toContain((int) $mine->id)
        ->and($ids)->not->toContain((int) $peer->id);
});

test('the correction chain is listed per review with its version, reason and date', function (): void {
    $f = mgrFixture();
    $store = app(PerformanceReviewStore::class);
    $original = mgrReview($f, $f['managers']['mine']);
    $corrected = $store->correct($f['managers']['mine'], $f['companyId'], (int) $original->id, new ReviewDraft(
        employeeEntityId: (int) $f['subject']->id,
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-03-31'),
        cutoffAt: new DateTimeImmutable('2026-04-07T00:00:00+00:00'),
        observationIds: [],
        outcome: PerformanceOutcome::PartiallyMet,
        rationale: 'Late source correction reduced the attributable result.',
    ), 'Source system corrected the stop attribution.');

    $page = Livewire::actingAs($f['managers']['mine'])->test(Index::class);
    $row = collect($page->viewData('reviews'))->firstWhere('id', (int) $corrected->id);

    expect($row['version'])->toBe(2)
        ->and($row['correction_reason'])->toContain('stop attribution')
        ->and($row['supersedes_review_id'])->toBe((int) $original->id);
    $page->assertSee('Source system corrected the stop attribution.');
});

test('a superseded review still shows the rationale it was released with, byte for byte', function (): void {
    $f = mgrFixture();
    $store = app(PerformanceReviewStore::class);
    $released = 'Met the agreed expectation with attributable evidence.';
    $original = mgrReview($f, $f['managers']['mine'], $released);
    $store->correct($f['managers']['mine'], $f['companyId'], (int) $original->id, new ReviewDraft(
        employeeEntityId: (int) $f['subject']->id,
        periodStart: new DateTimeImmutable('2026-01-01'),
        periodEnd: new DateTimeImmutable('2026-03-31'),
        cutoffAt: new DateTimeImmutable('2026-04-07T00:00:00+00:00'),
        observationIds: [],
        outcome: PerformanceOutcome::PartiallyMet,
        rationale: 'A different rationale entirely.',
    ), 'Reason.');

    $row = collect(Livewire::actingAs($f['managers']['mine'])->test(Index::class)->viewData('reviews'))
        ->firstWhere('id', (int) $original->id);

    // The superseded row is history. Its rationale is what was released, not
    // what the correction says now.
    expect($row['rationale'])->toBe($released)
        ->and($row['status'])->toBe('superseded');
});

test('a request-supplied review id outside the author scope is refused', function (): void {
    $f = mgrFixture();
    $peer = mgrReview($f, $f['managers']['peer']);

    Livewire::actingAs($f['managers']['mine'])
        ->test(Index::class)
        ->call('select', (int) $peer->id)
        ->assertHasErrors('selected');
});

test('the page is refused without the view capability', function (): void {
    $f = mgrFixture();
    mgrReview($f, $f['managers']['mine']);

    Livewire::actingAs($f['nobody'])->test(Index::class)->assertForbidden();
});
