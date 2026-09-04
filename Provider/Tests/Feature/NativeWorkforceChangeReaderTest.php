<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;
use App\Domains\People\Provider\Data\WorkforceChangeRequest;
use App\Domains\People\Provider\Data\WorkforceUpsert;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceChangeCursorException;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use Illuminate\Support\Carbon;

/*
 * Self-contained: every helper is prefixed changeReader and lives here. The
 * only outside helper is the platform's createTenantWithCompany(). Time is
 * driven through Carbon's test clock, which is what now() and every
 * updated_at column read.
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
    Carbon::setTestNow();
});

function changeReaderAt(string $time): void
{
    Carbon::setTestNow(Carbon::parse($time, 'UTC'));
}

/**
 * A tenant with one department, a head, a worker reporting to the head with a
 * confirmed portal user, and an untouched bystander — all created at T-1 so a
 * bootstrap at T0 starts strictly after every row's updated_at.
 *
 * @return array{tenant: object, company: object, department: object, head: Employee, worker: Employee, bystander: Employee, workerUser: User}
 */
function changeReaderFixture(string $name): array
{
    changeReaderAt('2026-09-01T07:00:00');
    [$tenant, $company] = createTenantWithCompany(['name' => $name], ['name' => $name.' Co', 'code' => strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 8))]);
    $type = DepartmentType::query()->create(['code' => 'cr-'.strtolower(preg_replace('/[^a-z]/i', '', $name)), 'name' => 'Change Ops', 'category' => 'operational', 'is_active' => true]);
    $department = Department::query()->create(['company_id' => $company->id, 'department_type_id' => $type->id, 'status' => 'active']);
    $head = Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'employee_number' => 'CR-HEAD', 'full_name' => 'Change Head', 'employee_type' => 'full_time']);
    $department->update(['head_id' => $head->id]);
    $worker = Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'supervisor_id' => $head->id, 'employee_number' => 'CR-WORK', 'full_name' => 'Change Worker', 'short_name' => null, 'employee_type' => 'full_time', 'email' => 'worker@change.test']);
    $workerUser = User::factory()->create(['company_id' => $company->id, 'employee_id' => $worker->id]);
    EmployeePortalAccess::query()->create(['employee_id' => $worker->id, 'user_id' => $workerUser->id, 'display_name' => $worker->displayName(), 'status' => EmployeePortalAccess::STATUS_ACTIVE]);
    $bystander = Employee::factory()->create(['company_id' => $company->id, 'department_id' => $department->id, 'employee_number' => 'CR-STILL', 'full_name' => 'Change Bystander', 'employee_type' => 'full_time']);
    // No department, no portal access: the only thing that can replay this row is its own updated_at.
    $loner = Employee::factory()->create(['company_id' => $company->id, 'department_id' => null, 'employee_number' => 'CR-LONE', 'full_name' => 'Change Loner', 'short_name' => null, 'employee_type' => 'full_time']);

    return compact('tenant', 'company', 'department', 'head', 'worker', 'bystander', 'loner', 'workerUser');
}

/** Bootstrap the tenant at T0 and return the resume cursor. */
function changeReaderBootstrap(int $tenantId): string
{
    changeReaderAt('2026-09-01T08:00:00');
    app(TenantContext::class)->set($tenantId);
    $page = app(ReadsWorkforceBootstrap::class)->read(new WorkforceBootstrapRequest);
    expect($page->complete)->toBeTrue();

    return $page->resumeCursor;
}

/** @return array<string, array<string, mixed>> external id => serialized change, keyed "type:resource:id" */
function changeReaderIndex(array $pages): array
{
    $index = [];
    foreach ($pages as $page) {
        foreach ($page->changes as $change) {
            $json = $change->jsonSerialize();
            $id = $change instanceof WorkforceUpsert ? $json['record']['reference']['external_id'] : $json['reference']['external_id'];
            $index["{$json['type']}:{$json['resource_type']}:{$id}"] = $json;
        }
    }

    return $index;
}

