<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\CriticalClassification;
use App\Domains\People\Skills\Enums\ReminderRule;
use App\Domains\People\Skills\Enums\SkillScope;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\SkillAssessment;
use App\Domains\People\Skills\Services\ReminderRules;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * Self-contained: every helper is prefixed reminder and lives here.
 *
 * This lane lists what is due. Nothing here sends anything, and no test asserts
 * that anything was sent, because nothing should be.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/**
 * Two companies in one tenant, each with an employee and its own skill.
 *
 * The scores are built through a real assessment rather than invented ids: the
 * score row carries foreign keys to a skill and the assessment it came from,
 * and a fixture that fakes those would be testing a shape the application
 * cannot produce.
 *
 * @return array{tenantId: int, companyId: int, employeeId: int, otherCompanyId: int, otherEmployeeId: int}
 */
function reminderFixture(string $name): array
{
    $tenant = createTenant(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company)->id,
        'employeeId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee)->id,
        'otherCompanyId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Company)->id,
        'otherEmployeeId' => (int) NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee)->id,
    ];
}

function reminderSkill(int $companyId, string $code): int
{
    $category = app(SkillCatalogStore::class)->defineCategory($companyId, 'ops-'.$code, 'Operations '.$code);

    return (int) app(SkillCatalogStore::class)->defineSkill($companyId, new SkillDraft(
        code: $code,
        name: 'Skill '.$code,
        definition: 'Does the thing.',
        categoryId: (int) $category->id,
        scope: SkillScope::Shared,
        criticalClassification: CriticalClassification::Safety,
        evidenceGuide: 'Observed.',
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
        defaultReassessmentMonths: 12,
    ))->id;
}

function reminderScore(array $f, int $companyId, int $employeeId, array $overrides = []): EmployeeSkillScore
{
    static $seq = 0;
    $seq++;
    $skillId = reminderSkill($companyId, 'reminder.skill.'.$seq);

    // A draft assessment is the lightest real row that satisfies the score's
    // source_assessment_id, and draft inserts are the ones the workflow guard
    // permits without workflow authority.
    $assessment = SkillAssessment::query()->create([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $companyId,
        'employee_entity_id' => $employeeId,
        'skill_id' => $skillId,
        'requirement_reference' => 'reminder.ops',
        'requirement_version' => 2,
        'required_level' => 4,
        'criticality' => 'critical',
        'mandatory_gate' => true,
        'assessed_level' => 2,
        'gap' => 2,
        'method' => 'direct_observation',
        'cycle' => 'annual',
        'status' => 'draft',
        'evidence' => 'Observed once.',
        'assessed_at' => now()->subYear(),
        'assessor_user_id' => 9,
    ]);

    return EmployeeSkillScore::query()->create(array_merge([
        'tenant_id' => $f['tenantId'],
        'company_entity_id' => $companyId,
        'employee_entity_id' => $employeeId,
        'skill_id' => $skillId,
        'source_assessment_id' => (int) $assessment->id,
        'requirement_reference' => 'reminder.ops',
        'requirement_version' => 2,
        'required_level' => 4,
        'current_level' => 2,
        'gap' => 2,
        'mandatory_gate' => true,
        'criticality' => 'critical',
        'assessed_at' => now()->subYear(),
        'next_assessment_due' => null,
        'valid_until' => null,
    ], $overrides));
}

test('a reassessment past its due date is listed as overdue', function (): void {
    $f = reminderFixture('Reminder Overdue Tenant');
    reminderScore($f, $f['companyId'], $f['employeeId'], ['next_assessment_due' => now()->subDays(3)->toDateString()]);

    $due = app(ReminderRules::class)->due($f['companyId'], now()->toImmutable());

    expect($due)->toHaveCount(1)
        ->and($due[0]->rule)->toBe(ReminderRule::OverdueReassessment)
        ->and($due[0]->employeeEntityId)->toBe($f['employeeId'])
        ->and($due[0]->requirementVersion)->toBe(2);
});

