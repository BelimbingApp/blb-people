<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Livewire\TeamGaps\Index;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Services\SkillAudienceAssignmentStore;
use Livewire\Livewire;

/**
 * 0007-a: an HOD reads the critical-skill gaps of their own direct reports,
 * with whether something already targets each gap. The subject set comes from
 * the acting user's department, never from the request, and the page is a read.
 *
 * Self-contained: helpers are prefixed gaps and live here.
 *
 * @return array<string, mixed>
 */
function gapsFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Team Gaps Tenant'], ['name' => 'Team Gaps Company', 'status' => 'active']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();

    $units = [];
    $departments = [];
    foreach ([['OPS', 'Operations'], ['FIN', 'Finance']] as [$code, $name]) {
        $units[$code] = PeopleReferenceEntry::query()->create([
            'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
            'code' => $code, 'name' => $name, 'status' => PeopleReferenceEntry::STATUS_ACTIVE,
        ]);
        $type = DepartmentType::query()->create([
            'code' => strtolower($code).'-gaps', 'name' => $name, 'category' => 'operational', 'is_active' => true,
        ]);
        $departments[$code] = Department::query()->create([
            'company_id' => $companyId, 'department_type_id' => $type->id, 'status' => 'active',
        ]);
    }

    $head = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $departments['OPS']->id,
        'full_name' => 'Operations Head', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    $departments['OPS']->update(['head_id' => $head->id]);
    EmployeeWorkProfile::query()->create(['employee_id' => $head->id, 'organization_unit_id' => $units['OPS']->id]);

    $report = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $departments['OPS']->id, 'supervisor_id' => $head->id,
        'full_name' => 'Direct Report', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $report->id, 'organization_unit_id' => $units['OPS']->id]);

    $peer = Employee::factory()->create([
        'company_id' => $companyId, 'department_id' => $departments['FIN']->id,
        'full_name' => 'Peer Department Employee', 'status' => 'active', 'employee_type' => 'full_time',
    ]);
    EmployeeWorkProfile::query()->create(['employee_id' => $peer->id, 'organization_unit_id' => $units['FIN']->id]);

    $hr = User::factory()->create(['company_id' => $companyId]);
    $hod = User::factory()->create(['company_id' => $companyId, 'employee_id' => $head->id]);
    $nobody = User::factory()->create(['company_id' => $companyId]);
    EmployeePortalAccess::query()->create([
        'employee_id' => $head->id, 'user_id' => $hod->id,
        'display_name' => 'Operations Head', 'status' => EmployeePortalAccess::STATUS_ACTIVE,
    ]);
    foreach ([[$hr, 'people_hr'], [$hod, 'people_hod']] as [$actor, $code]) {
        PrincipalRole::query()->create([
            'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
            'principal_id' => $actor->id,
            'role_id' => Role::query()->whereNull('company_id')->where('code', $code)->valueOrFail('id'),
        ]);
    }
    app(SkillAudienceAssignmentStore::class)->confirmActor($hr, $hod, $companyId, (int) $head->id, 'review:team-gaps-hod');

    return compact('tenantId', 'companyId', 'hod', 'nobody', 'head', 'report', 'peer');
}

function gapsScore(array $f, Employee $employee, int $required, int $current, RequirementCriticality $criticality = RequirementCriticality::Critical): EmployeeSkillScore
{
    return EmployeeSkillScore::query()->forCompany($f['tenantId'], $f['companyId'])->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $f['companyId'],
        'employee_entity_id' => $employee->id,
        'skill_id' => 4242,
        'requirement_reference' => 'fixture.safety',
        'requirement_version' => 2,
        'required_level' => $required,
        'current_level' => $current,
        'gap' => max($required - $current, 0),
        'mandatory_gate' => true,
        'criticality' => $criticality,
        'assessed_at' => now()->subDays(3),
    ]);
}

function gapsRows(array $f): array
{
    return Livewire::actingAs($f['hod'])->test(Index::class)->viewData('rows');
}

test('an HOD sees a direct report\'s critical gap and not a peer department\'s', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 2);
    gapsScore($f, $f['peer'], required: 4, current: 1);

    $names = collect(gapsRows($f))->pluck('employee')->all();

    expect($names)->toContain('Direct Report')
        ->and($names)->not->toContain('Peer Department Employee');
});

test('a skill at or above the required level is not a gap', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 3, current: 3);

    expect(gapsRows($f))->toBe([]);
});

test('only critical skills are listed', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 1, criticality: RequirementCriticality::Standard);

    // The page is the critical-skill view; a non-critical shortfall belongs to
    // the planning page, not the gap list.
    expect(gapsRows($f))->toBe([]);
});

test('a gap carries the latest assessment date and is marked unplanned by default', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 2);

    $row = collect(gapsRows($f))->firstWhere('employee', 'Direct Report');

    expect($row['required_level'])->toBe(4)
        ->and($row['current_level'])->toBe(2)
        ->and($row['planned'])->toBeFalse()
        ->and($row['assessed_at'])->not->toBeNull();
});

test('the page is refused without the team-gaps capability', function (): void {
    $f = gapsFixture();
    gapsScore($f, $f['report'], required: 4, current: 2);

    Livewire::actingAs($f['nobody'])->test(Index::class)->assertForbidden();
});
