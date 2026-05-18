<?php

namespace App\Modules\People\Employees\Services;

use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Computes an employee's readiness for payroll processing.
 *
 * Reads from the Payroll plugin's `people_payroll_employee_statutory_profiles`
 * table when Payroll is installed; degrades to "missing statutory profile"
 * for every employee when the plugin is absent. The boundary is kept by
 * accessing the table via the DB facade rather than importing the Payroll
 * Eloquent model — same pattern used by other source-side helpers that
 * need read-only access to plugin tables.
 */
class EmployeePayrollReadinessService
{
    public const STATE_READY = 'ready';

    public const STATE_BLOCKED = 'blocked';

    private const STATUTORY_PROFILE_TABLE = 'people_payroll_employee_statutory_profiles';

    private const BANK_NAME_METADATA_PATH = 'employees.metadata->payroll_bank->bank_name';

    private const BANK_ACCOUNT_NUMBER_METADATA_PATH = 'employees.metadata->payroll_bank->bank_account_number';

    private const NO_ROWS_CONDITION = '1 = 0';

    private const STATUTORY_PROFILE_EMPLOYEE_ID_COLUMN = self::STATUTORY_PROFILE_TABLE.'.employee_id';

    private const STATUTORY_PROFILE_VALIDATION_MESSAGES_COLUMN = self::STATUTORY_PROFILE_TABLE.'.validation_messages';

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
        $validationMessages = $this->normaliseValidationMessages($statutoryProfile);

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
        } elseif ($validationMessages !== []) {
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
                'effective_from' => $this->dateString($statutoryProfile?->effective_from),
                'effective_to' => $this->dateString($statutoryProfile?->effective_to),
                'validation_messages' => $validationMessages,
            ],
        ];
    }

    public function applyStateFilter(Builder $query, string $state): void
    {
        $statutoryTableExists = $this->statutoryProfileTableExists();

        if ($state === self::STATE_READY) {
            $query
                ->whereNotNull('people_employee_work_profiles.id')
                ->whereNotNull('people_employee_work_profiles.pay_rate_type')
                ->where('people_employee_work_profiles.pay_rate_type', '!=', '')
                ->where('employees.status', 'active')
                ->whereNotNull(self::BANK_NAME_METADATA_PATH)
                ->where(self::BANK_NAME_METADATA_PATH, '!=', '')
                ->whereNotNull(self::BANK_ACCOUNT_NUMBER_METADATA_PATH)
                ->where(self::BANK_ACCOUNT_NUMBER_METADATA_PATH, '!=', '');

            if (! $statutoryTableExists) {
                // No payroll plugin installed → no employee is payroll-ready.
                $query->whereRaw(self::NO_ROWS_CONDITION);

                return;
            }

            $query->whereExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from(self::STATUTORY_PROFILE_TABLE)
                    ->whereColumn(self::STATUTORY_PROFILE_EMPLOYEE_ID_COLUMN, 'employees.id')
                    ->where(function ($validationQuery): void {
                        $validationQuery->whereNull(self::STATUTORY_PROFILE_VALIDATION_MESSAGES_COLUMN)
                            ->orWhereJsonLength(self::STATUTORY_PROFILE_VALIDATION_MESSAGES_COLUMN, 0);
                    });
            });

            return;
        }

        if ($state !== self::STATE_BLOCKED) {
            return;
        }

        $query->where(function (Builder $blockingQuery) use ($statutoryTableExists): void {
            foreach (array_keys(self::blockerLabels()) as $index => $blocker) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $blockingQuery->{$method}(function (Builder $query) use ($blocker, $statutoryTableExists): void {
                    $this->applyBlockerFilter($query, $blocker, $statutoryTableExists);
                });
            }
        });
    }

    public function applyBlockerFilter(Builder $query, string $blocker, ?bool $statutoryTableExists = null): void
    {
        $statutoryTableExists ??= $this->statutoryProfileTableExists();

        match ($blocker) {
            'missing_work_profile' => $query->whereNull('people_employee_work_profiles.id'),
            'missing_pay_basis' => $query->where(function (Builder $payBasisQuery): void {
                $payBasisQuery->whereNull('people_employee_work_profiles.id')
                    ->orWhereNull('people_employee_work_profiles.pay_rate_type')
                    ->orWhere('people_employee_work_profiles.pay_rate_type', '');
            }),
            'missing_bank_details' => $query->where(function (Builder $bankQuery): void {
                $bankQuery->whereNull(self::BANK_NAME_METADATA_PATH)
                    ->orWhere(self::BANK_NAME_METADATA_PATH, '')
                    ->orWhereNull(self::BANK_ACCOUNT_NUMBER_METADATA_PATH)
                    ->orWhere(self::BANK_ACCOUNT_NUMBER_METADATA_PATH, '');
            }),
            'missing_statutory_profile' => $statutoryTableExists
                ? $query->whereNotExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from(self::STATUTORY_PROFILE_TABLE)
                        ->whereColumn(self::STATUTORY_PROFILE_EMPLOYEE_ID_COLUMN, 'employees.id');
                })
                : $query->whereRaw('1 = 1'),
            'statutory_profile_has_issues' => $statutoryTableExists
                ? $query->whereExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from(self::STATUTORY_PROFILE_TABLE)
                        ->whereColumn(self::STATUTORY_PROFILE_EMPLOYEE_ID_COLUMN, 'employees.id')
                        ->whereJsonLength(self::STATUTORY_PROFILE_VALIDATION_MESSAGES_COLUMN, '>', 0);
                })
                : $query->whereRaw(self::NO_ROWS_CONDITION),
            'inactive_employment' => $query->where('employees.status', '!=', 'active'),
            default => $query->whereRaw(self::NO_ROWS_CONDITION),
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

    private function latestStatutoryProfile(Employee $employee): ?stdClass
    {
        if (! $this->statutoryProfileTableExists()) {
            return null;
        }

        $row = DB::table(self::STATUTORY_PROFILE_TABLE)
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return $row;
    }

    private function statutoryProfileTableExists(): bool
    {
        return Schema::hasTable(self::STATUTORY_PROFILE_TABLE);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function normaliseValidationMessages(?stdClass $statutoryProfile): array
    {
        if ($statutoryProfile === null) {
            return [];
        }

        $raw = $statutoryProfile->validation_messages ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            return substr($value, 0, 10);
        }

        return null;
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