test('an incremental read fails closed without a tenant context and refuses a cursor from another tenant or one that was tampered with', function (): void {
    $fx = changeReaderFixture('Change Cursor Tenant');
    [$otherTenant] = createTenantWithCompany(['name' => 'Change Cursor Other Tenant']);
    $resume = changeReaderBootstrap((int) $fx['tenant']->id);
    $reader = app(ReadsWorkforceChanges::class);

    app(TenantContext::class)->clear();
    expect(fn () => $reader->read(new WorkforceChangeRequest($resume)))->toThrow(TenantContextMissingException::class);

    app(TenantContext::class)->set((int) $otherTenant->id);
    expect(fn () => $reader->read(new WorkforceChangeRequest($resume)))
        ->toThrow(InvalidWorkforceChangeCursorException::class, 'does not belong to the current tenant');

    app(TenantContext::class)->set((int) $fx['tenant']->id);
    $offset = intdiv(strlen($resume), 2);
    $tampered = substr_replace($resume, $resume[$offset] === 'A' ? 'B' : 'A', $offset, 1);
    expect(fn () => $reader->read(new WorkforceChangeRequest($tampered)))
        ->toThrow(InvalidWorkforceChangeCursorException::class, 'cursor is invalid');
});

test('an incremental read replays exactly what changed since the bootstrap, including changes that never touched the employee row', function (): void {
    $fx = changeReaderFixture('Change Replay Tenant');
    $resume = changeReaderBootstrap((int) $fx['tenant']->id);
    $tenantId = (int) $fx['tenant']->id;

    // Edited at exactly the bootstrap instant: the window the bootstrap cannot
    // freeze. The loner has no department and no portal access, so nothing but
    // this inclusive comparison can bring the row back.
    changeReaderAt('2026-09-01T08:00:00');
    $fx['loner']->update(['full_name' => 'Change Loner Edited At Start']);
    $fx['worker']->update(['full_name' => 'Change Worker Edited At Start']);

    changeReaderAt('2026-09-01T09:00:00');
    $fx['head']->update(['status' => 'inactive', 'employment_end' => '2026-09-01']);
    $hire = Employee::factory()->create(['company_id' => $fx['company']->id, 'department_id' => $fx['department']->id, 'supervisor_id' => $fx['worker']->id, 'employee_number' => 'CR-NEW', 'full_name' => 'Change New Hire', 'employee_type' => 'full_time']);
    // The department's head changes; the bystander's own row does not.
    $fx['department']->update(['head_id' => $fx['worker']->id]);
    // A second, non-primary company is soft-deleted; a third only renamed.
    changeReaderAt('2026-09-01T07:30:00');
    $doomed = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Doomed Co', 'code' => 'DOOMED', 'status' => 'active']);
    $renamed = Company::factory()->create(['tenant_id' => $tenantId, 'name' => 'Old Name Co', 'code' => 'RENAMED', 'status' => 'active']);
    changeReaderAt('2026-09-01T09:30:00');
    $doomed->delete();
    $renamed->update(['name' => 'New Name Co']);

    changeReaderAt('2026-09-01T10:00:00');
    app(TenantContext::class)->set($tenantId);
    $reader = app(ReadsWorkforceChanges::class);
    $page = $reader->read(new WorkforceChangeRequest($resume));
    $index = changeReaderIndex([$page]);

    expect($page->complete)->toBeTrue()
        ->and($page->resumeCursor)->not->toBeNull()
        ->and($page->since->format('Y-m-d\TH:i'))->toBe('2026-09-01T08:00')
        ->and($page->asOf->format('Y-m-d\TH:i'))->toBe('2026-09-01T10:00')
        ->and(array_keys($index))->toEqualCanonicalizing([
            'upsert:company:'.$renamed->id,
            'deactivation:company:'.$doomed->id,
            'upsert:organization_unit:'.$fx['department']->id,
            'upsert:employee:'.$fx['head']->id,
            'upsert:employee:'.$fx['worker']->id,
            'upsert:employee:'.$fx['bystander']->id,
            'upsert:employee:'.$hire->id,
            'upsert:employee:'.$fx['loner']->id,
        ])
        ->and($index['upsert:employee:'.$fx['loner']->id]['record']['display_name'])->toBe('Change Loner Edited At Start')
        ->and($index['upsert:company:'.$renamed->id]['record']['name'])->toBe('New Name Co')
        ->and($index['deactivation:company:'.$doomed->id]['occurred_at'])->toStartWith('2026-09-01T09:30')
        ->and($index['upsert:employee:'.$fx['head']->id]['record']['active'])->toBeFalse()
        ->and($index['upsert:employee:'.$fx['worker']->id]['record']['display_name'])->toBe('Change Worker Edited At Start')
        ->and($index['upsert:employee:'.$fx['worker']->id]['record']['user_reference']['external_id'])->toBe((string) $fx['workerUser']->id)
        // The bystander is replayed only because the department head changed, and carries the new head.
        ->and($index['upsert:employee:'.$fx['bystander']->id]['record']['department_head_reference']['external_id'])->toBe((string) $fx['worker']->id)
        ->and($index['upsert:employee:'.$hire->id]['record']['manager_reference']['external_id'])->toBe((string) $fx['worker']->id)
        ->and($page->jsonSerialize()['provider_id'])->toBe('blb-people');

    // Nothing changed since this read: the next read from its resume cursor is empty and moves the watermark.
    changeReaderAt('2026-09-01T11:00:00');
    $quiet = $reader->read(new WorkforceChangeRequest($page->resumeCursor));
    expect($quiet->changes)->toBe([])
        ->and($quiet->complete)->toBeTrue()
        ->and($quiet->since->format('H:i'))->toBe('10:00')
        ->and($quiet->asOf->format('H:i'))->toBe('11:00');
});

