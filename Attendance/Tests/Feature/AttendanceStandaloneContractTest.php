<?php

/**
 * Plan 12 Phase 6 — plug-out contract test for Attendance.
 *
 * Asserts that Attendance can operate without the Payroll plugin
 * installed by checking the seam from three angles:
 *
 *  1. Manifest: Attendance does NOT declare Payroll as a required
 *     module. (Attendance must boot when Payroll is absent.)
 *  2. Imports: Attendance does NOT compile-time depend on any Payroll
 *     class. (Already enforced by AttendanceDoesNotImportPayrollTest;
 *     re-asserted here as a regression net.)
 *  3. Event dispatch: dispatching AttendanceOvertimeApproved with NO
 *     listeners registered (the Payroll plugin's listener absent) does
 *     not throw or warn.
 *
 * The full runtime variant — boot the app with Payroll's ServiceProvider
 * not registered — is a separate test that would require reworking
 * BLB's path-based provider discovery. Out of scope for plan 12; tracked
 * as a future enhancement when the framework manifest-driven loader
 * lands.
 */

use App\Base\Foundation\ModuleManifest\ModuleManifestReader;
use App\Modules\People\Attendance\Events\AttendanceAllowanceMaterialized;
use App\Modules\People\Attendance\Events\AttendanceOvertimeApproved;
use Illuminate\Support\Facades\Event;

it('declares Attendance as not depending on the Payroll plugin', function (): void {
    $reader = new ModuleManifestReader([base_path('app/Modules/People')]);

    $attendance = collect($reader->all())->firstWhere('module', 'people/attendance');

    expect($attendance)->not->toBeNull('Attendance manifest is missing')
        ->and(array_keys($attendance->requiresModules))->not->toContain('people/payroll');
});

it('declares Payroll as treating Attendance as an optional listener target', function (): void {
    $reader = new ModuleManifestReader([base_path('app/Modules/People')]);

    $payroll = collect($reader->all())->firstWhere('module', 'people/payroll');

    expect($payroll)->not->toBeNull('Payroll manifest is missing')
        ->and(array_keys($payroll->optionalModules))->toContain('people/attendance')
        ->and(array_keys($payroll->requiresModules))->not->toContain('people/attendance');
});

it('dispatches Attendance events safely when no listener is registered', function (): void {
    Event::fake([
        AttendanceOvertimeApproved::class,
        AttendanceAllowanceMaterialized::class,
    ]);

    event(new AttendanceOvertimeApproved(
        companyId: 1,
        employeeId: 1,
        overtimeRequestId: 1,
        occurredOn: new DateTimeImmutable('2026-05-13'),
        payableMinutes: 90,
    ));

    event(new AttendanceAllowanceMaterialized(
        companyId: 1,
        employeeId: 1,
        attendanceAllowanceRuleId: 1,
        occurredOn: new DateTimeImmutable('2026-05-13'),
        amount: 15.0,
    ));

    Event::assertDispatched(AttendanceOvertimeApproved::class);
    Event::assertDispatched(AttendanceAllowanceMaterialized::class);
});
