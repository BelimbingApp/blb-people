<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Media\Services\MediaAssetStore;
use App\Base\Pdf\Services\PdfRenderer;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Skills\Models\EmployeeSkillScore;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Data\TrainingPassport;
use App\Domains\People\Training\Data\TrainingPassportCertificate;
use App\Domains\People\Training\Data\TrainingPassportEvent;
use App\Domains\People\Training\Data\TrainingPassportSkill;
use App\Domains\People\Training\Data\TrainingPassportSkillLevel;
use App\Domains\People\Training\Exceptions\TrainingPassportDenied;
use App\Domains\People\Training\Models\TrainingPassportDocument;
use App\Domains\People\Training\Models\TrainingPassportDocumentAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Printable training passport (0014-a).
 *
 * Generation reads the passport through {@see TrainingPassportReader}, so the
 * tenant, company and audience rules are the reader's; on top of that only
 * the employee themself or HR of the company may produce the document. The
 * PDF goes through the platform renderer and is registered as a media asset;
 * the document row and its audit row are written in one transaction.
 *
 * Downloading is separate from viewing the passport: a document is served
 * only to the employee it describes or to HR of the company, only while it
 * is inside its retention window, and every download is audited.
 */
final class TrainingPassportDocumentStore
{
    public const TEMPLATE_VERSION = 'training-passport@v1';

    public const RETENTION_DAYS = 30;

