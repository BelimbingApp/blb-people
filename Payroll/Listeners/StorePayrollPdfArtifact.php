<?php

namespace App\Modules\People\Payroll\Listeners;

use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Modules\People\Payroll\Models\PayrollPdfArtifact;

class StorePayrollPdfArtifact
{
    public function handle(PdfArtifactRendered $event): void
    {
        $metadata = $event->request->metadata;
        $payrollRunId = $metadata['payroll_run_id'] ?? null;
        $reportType = $metadata['report_type'] ?? null;

        if (! is_numeric($payrollRunId) || ! is_string($reportType) || $reportType === '') {
            return;
        }

        PayrollPdfArtifact::query()->create([
            'payroll_run_id' => (int) $payrollRunId,
            'payroll_run_participant_id' => $this->nullableInt($metadata['payroll_run_participant_id'] ?? null),
            'employee_id' => $this->nullableInt($metadata['employee_id'] ?? null),
            'report_type' => $reportType,
            'disk' => $event->artifact->disk,
            'path' => $event->artifact->path,
            'template_version' => $event->artifact->templateVersion,
            'data_version' => $event->artifact->dataVersion,
            'bytes' => $event->artifact->bytes,
            'sha256' => $event->artifact->sha256,
            'produced_by' => $event->artifact->producedBy,
            'produced_at' => $event->artifact->producedAt,
            'metadata' => $metadata,
        ]);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