test('a revoked portal access is replayed as a positive revocation even though the employee row is untouched', function (): void {
    $fx = changeReaderFixture('Change Revoke Tenant');
    $resume = changeReaderBootstrap((int) $fx['tenant']->id);

    changeReaderAt('2026-09-01T09:00:00');
    EmployeePortalAccess::query()->where('employee_id', $fx['worker']->id)->update(['status' => EmployeePortalAccess::STATUS_REVOKED, 'revoked_at' => now(), 'updated_at' => now()]);

    changeReaderAt('2026-09-01T10:00:00');
    app(TenantContext::class)->set((int) $fx['tenant']->id);
    $index = changeReaderIndex([app(ReadsWorkforceChanges::class)->read(new WorkforceChangeRequest($resume))]);

    expect(array_keys($index))->toBe(['upsert:employee:'.$fx['worker']->id])
        ->and($index['upsert:employee:'.$fx['worker']->id]['record']['user_reference'])->toBeNull()
        ->and($index['upsert:employee:'.$fx['worker']->id]['record']['user_reference_revoked'])->toBeTrue();
});

test('incremental pages walk employees under a watermark captured at the start, so a hire mid-read waits for the next read', function (): void {
    $fx = changeReaderFixture('Change Paging Tenant');
    $resume = changeReaderBootstrap((int) $fx['tenant']->id);
    $tenantId = (int) $fx['tenant']->id;

    changeReaderAt('2026-09-01T09:00:00');
    foreach (['head', 'worker', 'bystander'] as $key) {
        $fx[$key]->update(['short_name' => 'Paged '.$key]);
    }

    changeReaderAt('2026-09-01T10:00:00');
    app(TenantContext::class)->set($tenantId);
    $reader = app(ReadsWorkforceChanges::class);
    $first = $reader->read(new WorkforceChangeRequest($resume, limit: 1));

    expect($first->complete)->toBeFalse()
        ->and($first->nextPageCursor)->not->toBeNull()
        ->and($first->resumeCursor)->toBeNull();

    changeReaderAt('2026-09-01T10:05:00');
    $midRead = Employee::factory()->create(['company_id' => $fx['company']->id, 'department_id' => $fx['department']->id, 'employee_number' => 'CR-MID', 'full_name' => 'Change Mid Read', 'employee_type' => 'full_time']);

    $second = $reader->read(new WorkforceChangeRequest($resume, $first->nextPageCursor, 1));
    $third = $reader->read(new WorkforceChangeRequest($resume, $second->nextPageCursor, 1));
    $ids = array_map(static fn (string $key): string => substr($key, strrpos($key, ':') + 1), array_keys(changeReaderIndex([$first, $second, $third])));

    expect($ids)->toBe([(string) $fx['head']->id, (string) $fx['worker']->id, (string) $fx['bystander']->id])
        ->and($ids)->not->toContain((string) $midRead->id)
        ->and($second->asOf)->toEqual($first->asOf)
        ->and($third->complete)->toBeTrue()
        ->and($third->resumeCursor)->not->toBeNull();

    // The hire made during the read arrives with the next read from this read's resume cursor.
    changeReaderAt('2026-09-01T11:00:00');
    $next = changeReaderIndex([$reader->read(new WorkforceChangeRequest($third->resumeCursor))]);
    expect(array_keys($next))->toBe(['upsert:employee:'.$midRead->id]);

    // A page cursor is tenant-bound like the resume cursor.
    [$otherTenant] = createTenantWithCompany(['name' => 'Change Paging Other Tenant']);
    app(TenantContext::class)->set((int) $otherTenant->id);
    expect(fn () => $reader->read(new WorkforceChangeRequest($resume, $first->nextPageCursor, 1)))
        ->toThrow(InvalidWorkforceChangeCursorException::class, 'does not belong to the current tenant');
});

test('workforce changes are not exposed over HTTP before service authentication exists', function (): void {
    $this->getJson('/api/people/provider/v1/workforce/changes')->assertNotFound();
});
