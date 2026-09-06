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
use App\Domains\People\Performance\Enums\PerformanceOutcome;
use App\Domains\People\Performance\Enums\PerformanceReviewStatus;
use App\Domains\People\Performance\Exceptions\PerformanceReviewException;
use App\Domains\People\Performance\Models\PerformanceObservation;
use App\Domains\People\Performance\Models\PerformanceReview;
use App\Domains\People\Performance\Services\PerformanceReviewStore;

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * JP-A07: evidence corrected after a final review, or the employee disputing
 * the result, must leave the original review, its released rationale and the
 * response traceable — a correction is a new version, never a rewrite.
 *
 * JP-A11: a historical read names the effective date, the cutoff and the
 * versions it resolved, and the original and corrected views stay
 * distinguishable.
 *
 * Self-contained: Pest does not load helpers from sibling test files.
 *
 * @return array<string, mixed>
 */
function perfFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Perf Tenant'], ['name' => 'Perf Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    $siblingId = (int) Company::factory()->create(['tenant_id' => $tenantId, 'status' => 'active'])->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $users = [];
    foreach (['hr' => 'people_hr', 'hod' => 'people_hod'] as $key => $role) {
        $users[$key] = User::factory()->create(['company_id' => $companyId]);
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $users[$key]->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->valueOrFail('id'),
        ]);
    }
    $subject = Employee::factory()->create([
        'company_id' => $companyId, 'full_name' => 'Reviewed Employee',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    return [
        'tenant' => $tenantId, 'company' => $companyId, 'sibling' => $siblingId,
        'hr' => $users['hr'], 'hod' => $users['hod'], 'subject' => (int) $subject->id,
    ];
}

function perfObservation(array $f, string $evidence = 'Delivered the Q1 changeover with no safety stops.'): PerformanceObservation
{
    return app(PerformanceReviewStore::class)->recordObservation($f['hod'], $f['company'], new ObservationDraft(
        employeeEntityId: $f['subject'],
        windowStart: new DateTimeImmutable('2027-01-01'),
        windowEnd: new DateTimeImmutable('2027-03-31'),
        evidence: $evidence,
        sourceReference: 'ops:changeover-log',
        sourceVersion: 4,
    ));
}

function perfReviewDraft(array $f, array $observationIds): ReviewDraft
{
    return new ReviewDraft(
        employeeEntityId: $f['subject'],
        periodStart: new DateTimeImmutable('2027-01-01'),
        periodEnd: new DateTimeImmutable('2027-03-31'),
        cutoffAt: new DateTimeImmutable('2027-04-07T00:00:00+00:00'),
        observationIds: $observationIds,
        outcome: PerformanceOutcome::Met,
        rationale: 'Met the agreed changeover expectation with attributable evidence.',
    );
}

function perfFinalized(array $f): PerformanceReview
{
    $store = app(PerformanceReviewStore::class);
    $observation = perfObservation($f);
    $draft = $store->draftReview($f['hod'], $f['company'], perfReviewDraft($f, [(int) $observation->id]));

    return $store->finalize($f['hod'], $f['company'], (int) $draft->id);
}

test('a finalized review cannot be rewritten', function (): void {
    $f = perfFixture();
    $review = perfFinalized($f);

    expect($review->status)->toBe(PerformanceReviewStatus::Finalized)
        ->and(fn () => $review->update(['rationale' => 'Quietly reworded after release.']))
        ->toThrow(PerformanceReviewException::class, 'finalized');
});

test('JP-A07: a correction supersedes the original and leaves its released rationale readable', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $original = perfFinalized($f);
    $late = perfObservation($f, 'Late source correction: one stop was mis-attributed.');

    $corrected = $store->correct($f['hr'], $f['company'], (int) $original->id, perfReviewDraft($f, [(int) $late->id]), 'Source system corrected the stop attribution.');

    expect((int) $corrected->supersedes_review_id)->toBe((int) $original->id)
        ->and($corrected->version)->toBe(2)
        ->and($corrected->correction_reason)->toContain('stop attribution')
        ->and($original->refresh()->status)->toBe(PerformanceReviewStatus::Superseded)
        ->and($original->rationale)->toBe('Met the agreed changeover expectation with attributable evidence.')
        ->and($original->finalized_at)->not->toBeNull();
});

