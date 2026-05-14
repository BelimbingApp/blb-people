<?php

namespace App\Modules\People\Attendance\Console\Commands;

use App\Modules\People\Attendance\Models\AttendancePolicyGroup;
use App\Modules\People\Attendance\Services\AttendancePolicyValidationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:attendance:policy:validate')]
class PolicyValidateCommand extends Command
{
    protected $description = 'Validate an Attendance Policy Group and emit stable findings';

    protected $signature = 'blb:attendance:policy:validate
                            {policy : Policy group code or ID}
                            {--company=1 : Company ID}
                            {--json : Emit machine-readable JSON}';

    public function handle(AttendancePolicyValidationService $validator): int
    {
        $policyGroup = $this->policyGroup();
        if (! $policyGroup instanceof AttendancePolicyGroup) {
            return $this->writeResult([
                'status' => 'error',
                'findings' => [[
                    'severity' => 'error',
                    'code' => 'policy_not_found',
                    'message' => 'Attendance Policy Group was not found for the selected company.',
                    'path' => 'policy',
                ]],
            ], self::FAILURE);
        }

        $result = $validator->validate($policyGroup);

        return $this->writeResult($result, $result['status'] === 'error' ? self::FAILURE : self::SUCCESS);
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
        foreach ($result['findings'] ?? [] as $finding) {
            $this->line(sprintf('[%s] %s: %s (%s)', $finding['severity'], $finding['code'], $finding['message'], $finding['path']));
        }

        return $exitCode;
    }
}
