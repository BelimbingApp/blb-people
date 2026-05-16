<?php

namespace App\Modules\People\Attendance\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Company\Models\Company;
use App\Modules\People\Attendance\Models\AttendanceDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

trait InteractsWithAttendanceScreen
{
    public function statusVariant(string $status): string
    {
        return match ($status) {
            AttendanceDay::STATUS_READY_FOR_REVIEW, AttendanceDay::STATUS_FINALIZED, AttendanceDay::STATUS_EXPORTED_TO_PAYROLL => 'success',
            AttendanceDay::STATUS_EXCEPTION_PENDING, AttendanceDay::STATUS_IN_PROGRESS => 'warning',
            AttendanceDay::STATUS_LOCKED => 'danger',
            default => 'info',
        };
    }

    public function statusLabel(?string $value): string
    {
        return __(ucfirst(str_replace('_', ' ', (string) $value)));
    }

    protected function companyId(): int
    {
        return auth()->user()?->company_id ?? Company::LICENSEE_ID;
    }

    protected function currentEmployeeId(): ?int
    {
        $id = auth()->user()?->employee_id;

        return $id === null ? null : (int) $id;
    }

    protected function authorizeAttendance(string $capability): void
    {
        app(AuthorizationService::class)->authorize(
            Actor::forUser(Auth::user()),
            $capability,
        );
    }

    protected function canAttendance(string $capability): bool
    {
        return app(AuthorizationService::class)
            ->can(Actor::forUser(Auth::user()), $capability)
            ->allowed;
    }

    protected function schemaReady(): bool
    {
        return Schema::hasTable('people_attendance_days');
    }

    protected function ensureSchemaReady(): bool
    {
        if ($this->schemaReady()) {
            return true;
        }

        session()->flash('error', __('Attendance database tables are not installed yet. Run the Attendance migration first.'));

        return false;
    }

    protected function blankToNull(mixed $value): mixed
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    protected function templateUploadContents(mixed $upload): string
    {
        if ($upload instanceof TemporaryUploadedFile) {
            return (string) $upload->get();
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function errorResult(string $code, string $message, string $path): array
    {
        return [
            'status' => 'error',
            'summary' => ['errors' => 1, 'warnings' => 0, 'info' => 0],
            'findings' => [[
                'severity' => 'error',
                'code' => $code,
                'message' => $message,
                'path' => $path,
            ]],
        ];
    }

    /**
     * Returns active payroll pay items as plain rows for dropdown rendering.
     *
     * Transitional — used by the PolicyGroups screen for the
     * `payroll_defaults` JSON fields (lateness, overtime). Plan 12 Phase 4
     * audits those fields and moves them out of the Attendance domain, at
     * which point this helper goes away.
     *
     * Goes through the DB facade rather than the PayrollPayItem Eloquent
     * model so this file does not import a Payroll class.
     *
     * @return Collection<int, object{code: string, name: string}>
     */
    protected function payrollPayItems(int $companyId): Collection
    {
        if (! Schema::hasTable('people_payroll_pay_items')) {
            return collect();
        }

        return DB::table('people_payroll_pay_items')
            ->select(['code', 'name'])
            ->where('status', 'active')
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->orderBy('code')
            ->get();
    }

    /**
     * Transitional — see `payrollPayItems`.
     *
     * @return array<int, mixed>
     */
    protected function payrollPayItemValidationRules(int $companyId): array
    {
        $rules = ['string', 'max:80'];

        if ($this->payrollPayItems($companyId)->isEmpty()) {
            return $rules;
        }

        $rules[] = Rule::exists('people_payroll_pay_items', 'code')
            ->where(function ($query) use ($companyId): void {
                $query->where('status', 'active')
                    ->where(function ($scope) use ($companyId): void {
                        $scope->where('company_id', $companyId)
                            ->orWhereNull('company_id');
                    });
            });

        return $rules;
    }
}
