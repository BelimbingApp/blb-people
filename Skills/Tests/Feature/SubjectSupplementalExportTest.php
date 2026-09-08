<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Services\SkillsSubjectExporter;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingSubjectExporter;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * #410: Skills and Training register ExportsSupplementalSubjectRecords so the
 * connector data-subject export is no longer partial. Helpers prefixed subjectExport.
 */

beforeEach(function (): void {
    if (! interface_exists(ExportsSupplementalSubjectRecords::class)) {
        $this->markTestSkipped('people-connector/connector must be mounted for supplemental export registration.');
    }
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array{tenantId: int, companyId: int, subject: WorkforceSubject, sibling: WorkforceSubject, subjectEmployeeId: int, siblingEmployeeId: int, skillId: int} */
function subjectExportSide(string $label): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $label.' Co']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    $subjectEmployee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $siblingEmployee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, $companyId);
    $category = app(SkillCatalogStore::class)->defineCategory($companyId, Str::lower(Str::random(10)), $label);
    $skill = app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: Str::lower(Str::random(10)),
        name: $label.' Skill',
        definition: 'Supplemental export fixture skill.',
        categoryId: (int) $category->id,
    ));

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'subject' => new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee, (string) $subjectEmployee->id),
        'sibling' => new WorkforceSubject($tenantId, $companyId, WorkforceResourceType::Employee, (string) $siblingEmployee->id),
        'subjectEmployeeId' => (int) $subjectEmployee->id,
        'siblingEmployeeId' => (int) $siblingEmployee->id,
        'skillId' => (int) $skill->id,
    ];
}

function subjectExportSeedAssessment(array $side, int $employeeEntityId, string $marker): void
{
    app(TenantContext::class)->set($side['tenantId']);
    DB::table('people_connector_skill_assessments')->insert([
        'tenant_id' => $side['tenantId'],
        'company_entity_id' => $side['companyId'],
        'employee_entity_id' => $employeeEntityId,
        'skill_id' => $side['skillId'],
        'requirement_reference' => 'export.fixture',
        'requirement_version' => 1,
        'required_level' => 3,
        'criticality' => 'critical',
        'mandatory_gate' => false,
        'method' => 'direct_observation',
        'cycle' => 'annual',
        'status' => 'draft',
        'evidence' => $marker,
        'hod_verification' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function subjectExportSeedParticipant(array $side, WorkforceSubject $subject, string $marker): void
{
    app(TenantContext::class)->set($side['tenantId']);
    $trainer = NativeWorkforceFixture::create($side['tenantId'], WorkforceResourceType::Employee, $side['companyId']);
    $course = app(TrainingCatalogStore::class)->defineCourse($side['companyId'], new TrainingCourseDraft(
        code: Str::lower(Str::random(10)),
        title: $marker,
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [$side['skillId']],
        internalTrainerEmployeeEntityId: (int) $trainer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($side['companyId'], new TrainingEventDraft(
        courseId: (int) $course->id,
        startsAt: now()->addDay(),
        endsAt: now()->addDay()->addHours(2),
        capacity: 10,
        organizerEmployeeEntityId: (int) $trainer->id,
    ));

    DB::table('people_training_participants')->insert([
        'tenant_id' => $side['tenantId'],
        'company_entity_id' => $side['companyId'],
        'event_id' => (int) $event->id,
        'provider_id' => ExternalReference::PROVIDER_ID,
        'employee_subject_id' => $subject->stableId,
        'workforce_observed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('skills and training exporters are tagged for the connector supplemental contract', function (): void {
    $names = [];
    foreach (app()->tagged(ExportsSupplementalSubjectRecords::class) as $exporter) {
        $names[] = $exporter->name();
    }
    sort($names);

    expect($names)->toContain('people.skills', 'people.training')
        ->and(app(SkillsSubjectExporter::class)->restorable())->toBeFalse()
        ->and(app(TrainingSubjectExporter::class)->restorable())->toBeFalse();
});

test('skills export carries only the subject employee rows for that company and tenant', function (): void {
    $a = subjectExportSide('SkillsExport');
    $away = subjectExportSide('SkillsAway');

    subjectExportSeedAssessment($a, $a['subjectEmployeeId'], 'SUBJECT-ROW');
    subjectExportSeedAssessment($a, $a['siblingEmployeeId'], 'SIBLING-ROW');
    subjectExportSeedAssessment($away, $away['subjectEmployeeId'], 'AWAY-ROW');

    app(TenantContext::class)->set($a['tenantId']);
    $sections = app(SkillsSubjectExporter::class)->sections($a['subject'], $a['tenantId'], $a['companyId']);

    expect($sections)->toHaveKey('people_connector_skill_assessments')
        ->and(array_column($sections['people_connector_skill_assessments'], 'evidence'))->toBe(['SUBJECT-ROW'])
        ->and(array_column($sections['people_connector_skill_assessments'], 'employee_entity_id'))->toBe([$a['subjectEmployeeId']]);

    $sibling = app(SkillsSubjectExporter::class)->sections($a['sibling'], $a['tenantId'], $a['companyId']);
    expect(array_column($sibling['people_connector_skill_assessments'] ?? [], 'evidence'))->toBe(['SIBLING-ROW']);

    $foreignCompany = app(SkillsSubjectExporter::class)->sections(
        new WorkforceSubject($a['tenantId'], $a['companyId'] + 999, WorkforceResourceType::Employee, (string) $a['subjectEmployeeId']),
        $a['tenantId'],
        $a['companyId'] + 999,
    );
    expect($foreignCompany)->toBe([]);
});

test('training export carries only the subject participant rows for that company and tenant', function (): void {
    $a = subjectExportSide('TrainingExport');
    $away = subjectExportSide('TrainingAway');

    subjectExportSeedParticipant($a, $a['subject'], 'SUBJECT-PART');
    subjectExportSeedParticipant($a, $a['sibling'], 'SIBLING-PART');
    subjectExportSeedParticipant($away, $away['subject'], 'AWAY-PART');

    app(TenantContext::class)->set($a['tenantId']);
    $sections = app(TrainingSubjectExporter::class)->sections($a['subject'], $a['tenantId'], $a['companyId']);

    expect($sections)->toHaveKey('people_training_participants')
        ->and(array_column($sections['people_training_participants'], 'employee_subject_id'))->toBe([$a['subject']->stableId]);

    $sibling = app(TrainingSubjectExporter::class)->sections($a['sibling'], $a['tenantId'], $a['companyId']);
    expect(array_column($sibling['people_training_participants'] ?? [], 'employee_subject_id'))->toBe([$a['sibling']->stableId]);
});

test('every Skills or Training table with employee_entity_id is claimed by an exporter list or a deliberate exclusion', function (): void {
    $claimed = [
        ...SkillsSubjectExporter::EMPLOYEE_ENTITY_TABLES,
        ...SkillsSubjectExporter::DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS,
        ...TrainingSubjectExporter::EMPLOYEE_ENTITY_TABLES,
        ...TrainingSubjectExporter::DELIBERATE_EMPLOYEE_ENTITY_EXCLUSIONS,
    ];
    $claimed = array_values(array_unique($claimed));
    sort($claimed);

    $found = [];
    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (! str_starts_with($table, 'people_connector_skill_') && ! str_starts_with($table, 'people_training_')) {
            continue;
        }
        if (Schema::hasColumn($table, 'employee_entity_id')) {
            $found[] = $table;
        }
    }
    sort($found);

    expect(array_values(array_diff($found, $claimed)))->toBe([]);
});