    public const VIEW = 'people::pdf.training-passport';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SkillAudience $audience,
        private readonly TrainingPassportReader $reader,
        private readonly PdfRenderer $renderer,
        private readonly MediaAssetStore $media,
    ) {}

    public function generate(User $actor, WorkforceSubject $subject): TrainingPassportDocument
    {
        $passport = $this->reader->read($actor, $subject);
        $this->assertGenerator($actor, $subject);

        $tenantId = $this->tenantContext->requireTenantId();
        $companyId = (int) $subject->companyId;
        $employeeId = (int) $subject->stableId;
        $generatedAt = CarbonImmutable::instance($passport->generatedAt);
        $skillLevels = $this->skillLevels($tenantId, $companyId, $employeeId);
        $dataVersion = $this->dataVersion($passport, $skillLevels, $generatedAt);

        // Idempotent for one day of unchanged data: a second click, or a second
        // generation by HR after the employee's own, reuses the stored document
        // rather than storing a byte-identical copy and a duplicate audit row.
        $existing = TrainingPassportDocument::query()
            ->forCompany($tenantId, $companyId)
            ->where('employee_entity_id', $employeeId)
            ->where('data_version', $dataVersion)
            ->whereNotNull('media_asset_id')
            ->unexpired()
            ->orderByDesc('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $artifact = $this->renderer->renderInline(
            view: self::VIEW,
            data: [
                'passport' => $passport,
                'skillLevels' => $skillLevels,
                'employeeId' => $employeeId,
                'generatedAt' => $generatedAt,
                'watermark' => $this->watermark($employeeId, $generatedAt),
            ],
            templateVersion: self::TEMPLATE_VERSION,
            dataVersion: $dataVersion,
            producedBy: (int) $actor->id,
        );

        return DB::transaction(function () use ($artifact, $actor, $tenantId, $companyId, $employeeId, $generatedAt, $dataVersion): TrainingPassportDocument {
            $asset = $this->media->storeOriginal($artifact->disk, $artifact->path, [
                'original_filename' => sprintf('training-passport-%d-%s.pdf', $employeeId, $generatedAt->format('Ymd')),
                'mime_type' => 'application/pdf',
                'file_size' => $artifact->bytes,
                'metadata' => [
                    'kind' => 'training_passport',
                    'tenant_id' => $tenantId,
                    'company_entity_id' => $companyId,
                    'employee_entity_id' => $employeeId,
                    'template_version' => $artifact->templateVersion,
                    'sha256' => $artifact->sha256,
                ],
            ]);

            $document = TrainingPassportDocument::query()->create([
                'tenant_id' => $tenantId,
                'company_entity_id' => $companyId,
                'employee_entity_id' => $employeeId,
                'media_asset_id' => $asset->id,
                'template_version' => $artifact->templateVersion,
                'data_version' => $dataVersion,
                'sha256' => $artifact->sha256,
                'bytes' => $artifact->bytes,
                'generated_by_user_id' => $actor->id,
                'generated_at' => $generatedAt,
                'expires_at' => $generatedAt->addDays(self::RETENTION_DAYS),
            ]);

            $this->audit($document, TrainingPassportDocument::EVENT_GENERATED, $actor, [
                'sha256' => $artifact->sha256,
                'bytes' => $artifact->bytes,
            ]);

            return $document;
        });
    }

    /**
     * Resolve a document for download by id, or refuse. Refusals are one
     * exception whatever the reason, so an id outside the actor's company
     * looks the same as an id that never existed.
     */
    public function authorizeDownload(User $actor, int $documentId): TrainingPassportDocument
    {
        $tenantId = $this->tenantContext->requireTenantId();
        if ($actor->tenant_id !== $tenantId || $actor->company_id === null) {
            throw new TrainingPassportDenied('The training passport document is unavailable in the current scope.');
        }

        $document = TrainingPassportDocument::query()
            ->forCompany($tenantId, (int) $actor->company_id)
            ->whereKey($documentId)
            ->first();
        if ($document === null || $document->media_asset_id === null || $document->isExpired()) {
            throw new TrainingPassportDenied('The training passport document is unavailable in the current scope.');
        }

        $own = $actor->employee_id !== null && (int) $actor->employee_id === $document->employee_entity_id;
        if (! $own && ! $this->isHr($actor, $document->company_entity_id)) {
            throw new TrainingPassportDenied('The training passport document is unavailable in the current scope.');
        }

        $this->audit($document, TrainingPassportDocument::EVENT_DOWNLOADED, $actor);

        return $document;
    }

    /**
     * Unexpired documents for one employee, newest first. Same audience rule
     * as the download: the employee themself or HR of the company.
     *
     * @return Collection<int, TrainingPassportDocument>
     */
    public function documentsFor(User $actor, WorkforceSubject $subject): Collection
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $own = $actor->employee_id !== null && (string) $actor->employee_id === $subject->stableId;
        if ($subject->tenantId !== $tenantId || $subject->companyId === null
            || $actor->tenant_id !== $tenantId || $actor->company_id !== $subject->companyId
            || (! $own && ! $this->isHr($actor, (int) $subject->companyId))) {
            return new Collection;
        }

        return TrainingPassportDocument::query()
            ->forCompany($tenantId, (int) $subject->companyId)
            ->where('employee_entity_id', (int) $subject->stableId)
            ->whereNotNull('media_asset_id')
            ->unexpired()
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Drop the bytes of every document in the current tenant past its
     * retention window. The row and its audit trail stay; only the asset
     * pointer is cleared.
     *
     * @return int documents purged
     */
    public function purgeExpired(): int
    {
        $purged = 0;
        TrainingPassportDocument::query()
            ->forTenant($this->tenantContext->requireTenantId())
            ->withoutCompanyScope('retention purge runs across every company of the bound tenant')
            ->whereNotNull('media_asset_id')
            ->where('expires_at', '<=', now())
            ->with('asset')
            ->orderBy('id')
            ->each(function (TrainingPassportDocument $document) use (&$purged): void {
                $asset = $document->asset;
                $document->forceFill(['media_asset_id' => null])->save();
                if ($asset !== null) {
                    $this->media->delete($asset);
                }
                $purged++;
            });

        return $purged;
    }

    private function assertGenerator(User $actor, WorkforceSubject $subject): void
    {
        $own = $actor->employee_id !== null && (string) $actor->employee_id === $subject->stableId;
        if ($own || $this->isHr($actor, (int) $subject->companyId)) {
            return;
        }

        throw new TrainingPassportDenied('Only the employee or HR may generate a training passport document.');
    }

    private function isHr(User $actor, int $companyId): bool
    {
        try {
            $this->audience->assertHr($actor, $companyId);

            return true;
        } catch (AuthorizationDeniedException) {
            return false;
        }
    }

    /** @param  array<string, mixed>|null  $metadata */
    private function audit(TrainingPassportDocument $document, string $eventType, User $actor, ?array $metadata = null): void
    {
        TrainingPassportDocumentAudit::query()->create([
            'tenant_id' => $document->tenant_id,
            'company_entity_id' => $document->company_entity_id,
            'document_id' => $document->id,
            'employee_entity_id' => $document->employee_entity_id,
            'event_type' => $eventType,
            'actor_user_id' => $actor->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    /** @return list<TrainingPassportSkillLevel> */
    private function skillLevels(int $tenantId, int $companyId, int $employeeId): array
    {
        $scores = EmployeeSkillScore::query()
            ->forCompany($tenantId, $companyId)
            ->where('employee_entity_id', $employeeId)
            ->orderBy('skill_id')
            ->get(['skill_id', 'current_level', 'required_level', 'assessed_at', 'valid_until']);
        if ($scores->isEmpty()) {
            return [];
        }

        $skills = Skill::query()
            ->forCompany($tenantId, $companyId)
            ->whereIn('id', $scores->pluck('skill_id')->map(intval(...))->all())
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        return $scores
            ->filter(fn (EmployeeSkillScore $score): bool => $skills->has((int) $score->skill_id))
            ->map(function (EmployeeSkillScore $score) use ($skills): TrainingPassportSkillLevel {
                $skill = $skills->get((int) $score->skill_id);

                return new TrainingPassportSkillLevel(
                    (int) $skill->id, (string) $skill->code, (string) $skill->name,
                    (int) $score->current_level, (int) $score->required_level,
                    $score->assessed_at, $score->valid_until,
                );
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /** @param  list<TrainingPassportSkillLevel>  $skillLevels */
    private function dataVersion(TrainingPassport $passport, array $skillLevels, CarbonImmutable $generatedAt): string
    {
        $payload = [
            'template' => self::TEMPLATE_VERSION,
            'date' => $generatedAt->toDateString(),
            'employee' => $passport->subject->stableId,
            'events' => array_map(fn (TrainingPassportEvent $event): array => [
                $event->eventId, $event->status->value, $event->attended, $event->actualMinutes,
            ], $passport->events),
            'certificates' => array_map(fn (TrainingPassportCertificate $certificate): array => [
                $certificate->eventId, $certificate->reference, $certificate->validUntil?->format('Y-m-d'), $certificate->expired,
            ], $passport->certificates),
            'skills' => array_map(fn (TrainingPassportSkill $skill): int => $skill->skillId, $passport->skills),
            'levels' => array_map(fn (TrainingPassportSkillLevel $level): array => [
                $level->skillId, $level->currentLevel, $level->requiredLevel,
            ], $skillLevels),
        ];

        return hash('sha256', (string) json_encode($payload));
    }

    private function watermark(int $employeeId, CarbonImmutable $generatedAt): string
    {
        return __('Generated :date · Employee #:id', [
            'date' => $generatedAt->format('Y-m-d H:i'),
            'id' => $employeeId,
        ]);
    }
}
