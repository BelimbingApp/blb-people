<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Organisation\Services\PositionSubjectExporter;
use App\Domains\People\Performance\Services\PerformanceSubjectExporter;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Services\SkillsSubjectExporter;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Services\TrainingSubjectExporter;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * #412: Performance and Positions (Organisation) register
 * ExportsSupplementalSubjectRecords so the connector DSAR is not silently
 * incomplete after #411. Helpers prefixed perfExport.
 */

beforeEach(function (): void {
    if (! interface_exists(ExportsSupplementalSubjectRecords::class)) {
        $this->markTestSkipped('people-connector/connector must be mounted for supplemental export registration.');
    }
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{tenantId: int, companyId: int, subject: WorkforceSubject, sibling: WorkforceSubject, subjectEmployeeId: int, siblingEmployeeId: int} */
function perfExportSide(string $label): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $label.' Co']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    $subjectEmployee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $siblingEmployee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'subject' => new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee, (string) $subjectEmployee->id),
        'sibling' => new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee, (string) $siblingEmployee->id),
        'subjectEmployeeId' => (int) $subjectEmployee->id,
        'siblingEmployeeId' => (int) $siblingEmployee->id,
    ];
}

function perfExportSeedReview(array $side, int $employeeEntityId, string $marker, int $reviewerUserId): int
{
    app(TenantContext::class)->set($side['tenantId']);

    return (int) DB::table('people_performance_reviews')->insertGetId([
        'tenant_id' => $side['tenantId'],
        'company_entity_id' => $side['companyId'],
        'employee_entity_id' => $employeeEntityId,
        'review_key' => (string) Str::uuid(),
        'version' => 1,
        'status' => 'finalized',
        'period_start' => '2026-01-01',
        'period_end' => '2026-03-31',
        'cutoff_at' => now(),
        'outcome' => 'meets',
        'rationale' => $marker,
        'reviewer_user_id' => $reviewerUserId,
        'finalized_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function perfExportSeedObservation(array $side, int $employeeEntityId, string $marker, int $authorUserId): void
{
    app(TenantContext::class)->set($side['tenantId']);
    DB::table('people_performance_observations')->insert([
        'tenant_id' => $side['tenantId'],
        'company_entity_id' => $side['companyId'],
        'employee_entity_id' => $employeeEntityId,
        'window_start' => '2026-01-01',
        'window_end' => '2026-01-31',
        'evidence' => $marker,
        'author_user_id' => $authorUserId,
        'recorded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function perfExportSeedResponse(array $side, int $reviewId, int $respondentEmployeeId, string $marker): void
{
    app(TenantContext::class)->set($side['tenantId']);
    DB::table('people_performance_review_responses')->insert([
        'tenant_id' => $side['tenantId'],
        'company_entity_id' => $side['companyId'],
        'review_id' => $reviewId,
        'employee_entity_id' => $respondentEmployeeId,
        'response' => $marker,
        'recorded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function perfExportSeedAssignment(array $side, int $employeeEntityId, string $positionStableId): void
{
    app(TenantContext::class)->set($side['tenantId']);
    DB::table('people_position_assignments')->insert([
        'tenant_id' => $side['tenantId'],
        'company_entity_id' => $side['companyId'],
        'position_stable_id' => $positionStableId,
        'employee_entity_id' => $employeeEntityId,
        'type' => 'substantive',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('performance and positions exporters are tagged for the connector supplemental contract', function (): void {
    $names = [];
    foreach (app()->tagged(ExportsSupplementalSubjectRecords::class) as $exporter) {
        $names[] = $exporter->name();
    }
    sort($names);

    expect($names)->toContain('people.performance', 'people.positions', 'people.skills', 'people.training')
        ->and(app(PerformanceSubjectExporter::class)->restorable())->toBeFalse()
        ->and(app(PositionSubjectExporter::class)->restorable())->toBeFalse();
});

test('performance export carries only the subject employee rows for that company and tenant', function (): void {
    $a = perfExportSide('PerfExport');
    $away = perfExportSide('PerfAway');
    $reviewerUserId = (int) User::factory()->create(['company_id' => $a['companyId']])->id;

    $subjectReviewId = perfExportSeedReview($a, $a['subjectEmployeeId'], 'SUBJECT-REVIEW', $reviewerUserId);
    perfExportSeedReview($a, $a['siblingEmployeeId'], 'SIBLING-REVIEW', $reviewerUserId);
    perfExportSeedReview($away, $away['subjectEmployeeId'], 'AWAY-REVIEW', $reviewerUserId);
    perfExportSeedObservation($a, $a['subjectEmployeeId'], 'SUBJECT-OBS', $reviewerUserId);
    perfExportSeedObservation($a, $a['siblingEmployeeId'], 'SIBLING-OBS', $reviewerUserId);
    // Response authored by the subject on their own review.
    perfExportSeedResponse($a, $subjectReviewId, $a['subjectEmployeeId'], 'SUBJECT-RESPONSE');
    // Response authored by the sibling on the subject's review — travels with sibling.
    perfExportSeedResponse($a, $subjectReviewId, $a['siblingEmployeeId'], 'SIBLING-ON-SUBJECT');

    app(TenantContext::class)->set($a['tenantId']);
    $sections = app(PerformanceSubjectExporter::class)->sections($a['subject'], $a['tenantId'], $a['companyId']);

    expect($sections)->toHaveKey('people_performance_reviews')
        ->and(array_column($sections['people_performance_reviews'], 'rationale'))->toBe(['SUBJECT-REVIEW'])
        ->and(array_column($sections['people_performance_observations'], 'evidence'))->toBe(['SUBJECT-OBS'])
        ->and(array_column($sections['people_performance_review_responses'], 'response'))->toBe(['SUBJECT-RESPONSE']);

    $sibling = app(PerformanceSubjectExporter::class)->sections($a['sibling'], $a['tenantId'], $a['companyId']);
    expect(array_column($sibling['people_performance_reviews'] ?? [], 'rationale'))->toBe(['SIBLING-REVIEW'])
        ->and(array_column($sibling['people_performance_review_responses'] ?? [], 'response'))->toBe(['SIBLING-ON-SUBJECT']);

    $foreignCompany = app(PerformanceSubjectExporter::class)->sections(
        new WorkforceSubject($a['tenantId'], $a['companyId'] + 999, WorkforceResourceType::Employee, (string) $a['subjectEmployeeId']),
        $a['tenantId'],
        $a['companyId'] + 999,
    );
    expect($foreignCompany)->toBe([]);
});

test('positions export carries only the subject assignment rows for that company and tenant', function (): void {
    $a = perfExportSide('PosExport');
    $away = perfExportSide('PosAway');

    perfExportSeedAssignment($a, $a['subjectEmployeeId'], 'pos.subject');
    perfExportSeedAssignment($a, $a['siblingEmployeeId'], 'pos.sibling');
    perfExportSeedAssignment($away, $away['subjectEmployeeId'], 'pos.away');

    app(TenantContext::class)->set($a['tenantId']);
    $sections = app(PositionSubjectExporter::class)->sections($a['subject'], $a['tenantId'], $a['companyId']);

    expect($sections)->toHaveKey('people_position_assignments')
        ->and(array_column($sections['people_position_assignments'], 'position_stable_id'))->toBe(['pos.subject'])
        ->and(array_column($sections['people_position_assignments'], 'employee_entity_id'))->toBe([$a['subjectEmployeeId']]);

    $sibling = app(PositionSubjectExporter::class)->sections($a['sibling'], $a['tenantId'], $a['companyId']);
    expect(array_column($sibling['people_position_assignments'] ?? [], 'position_stable_id'))->toBe(['pos.sibling']);
});

test('every people table with employee_entity_id is claimed by an exporter list or a deliberate exclusion', function (): void {
    $claimed = [
        ...SkillsSubjectExporter::EMPLOYEE_ENTITY_TABLES,
        ...SkillsSubjectExporter::DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS,
        ...TrainingSubjectExporter::EMPLOYEE_ENTITY_TABLES,
        ...TrainingSubjectExporter::DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS,
        ...PerformanceSubjectExporter::EMPLOYEE_ENTITY_TABLES,
        ...PerformanceSubjectExporter::DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS,
        ...PositionSubjectExporter::EMPLOYEE_ENTITY_TABLES,
        ...PositionSubjectExporter::DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS,
    ];
    $claimed = array_values(array_unique($claimed));
    sort($claimed);

    $found = [];
    foreach (Schema::getTableListing() as $table) {
        if (! str_starts_with($table, 'people_')) {
            continue;
        }
        if (Schema::hasColumn($table, 'employee_entity_id')) {
            $found[] = $table;
        }
    }
    sort($found);

    $missing = array_values(array_diff($found, $claimed));

    expect($missing)->toBe([]);
});
