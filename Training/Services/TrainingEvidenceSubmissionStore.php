<?php

namespace App\Domains\People\Training\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Media\Exceptions\MediaStorageException;
use App\Base\Media\Models\MediaAsset;
use App\Base\Media\Services\MediaAssetStore;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Contracts\ReadsWorkforceDirectory;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Skills\Services\CompanyAttribution;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Exceptions\InvalidTrainingEvidenceSubmissionException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingEvidenceSubmission;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TrainingEvidenceSubmissionStore
{
    public const SUBMIT = 'people.training.participation.evidence.submit';

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly ReadsWorkforceDirectory $directory,
        private readonly CompanyAttribution $companies,
        private readonly AuthorizationService $authorization,
        private readonly MediaAssetStore $media,
    ) {}

    public function submit(
        User $actor,
        int $companyId,
        int $eventId,
        string $reflection,
        ?string $certificateNumber,
        ?string $certificateExpiresOn,
        UploadedFile $document,
    ): TrainingEvidenceSubmission {
        [$tenant, $employee] = $this->scope($actor, $companyId);
        $participant = $this->participant($tenant, $companyId, $eventId, $employee);
        $this->requireAttendedAndUnconfirmed($tenant, $companyId, $participant);

        $reflection = trim($reflection);
        $certificateNumber = $this->nullableTrim($certificateNumber);
        $certificateExpiry = $this->expiry($certificateExpiresOn, $certificateNumber);
        if ($reflection === '' || mb_strlen($reflection) > 2000) {
            throw new InvalidTrainingEvidenceSubmissionException('Add a reflection of no more than 2,000 characters.');
        }
        if ($certificateNumber !== null && mb_strlen($certificateNumber) > 160) {
            throw new InvalidTrainingEvidenceSubmissionException('The certificate number must be no more than 160 characters.');
        }
        if (! $document->isValid() || (int) $document->getSize() > 10 * 1024 * 1024) {
            throw new InvalidTrainingEvidenceSubmissionException('Upload one supporting document no larger than 10 MB.');
        }
        if (TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)->where('participant_id', $participant->id)->exists()) {
            throw new InvalidTrainingEvidenceSubmissionException('Evidence has already been submitted for this event and is pending HR confirmation.');
        }

        try {
            $asset = $this->media->putUploadedFile('local', 'people/training/evidence', $this->storableDocument($document), [
                'purpose' => 'people.training.participation.evidence',
                'tenant_id' => $tenant,
                'company_entity_id' => $companyId,
                'event_id' => $eventId,
                'participant_id' => (int) $participant->id,
            ]);
        } catch (MediaStorageException) {
            throw new InvalidTrainingEvidenceSubmissionException('This file cannot be stored safely. Choose another supporting document.');
        }

        try {
            return DB::transaction(fn (): TrainingEvidenceSubmission => TrainingEvidenceSubmission::query()->create([
                'tenant_id' => $tenant,
                'company_entity_id' => $companyId,
                'event_id' => $eventId,
                'participant_id' => $participant->id,
                'reflection' => $reflection,
                'certificate_number' => $certificateNumber,
                'certificate_expires_on' => $certificateExpiry,
                'document_asset_id' => $asset->id,
                'status' => 'pending',
                'submitted_by_user_id' => $actor->getKey(),
                'submitted_at' => now(),
            ]));
        } catch (Throwable $failure) {
            $this->discard($asset);
            if ($failure instanceof QueryException) {
                throw new InvalidTrainingEvidenceSubmissionException('Evidence has already been submitted for this event and is pending HR confirmation.');
            }

            throw $failure;
        }
    }

    /**
     * @return list<array{event_id: int, title: string, starts_at: mixed, confirmed: bool, submitted: bool}>
     */
    public function visibleEvents(User $actor, int $companyId): array
    {
        [$tenant, $employee] = $this->scope($actor, $companyId);
        $participants = TrainingParticipant::query()->forCompany($tenant, $companyId)
            ->where('provider_id', $employee->reference->providerId)
            ->where('employee_subject_id', $employee->reference->externalId)
            ->get();
        if ($participants->isEmpty()) {
            return [];
        }

        $facts = TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->whereIn('participant_id', $participants->pluck('id')->all())->get()->groupBy('participant_id');
        $participants = $participants->filter(fn (TrainingParticipant $participant): bool => $facts->get($participant->id, collect())
            ->contains(fn (TrainingParticipationFact $fact): bool => $fact->attendance === AttendanceStatus::Present));
        $events = TrainingEvent::query()->forCompany($tenant, $companyId)
            ->whereIn('id', $participants->pluck('event_id')->all())->get()->keyBy('id');
        $courses = TrainingCourse::query()->forCompany($tenant, $companyId)
            ->whereIn('id', $events->pluck('course_id')->all())->get()->keyBy('id');
        $submitted = TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)
            ->whereIn('participant_id', $participants->pluck('id')->all())->pluck('participant_id')->mapWithKeys(fn ($id): array => [(int) $id => true]);

        return $participants->map(function (TrainingParticipant $participant) use ($courses, $events, $facts, $submitted): ?array {
            $event = $events->get($participant->event_id);
            if ($event === null) {
                return null;
            }

            return [
                'event_id' => (int) $event->id,
                'title' => (string) ($courses->get($event->course_id)?->title ?? 'Training event'),
                'starts_at' => $event->starts_at,
                'confirmed' => $facts->get($participant->id, collect())->contains(fn (TrainingParticipationFact $fact): bool => $fact->confirmed_at !== null),
                'submitted' => $submitted->has((int) $participant->id),
            ];
        })->filter()->sortByDesc('starts_at')->values()->all();
    }

    /** @return array{0: int, 1: WorkforceEmployee} */
    private function scope(User $actor, int $companyId): array
    {
        $tenant = $this->tenancy->currentTenantId();
        $currentActor = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($tenant === null || $currentActor === null || $currentActor->getCompanyId() !== $actor->getCompanyId()
            || (int) $currentActor->tenant_id !== $tenant || ! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $this->authorization->authorize(Actor::forUser($actor), self::SUBMIT);
        $employee = $this->directory->employeeForUser((string) $companyId, (int) $actor->getKey());
        if ($employee === null || ! $employee->active || $employee->userReferenceRevoked
            || $employee->companyReference->externalId !== (string) $companyId
            || $employee->userReference?->providerId !== $employee->reference->providerId
            || $employee->userReference?->externalId !== (string) $actor->getKey()) {
            $this->deny();
        }

        return [$tenant, $employee];
    }

    private function participant(int $tenant, int $companyId, int $eventId, WorkforceEmployee $employee): TrainingParticipant
    {
        return TrainingParticipant::query()->forCompany($tenant, $companyId)
            ->where('event_id', $eventId)
            ->where('provider_id', $employee->reference->providerId)
            ->where('employee_subject_id', $employee->reference->externalId)
            ->first() ?? $this->deny();
    }

    private function requireAttendedAndUnconfirmed(int $tenant, int $companyId, TrainingParticipant $participant): void
    {
        $facts = TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->where('participant_id', $participant->id)->get();
        if ($facts->contains(fn (TrainingParticipationFact $fact): bool => $fact->confirmed_at !== null)) {
            throw new InvalidTrainingEvidenceSubmissionException('This participation has already been confirmed. Ask HR to make a traceable correction.');
        }
        if (! $facts->contains(fn (TrainingParticipationFact $fact): bool => $fact->attendance === AttendanceStatus::Present)) {
            throw new InvalidTrainingEvidenceSubmissionException('Evidence can only be submitted for an event you attended.');
        }
    }

    private function expiry(?string $value, ?string $certificateNumber): ?CarbonImmutable
    {
        $value = $this->nullableTrim($value);
        if ($value === null) {
            return null;
        }
        if ($certificateNumber === null) {
            throw new InvalidTrainingEvidenceSubmissionException('Add a certificate number before its expiry date.');
        }
        try {
            $expiry = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($expiry === false || $expiry->format('Y-m-d') !== $value) {
                throw new InvalidTrainingEvidenceSubmissionException('Use a valid certificate expiry date.');
            }

            return $expiry;
        } catch (Throwable) {
            throw new InvalidTrainingEvidenceSubmissionException('Use a valid certificate expiry date.');
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function storableDocument(UploadedFile $document): UploadedFile
    {
        $name = $document->getClientOriginalName();
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = '';
        }
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if (trim($name) === '') {
            $name = 'training-evidence.'.($document->guessExtension() ?: 'bin');
        }

        return new UploadedFile(
            $document->getRealPath(),
            mb_strcut($name, 0, 240, 'UTF-8'),
            $document->getMimeType(),
            $document->getError(),
            true,
        );
    }

    private function discard(MediaAsset $asset): void
    {
        try {
            $this->media->delete($asset);
        } catch (Throwable) {
            // Preserve the original failure; storage cleanup remains best-effort.
        }
    }

    private function deny(): never
    {
        throw new InvalidTrainingEvidenceSubmissionException('The evidence submission is unavailable in the current scope.');
    }
}
