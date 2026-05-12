<?php

namespace App\Modules\People\Employees\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Payroll\Models\PayrollEmployeeStatutoryProfile;
use Illuminate\Database\Eloquent\Builder;

class EmployeePayrollReadinessService
{
    public const STATE_READY = 'ready';

    public const STATE_BLOCKED = 'blocked';

    /**
     * @return array<string, string>
     */
    public static function blockerLabels(): array
    {
        return [
            'missing_work_profile' => 'Missing work profile',
            'missing_pay_basis' => 'Missing pay basis',
            'missing_bank_details' => 'Missing bank details',
            'missing_statutory_profile' => 'Missing statutory profile',
            'statutory_profile_has_issues' => 'Statutory profile has issues',
            'inactive_employment' => 'Employment is not active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summarize(Employee $employee): array
    {
        $employee->loadMissing([
            'workProfile.costCenter',
            'workProfile.organizationUnit',
            'workProfile.employmentGroup',
            'workProfile.jobTitle',
            'workProfile.workforceClass',
            'workProfile.jobGrade',
            'workProfile.workCalendar',
            'portalAccess.user',
        ]);

        $workProfile = $employee->workProfile;
        $portalAccess = $employee->portalAccess;
        $bank = $this->bankDetails($employee);
        $statutoryProfile = $this->latestStatutoryProfile($employee);

        $blockers = [];

        if ($workProfile === null) {
            $blockers[] = $this->blocker('missing_work_profile', 'Employee work profile has not been configured.');
        } else {
            if ($this->stringValue($workProfile->pay_rate_type) === '') {
                $blockers[] = $this->blocker('missing_pay_basis', 'Pay basis is required before payroll processing.');
            }
        }

        if ($bank['bank_name'] === '' || $bank['bank_account_number'] === '') {
            $blockers[] = $this->blocker('missing_bank_details', 'Payroll bank metadata is incomplete.');
        }

        if ($statutoryProfile === null) {
            $blockers[] = $this->blocker('missing_statutory_profile', 'No employee statutory profile is available.');
        } elseif (($statutoryProfile->validation_messages ?? []) !== []) {
            $blockers[] = $this->blocker(
                'statutory_profile_has_issues',
                'Current statutory profile still has validation messages.',
            );
        }

        if ($employee->status !== 'active') {
            $blockers[] = $this->blocker('inactive_employment', 'Employee must be active for payroll participation.');
        }

        return [
            'state' => $blockers === [] ? self::STATE_READY : self::STATE_BLOCKED,
            'blockers' => $blockers,
            'work_profile' => [
                'present' => $workProfile !== null,
                'pay_basis' => $this->stringValue($workProfile?->pay_rate_type),
                'hired_on' => $workProfile?->hired_on?->toDateString(),
                'resigned_on' => $workProfile?->resigned_on?->toDateString(),
            ],
            'bank' => $bank,
            'portal_access' => [
                'status' => $portalAccess?->status,
                'login_identifier' => $this->stringValue($portalAccess?->login_identifier),
                'email' => $this->stringValue($portalAccess?->email),
                'last_invited_at' => $portalAccess?->last_invited_at?->toIso8601String(),
            ],
            'statutory_profile' => [
                'present' => $statutoryProfile !== null,
                'country_iso' => $statutoryProfile?->country_iso,
                'effective_from' => $statutoryProfile?->effective_from?->toDateString(),
                'effective_to' => $statutoryProfile?->effective_to?->toDateString(),
                'validation_messages' => $statutoryProfile?->validation_messages ?? [],
            ],
        ];
    }

    public function applyStateFilter(Builder $query, string $state): void
    {
        if ($state === self::STATE_READY) {
            $query
                ->whereNotNull('employee_work_profiles.id')
                ->whereNotNull('employee_work_profiles.pay_rate_type')
                ->where('employee_work_profiles.pay_rate_type', '!=', '')
                ->where('employees.status', 'active')
                ->whereNotNull('employees.metadata->payroll_bank->bank_name')
                ->where('employees.metadata->payroll_bank->bank_name', '!=', '')
                ->whereNotNull('employees.metadata->payroll_bank->bank_account_number')
                ->where('employees.metadata->payroll_bank->bank_account_number', '!=', '')
                ->whereHas('statutoryProfiles', function (Builder $statutoryQuery): void {
                    $statutoryQuery->where(function (Builder $validationQuery): void {
                        $validationQuery->whereNull('validation_messages')
                            ->orWhereJsonLength('validation_messages', 0);
                    });
                });

            return;
        }

        if ($state !== self::STATE_BLOCKED) {
            return;
        }

        $query->where(function (Builder $blockingQuery): void {
            foreach (array_keys(self::blockerLabels()) as $index => $blocker) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $blockingQuery->{$method}(function (Builder $query) use ($blocker): void {
                    $this->applyBlockerFilter($query, $blocker);
                });
            }
        });
    }

    public function applyBlockerFilter(Builder $query, string $blocker): void
    {
        match ($blocker) {
            'missing_work_profile' => $query->whereNull('employee_work_profiles.id'),
            'missing_pay_basis' => $query->where(function (Builder $payBasisQuery): void {
                $payBasisQuery->whereNull('employee_work_profiles.id')
                    ->orWhereNull('employee_work_profiles.pay_rate_type')
                    ->orWhere('employee_work_profiles.pay_rate_type', '');
            }),
            'missing_bank_details' => $query->where(function (Builder $bankQuery): void {
                $bankQuery->whereNull('employees.metadata->payroll_bank->bank_name')
                    ->orWhere('employees.metadata->payroll_bank->bank_name', '')
                    ->orWhereNull('employees.metadata->payroll_bank->bank_account_number')
                    ->orWhere('employees.metadata->payroll_bank->bank_account_number', '');
            }),
            'missing_statutory_profile' => $query->whereDoesntHave('statutoryProfiles'),
            'statutory_profile_has_issues' => $query->whereHas('statutoryProfiles', function (Builder $statutoryQuery): void {
                $statutoryQuery->whereJsonLength('validation_messages', '>', 0);
            }),
            'inactive_employment' => $query->where('employees.status', '!=', 'active'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @return array{bank_name: string, bank_account_number: string}
     */
    private function bankDetails(Employee $employee): array
    {
        $bank = $employee->metadata['payroll_bank'] ?? [];

        if (! is_array($bank)) {
            $bank = [];
        }

        return [
            'bank_name' => $this->stringValue($bank['bank_name'] ?? null),
            'bank_account_number' => $this->stringValue($bank['bank_account_number'] ?? null),
        ];
    }

    private function latestStatutoryProfile(Employee $employee): ?PayrollEmployeeStatutoryProfile
    {
        if ($employee->relationLoaded('statutoryProfiles')) {
            return $employee->statutoryProfiles
                ->sortByDesc(fn (PayrollEmployeeStatutoryProfile $profile): string => (string) $profile->effective_from)
                ->first();
        }

        return $employee->statutoryProfiles()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{code: string, label: string, detail: string}
     */
    private function blocker(string $code, string $detail): array
    {
        return [
            'code' => $code,
            'label' => self::blockerLabels()[$code] ?? $code,
            'detail' => $detail,
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
