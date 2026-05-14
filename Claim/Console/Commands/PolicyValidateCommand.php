<?php

namespace App\Modules\People\Claim\Console\Commands;

use App\Modules\People\Claim\Models\ClaimPolicy;
use App\Modules\People\Claim\Services\ClaimPolicyValidationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:claim:policy:validate')]
class PolicyValidateCommand extends Command
{
    protected $description = 'Validate a Claim Policy and emit stable findings';

    protected $signature = 'blb:claim:policy:validate
                            {policy : Claim policy code or ID}
                            {--company=1 : Company ID}
                            {--json : Emit machine-readable JSON}';

    public function handle(ClaimPolicyValidationService $validator): int
    {
        $policy = $this->resolvePolicy();
        if (! $policy instanceof ClaimPolicy) {
            return $this->writeResult([
                'status' => 'error',
                'findings' => [[
                    'severity' => 'error',
                    'code' => 'policy_not_found',
                    'message' => 'Claim policy was not found for the selected company.',
                    'path' => 'policy',
                ]],
            ], self::FAILURE);
        }

        $result = $validator->validate($policy);

        return $this->writeResult($result, $result['status'] === 'error' ? self::FAILURE : self::SUCCESS);
    }

    private function resolvePolicy(): ?ClaimPolicy
    {
        $identifier = (string) $this->argument('policy');

        return ClaimPolicy::query()
            ->where('company_id', (int) $this->option('company'))
            ->where(function ($query) use ($identifier): void {
                $query->where('code', $identifier);
                if (ctype_digit($identifier)) {
                    $query->orWhereKey((int) $identifier);
                }
            })
            ->first();
    }

    /** @param  array<string, mixed>  $result */
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
