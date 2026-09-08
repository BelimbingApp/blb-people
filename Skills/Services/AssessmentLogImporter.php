<?php

namespace App\Domains\People\Skills\Services;

use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\AssessmentLogImportResult;
use App\Domains\People\Skills\Data\AssessmentLogPlannedRow;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\AssessmentStatus;
use App\Domains\People\Skills\Exceptions\InvalidAssessmentException;
use App\Domains\People\Skills\Import\SkillWorkbookReader;
use App\Domains\People\Skills\Import\UnreadableSkillWorkbook;
use App\Domains\People\Skills\Import\WorkbookDefect;
use App\Domains\People\Skills\Import\WorkbookSource;
use App\Domains\People\Skills\Models\SkillAssessment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies 04 Assessment Log after a clean dry run (blb-people#390 / [0008-d]).
 *
 * The dry run decides: any defect and nothing is written. Every would-create
 * row then becomes one finalized assessment through AssessmentStore in a
 * single transaction, so a refusal on row k leaves rows 1..k-1 unwritten and
 * is reported as a defect on that row. Would-skip rows, and rows whose
 * provenance key `<sha256>:<row>` is already recorded, are skipped, never
 * rewritten. One audit row per successful apply names the file hash, the
 * counts and the created ids.
 *
 * Scoping only, no authorization of the importer beyond the store's HR check:
 * the command proves the actor may import for the company before the file is
 * opened. The assessor of record is the row's Assessor Staff ID resolved to
 * its linked platform user; a row without one is a store refusal.
 */
final class AssessmentLogImporter
{
    public const string EVENT = 'people.skill.assessment-log.imported';

    public const string SOURCE = 'workbook:04-assessment-log';

    public const string UNRESOLVED_ASSESSOR = 'unresolved_assessor';

    public const string INVALID_METHOD = 'invalid_method';

    public const string INVALID_CYCLE = 'invalid_cycle';

    public const string STORE_REFUSED = 'store_refused';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AssessmentLogDryRun $dryRun,
        private readonly AssessmentStore $assessments,
        private readonly WorkforceSubjects $workforce,
        private readonly SemanticActionRecorder $recorder,
    ) {}

    /** @throws UnreadableSkillWorkbook */
    public function apply(User $actor, int $companyEntityId, string $workbookPath): AssessmentLogImportResult
    {
        $tenantId = $this->tenants->requireTenantId();
        $plan = $this->dryRun->run($tenantId, $companyEntityId, $workbookPath);

        if ($plan->defects !== []) {
            return new AssessmentLogImportResult($plan->sha256, [], 0, $plan->defects);
        }

        $assessors = [];
        foreach ($this->workforce->employees($companyEntityId) as $employee) {
            if ($employee->employeeNumber !== null && $employee->userReference !== null) {
                $assessors[trim($employee->employeeNumber)] = [(int) $employee->reference->externalId, (int) $employee->userReference->externalId];
            }
        }

        $created = [];
        $skipped = 0;

        try {
            DB::transaction(function () use ($actor, $companyEntityId, $tenantId, $plan, $assessors, &$created, &$skipped): void {
                foreach ($plan->plan as $row) {
                    $reference = $plan->sha256.':'.$row->row;

                    if ($row->wouldSkip || $this->referenceExists($tenantId, $companyEntityId, $reference)) {
                        $skipped++;

                        continue;
                    }

                    $created[] = (int) $this->create($actor, $companyEntityId, $row, $plan->sha256, $reference, $assessors)->getKey();
                }
            });
        } catch (RefusedAssessmentLogRow $refusal) {
            return new AssessmentLogImportResult($plan->sha256, [], 0, [$refusal->defect]);
        }

        $this->recorder->record(
            event: self::EVENT,
            summary: __('Imported 04 Assessment Log :sha: :created assessments created, :skipped skipped', [
                'sha' => $plan->sha256, 'created' => count($created), 'skipped' => $skipped,
            ]),
            source: __('Skills'),
            subject: ['name' => 'skill-assessment-log-import', 'id' => $companyEntityId, 'identifier' => $plan->sha256],
            surface: 'people.skill.catalog.import',
            uiElement: 'assessment-log-apply',
            context: [
                'company_entity_id' => $companyEntityId,
                'file' => basename($workbookPath),
                'sha256' => $plan->sha256,
                'created' => count($created),
                'skipped' => $skipped,
                'assessment_ids' => $created,
            ],
        );

        return new AssessmentLogImportResult($plan->sha256, $created, $skipped, []);
    }

    /** @param  array<string, array{int, int}>  $assessors  staff id => [employee entity id, platform user id] */
    private function create(User $actor, int $companyEntityId, AssessmentLogPlannedRow $row, string $sha256, string $reference, array $assessors): SkillAssessment
    {
        $source = new WorkbookSource($sha256, SkillWorkbookReader::ASSESSMENT_LOG, $row->row);
        $assessor = $assessors[$row->assessorStaffId] ?? null;
        if ($assessor === null) {
            throw new RefusedAssessmentLogRow(new WorkbookDefect(self::UNRESOLVED_ASSESSOR, 'I'.$row->row, $source));
        }

        $method = AssessmentMethod::tryFrom($row->method)
            ?? throw new RefusedAssessmentLogRow(new WorkbookDefect(self::INVALID_METHOD, 'G'.$row->row, $source));
        $cycle = AssessmentCycle::tryFrom($row->cycle)
            ?? throw new RefusedAssessmentLogRow(new WorkbookDefect(self::INVALID_CYCLE, 'B'.$row->row, $source));

        try {
            return $this->assessments->importFinalized(
                $actor,
                $companyEntityId,
                new AssessmentDraft(
                    employeeEntityId: $row->employeeEntityId,
                    skillId: $row->skillId,
                    assessedLevel: $row->assessedLevel,
                    method: $method,
                    cycle: $cycle,
                    assessedAt: CarbonImmutable::parse($row->assessedOn)->startOfDay(),
                    evidence: $row->evidence,
                    assessorUserId: $assessor[1],
                    assessorEmployeeEntityId: $assessor[0],
                    certificateNumber: $row->certificateNumber,
                    validUntil: $row->validUntil === null ? null : CarbonImmutable::parse($row->validUntil)->startOfDay(),
                ),
                self::SOURCE,
                $reference,
                sprintf('04 Assessment Log row %d (HOD Verified? = %s)', $row->row, $row->hodVerified === '' ? '-' : $row->hodVerified),
            );
        } catch (InvalidAssessmentException) {
            throw new RefusedAssessmentLogRow(new WorkbookDefect(self::STORE_REFUSED, 'A'.$row->row, $source));
        }
    }

    private function referenceExists(int $tenantId, int $companyEntityId, string $reference): bool
    {
        return SkillAssessment::query()->forCompany($tenantId, $companyEntityId)
            ->where('source', self::SOURCE)
            ->where('source_reference', $reference)
            ->where('status', AssessmentStatus::Finalized->value)
            ->exists();
    }
}
