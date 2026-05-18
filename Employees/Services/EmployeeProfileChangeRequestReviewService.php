<?php

namespace App\Modules\People\Employees\Services;

use App\Modules\Core\User\Models\User;
use App\Modules\People\Settings\Models\EmployeeProfileChangeRequest;
use App\Modules\People\Settings\Models\EmployeeWorkProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeProfileChangeRequestReviewService
{
    /**
     * @throws ValidationException
     */
    public function approve(EmployeeProfileChangeRequest $request, User $reviewer, ?string $note = null): EmployeeProfileChangeRequest
    {
        if ($request->status !== EmployeeProfileChangeRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted requests can be approved.',
            ]);
        }

        [$employeeChanges, $workProfileChanges] = $this->extractChanges($request);

        if ($employeeChanges === [] && $workProfileChanges === []) {
            throw ValidationException::withMessages([
                'requested_changes' => 'The request does not contain any supported employee or work profile changes.',
            ]);
        }

        DB::transaction(function () use ($request, $reviewer, $note, $employeeChanges, $workProfileChanges): void {
            if ($employeeChanges !== []) {
                $request->employee->fill($employeeChanges);
                $request->employee->save();
            }

            if ($workProfileChanges !== []) {
                EmployeeWorkProfile::query()->updateOrCreate(
                    ['employee_id' => $request->employee_id],
                    $workProfileChanges,
                );
            }

            $request->forceFill([
                'status' => EmployeeProfileChangeRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $this->reviewNotes('approved', $note),
            ])->save();
        });

        return $request->fresh(['employee.workProfile', 'requestedBy', 'reviewedBy']);
    }

    /**
     * @throws ValidationException
     */
    public function reject(EmployeeProfileChangeRequest $request, User $reviewer, ?string $note = null): EmployeeProfileChangeRequest
    {
        if ($request->status !== EmployeeProfileChangeRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted requests can be rejected.',
            ]);
        }

        $request->forceFill([
            'status' => EmployeeProfileChangeRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $this->reviewNotes('rejected', $note),
        ])->save();

        return $request->fresh(['employee.workProfile', 'requestedBy', 'reviewedBy']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function extractChanges(EmployeeProfileChangeRequest $request): array
    {
        $changes = $request->requested_changes;

        if (! is_array($changes)) {
            throw ValidationException::withMessages([
                'requested_changes' => 'Requested changes must be an object payload.',
            ]);
        }

        $allowedEmployee = [
            'full_name',
            'short_name',
            'designation',
            'email',
            'mobile_number',
            'employee_number',
            'status',
        ];

        $allowedWorkProfile = [
            'cost_center_id',
            'organization_unit_id',
            'employment_group_id',
            'job_title_id',
            'workforce_class_id',
            'job_grade_id',
            'work_calendar_id',
            'pay_rate_type',
            'hired_on',
            'resigned_on',
        ];

        $employeeChanges = $this->pickAllowedFields($changes, $allowedEmployee);
        $nestedEmployee = is_array($changes['employee'] ?? null) ? $changes['employee'] : [];
        $employeeChanges = array_merge($employeeChanges, $this->pickAllowedFields($nestedEmployee, $allowedEmployee));

        $nestedWorkProfile = is_array($changes['work_profile'] ?? null) ? $changes['work_profile'] : [];
        $workProfileChanges = $this->pickAllowedFields($nestedWorkProfile, $allowedWorkProfile);

        return [$employeeChanges, $workProfileChanges];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function pickAllowedFields(array $source, array $allowed): array
    {
        $picked = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $source)) {
                $picked[$field] = $source[$field];
            }
        }

        return $picked;
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewNotes(string $decision, ?string $note): array
    {
        $notes = [
            'decision' => $decision,
        ];

        $note = trim((string) $note);

        if ($note !== '') {
            $notes['note'] = $note;
        }

        return $notes;
    }
}
