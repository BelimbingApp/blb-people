<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Data\ResolvedSkillRequirement;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\OrganisationSkillCoverage;
use App\Domains\People\Skills\Services\SkillCatalogStore;

test('organisation coverage counts only scores pinned to the published requirement version', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(TenantContext::class)->set((int) $tenant->id);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'status' => 'active']);
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory((int) $company->id, 'coverage', 'Coverage');
    $skill = $catalog->defineSkill((int) $company->id, new SkillDraft(
        'coverage.skill',
        'Coverage skill',
        'A skill used to prove aggregate version pinning.',
        (int) $category->id,
    ));
    $assessment = SkillAssessment::query()->create([
        'tenant_id' => $tenant->id,
        'company_entity_id' => $company->id,
        'employee_entity_id' => $employee->id,
        'skill_id' => $skill->id,
        'requirement_reference' => 'published.profile',
        'requirement_version' => 1,
        'required_level' => 3,
        'assessed_level' => 3,
        'gap' => 0,
        'criticality' => RequirementCriticality::Critical,
        'method' => 'direct_observation',
        'cycle' => 'annual',
        'status' => 'draft',
        'assessed_at' => now(),
    ]);
    $score = EmployeeSkillScore::query()->create([
        ...$assessment->only([
            'tenant_id', 'company_entity_id', 'employee_entity_id', 'skill_id',
            'requirement_reference', 'requirement_version', 'required_level', 'gap', 'criticality', 'assessed_at',
        ]),
        'source_assessment_id' => $assessment->id,
        'current_level' => 3,
    ]);
    $requirements = Mockery::mock(ResolvesSkillRequirements::class);
    $requirements->shouldReceive('requirementsFor')->twice()->andReturn([
        new ResolvedSkillRequirement(
            requirementReference: 'published.profile',
            requirementVersion: 2,
            // Coverage reads requirements through the seam and never checks
            // the pin, so any id serves; named arguments keep this call honest
            // if the DTO grows again.
            requirementProfileId: 4001,
            skillId: (int) $skill->id,
            requiredLevel: 3,
            criticality: RequirementCriticality::Critical,
        ),
    ]);
    $coverage = new OrganisationSkillCoverage(app(TenantContext::class), $requirements);
    $companyReference = new ExternalReference(WorkforceResourceType::Company, (string) $company->id);
    $subject = new WorkforceEmployee(
        new ExternalReference(WorkforceResourceType::Employee, (string) $employee->id),
        $companyReference,
        'Coverage Employee',
        true,
        new DateTimeImmutable,
        new DateTimeImmutable,
    );

    expect($coverage->summarize((string) $company->id, [$subject], now())->value)->toBe(0);

    $score->update(['requirement_version' => 2]);

    expect($coverage->summarize((string) $company->id, [$subject], now())->value)->toBe(100);
});