test('JP-A07: a correction needs a stated reason', function (): void {
    $f = perfFixture();
    $original = perfFinalized($f);

    expect(fn () => app(PerformanceReviewStore::class)
        ->correct($f['hr'], $f['company'], (int) $original->id, perfReviewDraft($f, []), '   '))
        ->toThrow(PerformanceReviewException::class, 'reason');
});

test('JP-A07: an employee response survives a correction, even when the outcome does not change', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $original = perfFinalized($f);
    $store->recordEmployeeResponse($f['hr'], $f['company'], (int) $original->id, $f['subject'], 'I dispute the second stop.');

    $corrected = $store->correct($f['hr'], $f['company'], (int) $original->id, perfReviewDraft($f, []), 'Recheck after the dispute; outcome unchanged.');

    expect($corrected->outcome)->toBe($original->outcome)
        ->and($store->responsesFor($f['company'], (int) $original->id))->toHaveCount(1)
        ->and($store->responsesFor($f['company'], (int) $original->id)[0]->response)->toContain('second stop');
});

test('JP-A07: correcting an observation does not change the finalized review that pinned it', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $observation = perfObservation($f);
    $draft = $store->draftReview($f['hod'], $f['company'], perfReviewDraft($f, [(int) $observation->id]));
    $review = $store->finalize($f['hod'], $f['company'], (int) $draft->id);

    $store->correctObservation($f['hod'], $f['company'], (int) $observation->id, 'Corrected: the stop was on the prior shift.', 'Late source correction.');

    $pinned = $store->observationsFor($f['company'], (int) $review->id);

    expect($pinned)->toHaveCount(1)
        ->and((int) $pinned[0]->id)->toBe((int) $observation->id)
        ->and($pinned[0]->evidence)->toBe('Delivered the Q1 changeover with no safety stops.');
});

test('JP-A11: the historical read resolves the version effective at a date and says which it is', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $original = perfFinalized($f);
    $this->travelTo(new DateTimeImmutable('2027-05-01T00:00:00+00:00'));
    $corrected = $store->correct($f['hr'], $f['company'], (int) $original->id, perfReviewDraft($f, []), 'Late evidence.');

    $before = $store->asOf($f['company'], $f['subject'], new DateTimeImmutable('2027-04-20T00:00:00+00:00'));
    $after = $store->asOf($f['company'], $f['subject'], new DateTimeImmutable('2027-06-01T00:00:00+00:00'));

    expect((int) $before['review']->id)->toBe((int) $original->id)
        ->and($before['corrected'])->toBeFalse()
        ->and($before['cutoff_at']->format('Y-m-d'))->toBe('2027-04-07')
        ->and((int) $after['review']->id)->toBe((int) $corrected->id)
        ->and($after['corrected'])->toBeTrue()
        ->and($after['version'])->toBe(2);
    $this->travelBack();
});

test('a review from another company is not reachable', function (): void {
    $f = perfFixture();
    $review = perfFinalized($f);

    expect(fn () => app(PerformanceReviewStore::class)
        ->correct($f['hr'], $f['sibling'], (int) $review->id, perfReviewDraft($f, []), 'Reason.'))
        ->toThrow(PerformanceReviewException::class);
});

test('finalizing needs a rationale a reader can act on', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $observation = perfObservation($f);

    expect(fn () => $store->draftReview($f['hod'], $f['company'], new ReviewDraft(
        employeeEntityId: $f['subject'],
        periodStart: new DateTimeImmutable('2027-01-01'),
        periodEnd: new DateTimeImmutable('2027-03-31'),
        cutoffAt: new DateTimeImmutable('2027-04-07T00:00:00+00:00'),
        observationIds: [(int) $observation->id],
        outcome: PerformanceOutcome::Met,
        rationale: '   ',
    )))->toThrow(PerformanceReviewException::class, 'rationale');
});

