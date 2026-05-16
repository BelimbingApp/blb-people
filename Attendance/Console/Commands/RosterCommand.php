<?php

namespace App\Modules\People\Attendance\Console\Commands;

use App\Modules\People\Attendance\Models\AttendanceRosterAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:attendance:roster')]
class RosterCommand extends Command
{
    protected $signature = 'blb:attendance:roster
        {action : draft|validate|explain|publish-dry-run}
        {--company= : Company id}
        {--from= : Start date}
        {--to= : End date}';

    protected $description = 'Emit stable JSON for roster draft, validation, explanation, and publish dry-run operator workflows.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $inputErrors = $this->inputErrors($action);

        if ($inputErrors !== []) {
            return $this->writeResult([
                'action' => $action,
                'status' => 'error',
                'summary' => [
                    'assignments' => 0,
                    'drafts' => 0,
                    'published' => 0,
                ],
                'findings' => $inputErrors,
                'publish_preview' => [],
            ], self::FAILURE);
        }

        $query = AttendanceRosterAssignment::query()->with(['employee', 'shiftTemplate', 'policyGroup']);

        $query->where('company_id', (int) $this->option('company'));

        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $query->where(function ($scope) use ($from): void {
            $scope->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $from);
        });
        $query->whereDate('effective_from', '<=', $to);

        $assignments = $query->orderBy('effective_from')->limit(500)->get();
        $drafts = $assignments->where('publish_state', 'draft');
        $payload = [
            'action' => $action,
            'status' => 'ok',
            'summary' => [
                'assignments' => $assignments->count(),
                'drafts' => $drafts->count(),
                'published' => $assignments->where('publish_state', 'published')->count(),
            ],
            'findings' => $action === 'validate'
                ? $this->validationFindings($assignments)
                : [],
            'publish_preview' => $action === 'publish-dry-run'
                ? $drafts->map(fn (AttendanceRosterAssignment $assignment): array => [
                    'assignment_id' => $assignment->id,
                    'employee_id' => $assignment->employee_id,
                    'employee_number' => $assignment->employee?->employee_number,
                    'effective_from' => $assignment->effective_from?->toDateString(),
                    'effective_to' => $assignment->effective_to?->toDateString(),
                    'shift' => $assignment->shiftTemplate?->code,
                    'policy' => $assignment->policyGroup?->code,
                ])->values()->all()
                : [],
        ];

        return $this->writeResult($payload, self::SUCCESS);
    }

    /**
     * @return list<array<string, string>>
     */
    private function inputErrors(string $action): array
    {
        $findings = [];

        if (! in_array($action, ['draft', 'validate', 'explain', 'publish-dry-run'], true)) {
            $findings[] = [
                'severity' => 'error',
                'code' => 'unsupported_action',
                'message' => 'Unsupported roster action.',
                'path' => 'action',
            ];
        }

        if (filter_var($this->option('company'), FILTER_VALIDATE_INT) === false) {
            $findings[] = [
                'severity' => 'error',
                'code' => 'company_required',
                'message' => 'A valid --company ID is required.',
                'path' => 'company',
            ];
        }

        foreach (['from', 'to'] as $option) {
            $value = (string) ($this->option($option) ?? '');
            if (! $this->isDateString($value)) {
                $findings[] = [
                    'severity' => 'error',
                    'code' => $option.'_date_required',
                    'message' => 'A valid --'.$option.' date is required in YYYY-MM-DD format.',
                    'path' => $option,
                ];
            }
        }

        if ($this->isDateString((string) ($this->option('from') ?? ''))
            && $this->isDateString((string) ($this->option('to') ?? ''))
            && (string) $this->option('from') > (string) $this->option('to')) {
            $findings[] = [
                'severity' => 'error',
                'code' => 'invalid_date_range',
                'message' => '--to must be on or after --from.',
                'path' => 'to',
            ];
        }

        return $findings;
    }

    private function isDateString(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof \DateTimeImmutable) {
            return false;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return false;
        }

        return $date->format('Y-m-d') === $value;
    }

    /**
     * @param  Collection<int, AttendanceRosterAssignment>  $assignments
     * @return list<array<string, string>>
     */
    private function validationFindings($assignments): array
    {
        $findings = [];

        foreach ($assignments->groupBy('employee_id') as $employeeId => $employeeAssignments) {
            $sorted = $employeeAssignments->sortBy('effective_from')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $current = $sorted[$i];
                $previousEnd = $previous->effective_to?->toDateString() ?? '9999-12-31';

                if ($previousEnd >= $current->effective_from?->toDateString()) {
                    $findings[] = [
                        'severity' => 'warning',
                        'code' => 'overlap_existing_roster',
                        'message' => "Employee {$employeeId} has overlapping roster assignments.",
                    ];
                }
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeResult(array $payload, int $exitCode): int
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $exitCode;
    }
}
