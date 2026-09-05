<?php

namespace App\Domains\People\Performance\Models;

use App\Domains\People\Performance\Enums\JobDescriptionStatus;
use App\Domains\People\Performance\Exceptions\JobDescriptionException;
use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;

/** One immutable published version of a company-owned job description. */
class JobDescription extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_job_descriptions';

    protected static function booted(): void
    {
        static::updating(function (JobDescription $description): void {
            $original = JobDescriptionStatus::from((string) $description->getRawOriginal('status'));

            if ($original === JobDescriptionStatus::Draft) {
                return;
            }

            $dirty = array_keys($description->getDirty());
            if ($original === JobDescriptionStatus::Published
                && $description->status === JobDescriptionStatus::Superseded
                && array_diff($dirty, ['status', 'superseded_at', 'updated_at']) === []) {
                return;
            }

            throw new JobDescriptionException('A published job-description version cannot be modified; publish a replacement version.');
        });

        static::deleting(function (JobDescription $description): void {
            if ($description->status !== JobDescriptionStatus::Draft) {
                throw new JobDescriptionException('A published job-description version cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'position_version' => 'integer',
            'status' => JobDescriptionStatus::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
            'responsibilities' => 'array',
            'duties' => 'array',
            'qualifications' => 'array',
            'competency_links' => 'array',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
