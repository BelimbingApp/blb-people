<?php

namespace App\Modules\People\Payroll\Services;

use App\Modules\People\Payroll\Models\PayrollResultLine;
use App\Modules\People\Payroll\Models\PayrollRun;
use App\Modules\People\Payroll\Models\PayrollRunAuditEvent;
use App\Modules\People\Payroll\Models\PayrollRunParticipant;

class PayrollLockAuditReportBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $run): array
    {
        $run->loadMissing(['calendar', 'period', 'participants.employee', 'participants.resultLines', 'auditEvents.user']);
        $participants = $run->participants
            ->map(fn (PayrollRunParticipant $participant): array => $this->participantPayload($participant))
            ->values()
            ->all();
        $resultLines = $run->participants->flatMap(fn (PayrollRunParticipant $participant) => $participant->resultLines);

        return [
            'run' => $this->runPayload($run),
            'lock_state' => $this->lockStatePayload($run),
            'controls' => [
                'participants_count' => count($participants),
                'result_lines_count' => $resultLines->count(),
                'audit_events_count' => $run->auditEvents->count(),
            ],
            'totals_by_line_type' => $this->totalsByLineType($resultLines),
            'participants' => $participants,
            'audit_events' => $run->auditEvents
                ->sortBy([['occurred_at', 'asc'], ['id', 'asc']])
                ->map(fn (PayrollRunAuditEvent $event): array => $this->auditEventPayload($event))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPayload(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'code' => $run->code,
            'name' => $run->name,
            'status' => $run->status,
            'country_iso' => $run->calendar?->country_iso,
            'currency' => $run->currency,
            'period' => $run->period?->code,
            'period_name' => $run->period?->name,
            'starts_on' => $run->period?->starts_on?->toDateString(),
            'ends_on' => $run->period?->ends_on?->toDateString(),
            'pay_date' => $run->period?->pay_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockStatePayload(PayrollRun $run): array
    {
        return [
            'calculated_at' => $run->calculated_at?->toIso8601String(),
            'reviewed_at' => $run->reviewed_at?->toIso8601String(),
            'approved_at' => $run->approved_at?->toIso8601String(),
            'closed_at' => $run->closed_at?->toIso8601String(),
            'voided_at' => $run->voided_at?->toIso8601String(),
            'is_locked' => $run->isClosed(),
            'is_reviewed' => $run->reviewed_at !== null,
            'is_approved' => $run->approved_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function participantPayload(PayrollRunParticipant $participant): array
    {
        return [
            'employee' => [
                'id' => $participant->employee->id,
                'number' => $participant->employee->employee_number,
                'name' => $participant->employee->displayName(),
            ],
            'status' => $participant->status,
            'gross_pay' => $participant->gross_pay,
            'total_deductions' => $participant->total_deductions,
            'total_reimbursements' => $participant->total_reimbursements,
            'net_pay' => $participant->net_pay,
            'result_lines_count' => $participant->resultLines->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditEventPayload(PayrollRunAuditEvent $event): array
    {
        return [
            'id' => $event->id,
            'action' => $event->action,
            'message' => $event->message,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'user' => $event->user?->name,
            'payload' => $event->payload ?? [],
        ];
    }

    /**
     * @return list<array{type: string, count: int, amount: string}>
     */
    private function totalsByLineType($lines): array
    {
        return $lines
            ->groupBy('line_type')
            ->map(fn ($group, string $type): array => [
                'type' => $type,
                'count' => $group->count(),
                'amount' => $this->moneyString($group->sum(fn (PayrollResultLine $line): int => $this->moneyUnits($line->amount))),
            ])
            ->sortBy('type')
            ->values()
            ->all();
    }

    private function moneyUnits(string|int|float|null $amount): int
    {
        $normalized = trim((string) ($amount ?? '0'));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        $units = ((int) $whole * 10000) + (int) str_pad(substr($fraction, 0, 4), 4, '0');

        return $negative ? -$units : $units;
    }

    private function moneyString(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $absolute = abs($units);

        return sprintf('%s%d.%04d', $sign, intdiv($absolute, 10000), $absolute % 10000);
    }
}
