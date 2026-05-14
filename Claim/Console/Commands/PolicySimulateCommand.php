<?php

namespace App\Modules\People\Claim\Console\Commands;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\People\Claim\Models\ClaimAssignmentLine;
use App\Modules\People\Claim\Services\ClaimPolicySimulationService;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'blb:claim:policy:simulate')]
class PolicySimulateCommand extends Command
{
    protected $description = 'Simulate a Claim policy evaluation for an (employee, assignment line, date, amount) tuple';

    protected $signature = 'blb:claim:policy:simulate
                            {employee : Employee number or ID}
                            {line : Claim assignment line ID}
                            {date : Incurred-on date (YYYY-MM-DD)}
                            {amount : Requested amount}
                            {--attachments=0 : Attachment count}
                            {--provider= : Provider name}
                            {--company=1 : Company ID}
                            {--json : Emit machine-readable JSON}';

    public function handle(ClaimPolicySimulationService $simulator): int
    {
        $employee = $this->resolveEmployee();
        if (! $employee instanceof Employee) {
            $this->error('Employee not found for the selected company.');

            return self::FAILURE;
        }

        $line = ClaimAssignmentLine::query()->find((int) $this->argument('line'));
        if (! $line instanceof ClaimAssignmentLine) {
            $this->error('Claim assignment line not found.');

            return self::FAILURE;
        }

        $result = $simulator->simulate(
            employee: $employee,
            assignmentLine: $line,
            incurredOn: new DateTimeImmutable((string) $this->argument('date')),
            requestedAmount: (float) $this->argument('amount'),
            attachmentCount: (int) $this->option('attachments'),
            providerName: $this->option('provider') ?: null,
        );

        return $this->writeResult($result, $result['status'] === 'blocked' ? self::FAILURE : self::SUCCESS);
    }

    private function resolveEmployee(): ?Employee
    {
        $identifier = (string) $this->argument('employee');

        return Employee::query()
            ->where('company_id', (int) $this->option('company'))
            ->where(function ($query) use ($identifier): void {
                $query->where('employee_number', $identifier);
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
        $this->line($result['explanation'] ?? '');
        if ($result['blocking'] !== []) {
            $this->line('');
            $this->line('Blocking reasons:');
            foreach ($result['blocking'] as $reason) {
                $this->line('  - '.$reason);
            }
        }

        return $exitCode;
    }
}
