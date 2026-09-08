<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Tests\Support\NativeWorkforceFixture;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\CutoverWorkflow;
use App\Domains\People\Training\Enums\CutoverWriter;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Exceptions\CutoverWriteRefusedException;
use App\Domains\People\Training\Exceptions\InvalidCutoverWindowException;
use App\Domains\People\Training\Models\TrainingCutoverWindow;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\CutoverWriteGuard;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 0015-f / #418: cutover single-writer guard.
 * Self-contained: helpers are prefixed cutW.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

beforeEach(function (): void {
    $this->withoutVite();
});

function cutWUser(Company $company, string $roleCode, string $name): User
{
    $user = User::factory()->create(['company_id' => $company->id, 'name' => $name]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value, 'principal_id' => $user->id,
        'role_id' => Role::query()->whereNull('company_id')->where('code', $roleCode)->sole()->id,
    ]);

    return $user;
}

/** @return array{tenantId: int, companyId: int, hr: User, company: Company, employee: object, department: object, subject: callable} */
function cutWFixture(string $label = 'CutW'): array
{
    $tenant = createTenant(['name' => $label.' Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    setupAuthzRoles();
    $company = Company::factory()->create(['tenant_id' => $tenantId, 'name' => $label.' Co', 'status' => 'active']);
    $employee = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::Employee, (int) $company->id);
    $department = NativeWorkforceFixture::create($tenantId, WorkforceResourceType::OrganizationUnit, (int) $company->id);
    $subject = fn ($record, WorkforceResourceType $type) => new WorkforceSubject(
        $tenantId, (int) $company->id, $type, (string) $record->id,
    );

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'company' => $company,
        'hr' => cutWUser($company, 'people_hr', $label.' HR'),
        'employee' => $employee,
        'department' => $department,
        'subject' => $subject,
    ];
}

function cutWRequestDraft(array $f): TrainingRequestDraft
{
    return new TrainingRequestDraft(
        requestor: $f['subject']($f['employee'], WorkforceResourceType::Employee),
        department: $f['subject']($f['department'], WorkforceResourceType::OrganizationUnit),
        needSource: TrainingNeedSource::NewMachineTechnology,
        need: 'Operators need safe control-system operation.',
        learningObjective: 'Operate the new control system safely.',
        expectedResult: 'Zero unsafe startup deviations.',
        priority: TrainingPriority::High,
        skillGapAssessmentId: null,
        requirementVersion: null,
    );
}

test('legacy window refuses TrainingRequestStore writes and leaves reads intact; system window allows writes', function (): void {
    $f = cutWFixture();
    $guard = app(CutoverWriteGuard::class);
    $start = new DateTimeImmutable('2026-09-01 00:00:00');
    $end = new DateTimeImmutable('2026-09-30 23:59:59');

    $guard->declare($f['hr'], $f['companyId'], CutoverWorkflow::TrainingRequests, CutoverWriter::Legacy, $start, $end, 'portal still live');

    expect(fn () => $guard->assertWritable($f['companyId'], CutoverWorkflow::TrainingRequests, new DateTimeImmutable('2026-09-15 12:00:00')))
        ->toThrow(CutoverWriteRefusedException::class, 'Training requests');

    // Reads unaffected: querying requests does not consult the guard.
    expect(TrainingRequest::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(0);

    $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::TrainingRequests,
        CutoverWriter::System,
        new DateTimeImmutable('2026-10-01 00:00:00'),
        null,
        'cutover complete',
    );
    $guard->assertWritable($f['companyId'], CutoverWorkflow::TrainingRequests, new DateTimeImmutable('2026-10-01 00:00:01'));
    expect(true)->toBeTrue();
});

test('window edges: one second inside refuses, one second outside allows', function (): void {
    $f = cutWFixture('CutWEdge');
    $guard = app(CutoverWriteGuard::class);
    $start = new DateTimeImmutable('2026-09-08 10:00:00');
    $end = new DateTimeImmutable('2026-09-08 11:00:00');
    $guard->declare($f['hr'], $f['companyId'], CutoverWorkflow::Effectiveness, CutoverWriter::Legacy, $start, $end, 'edge pin');

    expect(fn () => $guard->assertWritable($f['companyId'], CutoverWorkflow::Effectiveness, $end))
        ->toThrow(CutoverWriteRefusedException::class);
    $guard->assertWritable($f['companyId'], CutoverWorkflow::Effectiveness, $end->modify('+1 second'));
});