test('an actor attributed only to another company cannot correct this review', function (): void {
    $f = perfFixture();
    $review = perfFinalized($f);
    $outsider = User::factory()->create(['company_id' => $f['sibling']]);
    PrincipalRole::query()->create([
        'company_id' => $f['sibling'], 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $outsider->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
    ]);

    // The review id and the company id are both this company's. The only thing
    // between the outsider and a released review is the attribution check —
    // passing the sibling's own id would have been refused by the lookup alone.
    expect(fn () => app(PerformanceReviewStore::class)
        ->correct($outsider, $f['company'], (int) $review->id, perfReviewDraft($f, []), 'Reason.'))
        ->toThrow(PerformanceReviewException::class, 'company scope');
});

test('a draft review cannot be corrected; it is still editable as a draft', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $observation = perfObservation($f);
    $draft = $store->draftReview($f['hod'], $f['company'], perfReviewDraft($f, [(int) $observation->id]));

    // Correction is the route for a released outcome. Offering it on a draft
    // would mint a version 2 that never had a version 1 anyone saw.
    expect(fn () => $store->correct($f['hr'], $f['company'], (int) $draft->id, perfReviewDraft($f, []), 'Reason.'))
        ->toThrow(PerformanceReviewException::class, 'finalized');
});

test('a finalized review cannot be deleted', function (): void {
    $f = perfFixture();
    $review = perfFinalized($f);

    expect(fn () => $review->delete())
        ->toThrow(PerformanceReviewException::class, 'deleted')
        ->and($review->exists)->toBeTrue();
});

test('a response is refused on a review that has not been released', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $observation = perfObservation($f);
    $draft = $store->draftReview($f['hod'], $f['company'], perfReviewDraft($f, [(int) $observation->id]));

    // The workflow is finalize, then response. A response to a draft answers
    // something the employee was never shown.
    expect(fn () => $store->recordEmployeeResponse($f['hr'], $f['company'], (int) $draft->id, $f['subject'], 'Early.'))
        ->toThrow(PerformanceReviewException::class, 'released');
});

test('a response must name the employee the review is about', function (): void {
    $f = perfFixture();
    $review = perfFinalized($f);
    $someoneElse = Employee::factory()->create([
        'company_id' => $f['company'], 'full_name' => 'Other Subject',
        'status' => 'active', 'employee_type' => 'full_time',
    ]);

    expect(fn () => app(PerformanceReviewStore::class)
        ->recordEmployeeResponse($f['hr'], $f['company'], (int) $review->id, (int) $someoneElse->id, 'Not mine.'))
        ->toThrow(PerformanceReviewException::class, 'subject');
});

test('an actor from another company cannot record a response on this review', function (): void {
    $f = perfFixture();
    $review = perfFinalized($f);
    $outsider = User::factory()->create(['company_id' => $f['sibling']]);
    PrincipalRole::query()->create([
        'company_id' => $f['sibling'], 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $outsider->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', 'people_hr')->valueOrFail('id'),
    ]);

    expect(fn () => app(PerformanceReviewStore::class)
        ->recordEmployeeResponse($outsider, $f['company'], (int) $review->id, $f['subject'], 'Outsider.'))
        ->toThrow(PerformanceReviewException::class, 'company scope');
});

test('an observation correction states its reason separately from the corrected evidence', function (): void {
    $f = perfFixture();
    $store = app(PerformanceReviewStore::class);
    $observation = perfObservation($f);

    $replacement = $store->correctObservation(
        $f['hod'], $f['company'], (int) $observation->id,
        'The stop was on the prior shift.',
        'Source system re-attributed the stop after the shift-boundary fix.',
    );

    expect($replacement->evidence)->toBe('The stop was on the prior shift.')
        ->and($replacement->correction_reason)->toBe('Source system re-attributed the stop after the shift-boundary fix.')
        ->and($replacement->evidence)->not->toBe($replacement->correction_reason);
});

test('a recorded observation cannot be quietly rewritten', function (): void {
    $f = perfFixture();
    $observation = perfObservation($f);

    // The store supersedes rather than edits, but Eloquent does not know that.
    expect(fn () => $observation->update(['evidence' => 'Reworded after the fact.']))
        ->toThrow(PerformanceReviewException::class, 'superseding');
});
