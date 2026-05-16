<?php
use App\Modules\People\Payroll\Livewire\AttendanceAllowanceMapping;
use App\Modules\People\Payroll\Livewire\Index;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function (): void {
    Route::get('people/payroll', Index::class)
        ->middleware('authz:people.payroll.view')
        ->name('people.payroll.index');

    Route::get('people/payroll/attendance-allowance-mapping', AttendanceAllowanceMapping::class)
        ->middleware('authz:people.payroll.manage')
        ->name('people.payroll.attendance-allowance-mapping');
});