test('overlapping windows for the same workflow are refused at declaration', function (): void {
    $f = cutWFixture('CutWOverlap');
    $guard = app(CutoverWriteGuard::class);
    $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::Attendance,
        CutoverWriter::Legacy,
        new DateTimeImmutable('2026-09-01 00:00:00'),
        new DateTimeImmutable('2026-09-15 00:00:00'),
        'first',
    );

    expect(fn () => $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::Attendance,
        CutoverWriter::System,
        new DateTimeImmutable('2026-09-10 00:00:00'),
        new DateTimeImmutable('2026-09-20 00:00:00'),
        'clash',
    ))->toThrow(InvalidCutoverWindowException::class, 'overlapping');
});

test('cutover windows are append-only at the database', function (): void {
    $f = cutWFixture('CutWAppend');
    $guard = app(CutoverWriteGuard::class);
    $window = $guard->declare(
        $f['hr'],
        $f['companyId'],
        CutoverWorkflow::TrainingRequests,
        CutoverWriter::Legacy,
        new DateTimeImmutable('2026-09-01 00:00:00'),
        null,
        'append-only',
    );

    // Each attempt runs in its own DB::transaction() so the trigger's abort is
    // confined to a savepoint. Pest already wraps the test in a transaction,
    // and on PostgreSQL a raised exception poisons the whole one: the first
    // refusal would leave the second reporting 25P02 "current transaction is
    // aborted" instead of the trigger's message. SQLite does not care, which
    // is exactly why this only shows up in the postgres mirror.
    foreach ([
        fn () => DB::table('people_training_cutover_windows')->where('id', $window->id)->update(['reason' => 'nope']),
        fn () => DB::table('people_training_cutover_windows')->where('id', $window->id)->delete(),
    ] as $attempt) {
        expect(fn () => DB::transaction($attempt))
            ->toThrow(QueryException::class, 'append-only');
    }

    expect(TrainingCutoverWindow::query()->forCompany($f['tenantId'], $f['companyId'])->count())->toBe(1);
});

test('create is refused while the legacy portal is the authoritative writer', function (): void {
    $f = cutWFixture('CutWCreate');
    $companyId = $f['companyId'];

    app(CutoverWriteGuard::class)->declare(
        $f['hr'], $companyId,
        CutoverWorkflow::TrainingRequests, CutoverWriter::Legacy,
        new DateTimeImmutable('2026-01-01 00:00:00'), null, 'portal owns requests',
    );

    expect(fn () => app(TrainingRequestStore::class)->create($f['hr'], $companyId, cutWRequestDraft($f)))
        ->toThrow(CutoverWriteRefusedException::class);

    expect(TrainingRequest::query()->forCompany($f['tenantId'], $companyId)->count())->toBe(0);
});

test('a legacy window refuses every training-request write, not only create', function (): void {
    $f = cutWFixture('CutWCancel');
    $companyId = $f['companyId'];
    $store = app(TrainingRequestStore::class);

    // A request that already exists when the cutover window opens.
    $request = $store->create($f['hr'], $companyId, cutWRequestDraft($f));

    app(CutoverWriteGuard::class)->declare(
        $f['hr'], $companyId,
        CutoverWorkflow::TrainingRequests, CutoverWriter::Legacy,
        new DateTimeImmutable('2026-01-01 00:00:00'), null, 'portal owns requests',
    );

    // Control: create is guarded via scope().
    expect(fn () => $store->create($f['hr'], $companyId, cutWRequestDraft($f)))
        ->toThrow(CutoverWriteRefusedException::class);

    // cancel() writes a status transition through finish(), never through move().
    expect(fn () => $store->cancel($f['hr'], $companyId, (int) $request->id, 'withdrawn'))
        ->toThrow(CutoverWriteRefusedException::class);

    expect($request->fresh()->status->value)->toBe('draft');
});
