<?php

namespace App\Modules\People\Attendance\Console\Commands;

use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Models\AttendanceShiftTemplate;
use App\Modules\People\Attendance\Services\AttendancePolicySimulationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:attendance:policy:simulate')]
class PolicySimulateCommand extends Command
{
    protected $description = 'Simulate an Attendance Policy Group against a shift and sample clock times';

    protected $signature = 'blb:attendance:policy:simulate
                            {policy : Policy group code or ID}
                            {--company=1 : Company ID}
                            {--shift= : Shift template code or ID}
                            {--date= : Attendance date, YYYY-MM-DD}
                            {--clock-in= : Clock-in time, HH:MM}
                            {--clock-out= : Clock-out time, HH:MM}
                            {--json : Emit machine-readable JSON}';

    public function handle(AttendancePolicySimulationService $simulator): int
    {
        $policyGroup = $this->policyGroup();
        $shiftTemplate = $this->shiftTemplate();
        $date = $this->optionString('date');
        $clockIn = $this->optionString('clock-in');
        $clockOut = $this->optionString('clock-out');

        $inputErrors = $this->inputErrors($policyGroup, $shiftTemplate, $date, $clockIn, $clockOut);
        if ($inputErrors !== []) {
            return $this->writeResult(['status' => 'error', 'findings' => $inputErrors], self::FAILURE);
        }

        $result = $simulator->simulate($policyGroup, $shiftTemplate, $date, $clockIn, $clockOut);

        return $this->writeResult($result, self::SUCCESS);
    }

    private function policyGroup(): ?AttendancePolicyGroup
    {
        $policy = (string) $this->argument('policy');

        return AttendancePolicyGroup::query()
            ->where('company_id', (int) $this->option('company'))
            ->where(function ($query) use ($policy): void {
                $query->where('code', $policy);
                if (ctype_digit($policy)) {
                    $query->orWhereKey((int) $policy);
                }
            })
            ->first();
    }

    private function shiftTemplate(): ?AttendanceShiftTemplate
    {
        $shift = $this->optionString('shift');
        if ($shift === '') {
            return null;
        }

        return AttendanceShiftTemplate::query()
            ->where('company_id', (int) $this->option('company'))
            ->where(function ($query) use ($shift): void {
                $query->where('code', $shift);
                if (ctype_digit($shift)) {
                    $query->orWhereKey((int) $shift);
                }
            })
            ->first();
    }

    private function optionString(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function inputErrors(?AttendancePolicyGroup $policyGroup, ?AttendanceShiftTemplate $shiftTemplate, string $date, string $clockIn, string $clockOut): array
    {
        $errors = [];
        if (! $policyGroup instanceof AttendancePolicyGroup) {
            $errors[] = $this->finding('policy_not_found', 'Attendance Policy Group was not found for the selected company.', 'policy');
        }
        if (! $shiftTemplate instanceof AttendanceShiftTemplate) {
            $errors[] = $this->finding('shift_not_found', 'Shift template was not found for the selected company.', 'shift');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = $this->finding('date_invalid', 'Simulation date must be YYYY-MM-DD.', 'date');
        }
        if (! preg_match('/^\d{2}:\d{2}$/', $clockIn)) {
            $errors[] = $this->finding('clock_in_invalid', 'Clock-in must be HH:MM.', 'clock-in');
        }
        if (! preg_match('/^\d{2}:\d{2}$/', $clockOut)) {
            $errors[] = $this->finding('clock_out_invalid', 'Clock-out must be HH:MM.', 'clock-out');
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private function finding(string $code, string $message, string $path): array
    {
        return [
            'severity' => 'error',
            'code' => $code,
            'message' => $message,
            'path' => $path,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeResult(array $result, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->line('Status: '.$result['status']);
        if (isset($result['explanation'])) {
            $this->line($result['explanation']);
        }
        foreach ($result['findings'] ?? [] as $finding) {
            $this->line(sprintf('[%s] %s: %s (%s)', $finding['severity'], $finding['code'], $finding['message'], $finding['path']));
        }

        return $exitCode;
    }
}
