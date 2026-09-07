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
use App\Domains\People\Training\Models\TrainingEvidenceDecision;
use App\Domains\People\Training\Models\TrainingEvidenceSubmission;
use App\Domains\People\Training\Models\TrainingParticipant;
use App\Domains\People\Training\Models\TrainingParticipationFact;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TrainingEvidenceSubmissionStore
{
    public const SUBMIT = 'people.training.participation.evidence.submit';

    /**
     * HR decisions on submitted evidence (0011-b). The issue names this
     * `...evidence.confirm`, but platform verbs are a closed vocabulary
     * and `confirm` is not declared there; `verify` is the blessed
     * equivalent and covers both confirm and return decisions.
     */
    public const VERIFY = 'people.training.participation.evidence.verify';

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
        $draft = TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)
            ->where('participant_id', $participant->id)
            ->first();
        if ($draft !== null && $draft->status !== 'draft') {
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
            $result = DB::transaction(function () use ($tenant, $companyId, $eventId, $participant, $reflection, $certificateNumber, $certificateExpiry, $asset, $actor, $draft): array {
                if ($draft !== null) {
                    $replacedAssetId = (int) $draft->document_asset_id;
                    $draft->update([
                        'reflection' => $reflection,
                        'certificate_number' => $certificateNumber,
                        'certificate_expires_on' => $certificateExpiry,
                        'document_asset_id' => $asset->id,
                        'status' => 'pending',
                        'submitted_by_user_id' => $actor->getKey(),
                        'submitted_at' => now(),
                        'decided_by_user_id' => null,
                        'decided_at' => null,
                        'decision_note' => null,
                    ]);

                    return [$draft->refresh(), $replacedAssetId];
                }

                return [TrainingEvidenceSubmission::query()->create([
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
                ]), null];
            });

            // Best-effort cleanup of the replaced document after commit; the
            // new row stays authoritative even if the old file lingers.
            if ($result[1] !== null) {
                $replaced = MediaAsset::query()->whereKey($result[1])->first();
                if ($replaced !== null) {
                    $this->discard($replaced);
                }
            }

            return $result[0];
        } catch (Throwable $failure) {
            $this->discard($asset);
            if ($failure instanceof QueryException) {
                throw new InvalidTrainingEvidenceSubmissionException('Evidence has already been submitted for this event and is pending HR confirmation.');
            }

            throw $failure;
        }
    }

    /**
     * Pending evidence submissions for the HR queue, oldest first.
     *
     * @return Collection<int, TrainingEvidenceSubmission>
     */
    public function pendingQueue(User $actor, int $companyId): Collection
    {
        $tenant = $this->authorizeDecision($actor, $companyId);

        return TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)
            ->where('status', 'pending')
            ->orderBy('submitted_at')->orderBy('id')
            ->get();
    }

    /**
     * Confirm a pending submission (0011-b): the certificate number and
     * expiry land on the participation fact, the submission closes as
     * confirmed, and one audit row names the deciding HR user.
     */
    public function confirm(User $actor, int $companyId, int $submissionId): TrainingEvidenceSubmission
    {
        $tenant = $this->authorizeDecision($actor, $companyId);

        return DB::transaction(function () use ($tenant, $companyId, $submissionId, $actor): TrainingEvidenceSubmission {
            $submission = $this->pendingSubmission($tenant, $companyId, $submissionId);
            $fact = $this->unconfirmedFact($tenant, $companyId, $submission);

            $fact->update([
                'certificate_reference' => $submission->certificate_number,
                'certificate_valid_from' => CarbonImmutable::today(),
                'certificate_valid_until' => $submission->certificate_expires_on,
            ]);

            $submission->update([
                'status' => 'confirmed',
                'decided_by_user_id' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => null,
            ]);
            $this->recordDecision($submission, 'confirmed', null, (int) $actor->getKey());

            return $submission->refresh();
        });
    }

    /**
     * Return a pending submission to the employee with a note. A confirmed
     * submission can never be returned; the employee resubmits through
     * submit(), which reopens the same row.
     */
    public function returnToEmployee(User $actor, int $companyId, int $submissionId, string $note): TrainingEvidenceSubmission
    {
        $tenant = $this->authorizeDecision($actor, $companyId);
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw new InvalidTrainingEvidenceSubmissionException('A return note of up to 2,000 characters is required.');
        }

        return DB::transaction(function () use ($tenant, $companyId, $submissionId, $note, $actor): TrainingEvidenceSubmission {
            $submission = $this->pendingSubmission($tenant, $companyId, $submissionId);

            $submission->update([
                'status' => 'draft',
                'decided_by_user_id' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $this->recordDecision($submission, 'returned', $note, (int) $actor->getKey());

            return $submission->refresh();
        });
    }

    /**
     * @return list<array{event_id: int, title: string, starts_at: mixed, confirmed: bool, submitted: bool, returned_note: ?string}>
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

        $facts = TrainingParticipationFact::query()->forCompany($tenant, $companyId)->current()
            ->whereIn('participant_id', $participants->pluck('id')->all())->get()->groupBy('participant_id');
        $participants = $participants->filter(fn (TrainingParticipant $participant): bool => $facts->get($participant->id, collect())
            ->contains(fn (TrainingParticipationFact $fact): bool => $fact->attendance === AttendanceStatus::Present));
        $events = TrainingEvent::query()->forCompany($tenant, $companyId)
            ->whereIn('id', $participants->pluck('event_id')->all())->get()->keyBy('id');
        $courses = TrainingCourse::query()->forCompany($tenant, $companyId)
            ->whereIn('id', $events->pluck('course_id')->all())->get()->keyBy('id');
        $submissions = TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)
            ->whereIn('participant_id', $participants->pluck('id')->all())->get()->keyBy('participant_id');

        return $participants->map(function (TrainingParticipant $participant) use ($courses, $events, $facts, $submissions): ?array {
            $event = $events->get($participant->event_id);
            if ($event === null) {
                return null;
            }
            $submission = $submissions->get((int) $participant->id);

            return [
                'event_id' => (int) $event->id,
                'title' => (string) ($courses->get($event->course_id)?->title ?? 'Training event'),
                'starts_at' => $event->starts_at,
                'confirmed' => $facts->get($participant->id, collect())->contains(fn (TrainingParticipationFact $fact): bool => $fact->confirmed_at !== null),
                'submitted' => $submission !== null && $submission->status !== 'draft',
                'returned_note' => $submission !== null && $submission->status === 'draft' ? $submission->decision_note : null,
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

    private function authorizeDecision(User $actor, int $companyId): int
    {
        $tenant = $this->tenancy->currentTenantId();
        $currentActor = $actor->exists ? User::query()->find($actor->getKey()) : null;
        if ($tenant === null || $currentActor === null || $currentActor->getCompanyId() !== $actor->getCompanyId()
            || (int) $currentActor->tenant_id !== $tenant || ! $this->companies->mayActFor($actor, $companyId)) {
            $this->deny();
        }
        $this->authorization->authorize(Actor::forUser($actor), self::VERIFY);

        return $tenant;
    }

    private function pendingSubmission(int $tenant, int $companyId, int $submissionId): TrainingEvidenceSubmission
    {
        $submission = TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)
            ->whereKey($submissionId)
            ->lockForUpdate()
            ->first();
        if ($submission === null) {
            $this->deny();
        }
        if ($submission->status === 'confirmed') {
            throw new InvalidTrainingEvidenceSubmissionException('A confirmed submission cannot be returned or decided again.');
        }
        if ($submission->status !== 'pending') {
            throw new InvalidTrainingEvidenceSubmissionException('Only a pending submission can be decided.');
        }

        return $submission;
    }

    private function unconfirmedFact(int $tenant, int $companyId, TrainingEvidenceSubmission $submission): TrainingParticipationFact
    {
        $fact = TrainingParticipationFact::query()->forCompany($tenant, $companyId)
            ->where('participant_id', $submission->participant_id)
            ->lockForUpdate()
            ->first();
        if ($fact === null || $fact->confirmed_at !== null) {
            throw new InvalidTrainingEvidenceSubmissionException('The participation is already confirmed.');
        }

        return $fact;
    }

    private function recordDecision(TrainingEvidenceSubmission $submission, string $decision, ?string $note, int $decidedBy): void
    {
        TrainingEvidenceDecision::query()->create([
            'tenant_id' => $submission->tenant_id,
            'company_entity_id' => $submission->company_entity_id,
            'submission_id' => $submission->id,
            'event_id' => $submission->event_id,
            'participant_id' => $submission->participant_id,
            'decision' => $decision,
            'note' => $note,
            'decided_by_user_id' => $decidedBy,
            'decided_at' => now(),
        ]);
    }

    private function deny(): never
    {
        throw new InvalidTrainingEvidenceSubmissionException('The evidence submission is unavailable in the current scope.');
    }
}