test('a reassessment not yet due is not listed', function (): void {
    $f = reminderFixture('Reminder Not Yet Tenant');
    reminderScore($f, $f['companyId'], $f['employeeId'], ['next_assessment_due' => now()->addDays(30)->toDateString()]);

    expect(app(ReminderRules::class)->due($f['companyId'], now()->toImmutable()))->toBe([]);
});

test('a certificate expiring inside the window is listed', function (): void {
    $f = reminderFixture('Reminder Expiring Tenant');
    reminderScore($f, $f['companyId'], $f['employeeId'], ['valid_until' => now()->addDays(10)->toDateString()]);

    $due = app(ReminderRules::class)->due($f['companyId'], now()->toImmutable(), expiringWithinDays: 30);

    expect($due)->toHaveCount(1)
        ->and($due[0]->rule)->toBe(ReminderRule::ExpiringCertificate);
});

test('a certificate expiring beyond the window is not listed', function (): void {
    $f = reminderFixture('Reminder Far Off Tenant');
    reminderScore($f, $f['companyId'], $f['employeeId'], ['valid_until' => now()->addDays(60)->toDateString()]);

    expect(app(ReminderRules::class)->due($f['companyId'], now()->toImmutable(), expiringWithinDays: 30))->toBe([]);
});

test('a certificate that has already lapsed is listed rather than ignored', function (): void {
    $f = reminderFixture('Reminder Lapsed Tenant');
    reminderScore($f, $f['companyId'], $f['employeeId'], ['valid_until' => now()->subDays(5)->toDateString()]);

    // Already expired is not "no longer expiring". Dropping it would quietly
    // stop reminding about exactly the certificates most in need of it.
    $due = app(ReminderRules::class)->due($f['companyId'], now()->toImmutable(), expiringWithinDays: 30);

    expect($due)->toHaveCount(1)
        ->and($due[0]->rule)->toBe(ReminderRule::ExpiringCertificate);
});

test('another company reassessment never appears in this company list', function (): void {
    $f = reminderFixture('Reminder Isolation Tenant');
    reminderScore($f, $f['otherCompanyId'], $f['otherEmployeeId'], ['next_assessment_due' => now()->subDays(3)->toDateString()]);

    expect(app(ReminderRules::class)->due($f['companyId'], now()->toImmutable()))->toBe([]);
});

test('another company certificate never appears in this company list', function (): void {
    $f = reminderFixture('Reminder Certificate Isolation Tenant');
    reminderScore($f, $f['otherCompanyId'], $f['otherEmployeeId'], ['valid_until' => now()->addDays(10)->toDateString()]);

    // Same tenant, different company. A reminder naming somebody else's
    // employee is a disclosure, not a nuisance.
    expect(app(ReminderRules::class)->due($f['companyId'], now()->toImmutable(), expiringWithinDays: 30))->toBe([]);
});

test('one score that is both overdue and expiring produces one reminder per reason', function (): void {
    $f = reminderFixture('Reminder Both Tenant');
    reminderScore($f, $f['companyId'], $f['employeeId'], [
        'next_assessment_due' => now()->subDays(3)->toDateString(),
        'valid_until' => now()->addDays(10)->toDateString(),
    ]);

    $due = app(ReminderRules::class)->due($f['companyId'], now()->toImmutable(), expiringWithinDays: 30);

    // Two different things are true about this person and each has its own
    // remedy. Collapsing them would hide one behind the other.
    expect(collect($due)->pluck('rule')->all())
        ->toBe([ReminderRule::OverdueReassessment, ReminderRule::ExpiringCertificate]);
});

test('the command reports counts and sends nothing', function (): void {
    $f = reminderFixture('Reminder Command Tenant');
    reminderScore($f, $f['companyId'], $f['employeeId'], ['next_assessment_due' => now()->subDays(3)->toDateString()]);
    Mail::fake();
    Notification::fake();

    $this->artisan('people:reminders-due', ['--tenant' => $f['tenantId'], '--company' => $f['companyId']])
        ->expectsOutputToContain('overdue_reassessment: 1')
        ->assertExitCode(0);

    // The issue says no sending yet, so the absence is the deliverable and it
    // is asserted rather than assumed.
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});
