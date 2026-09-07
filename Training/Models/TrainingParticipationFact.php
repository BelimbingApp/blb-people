<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TrainingParticipationFact extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_participation_facts';

    /**
     * Rows nothing supersedes: the original where no correction exists, and
     * the newest correction where one does.
     *
     * A confirmed fact is immutable, so a correction is an append. Every
     * reader that answers "what happened" wants this scope; a reader that
     * wants the history asks without it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        // A plain builder, not self::query(): this model requires a company
        // scope, and the subquery only names ids to exclude. Ids are unique
        // across companies, so an id superseded elsewhere cannot match a row
        // the outer, properly scoped query returned.
        $query->whereNotIn(
            'id',
            DB::table($this->getTable())->where('supersedes_fact_id', '>', 0)->select('supersedes_fact_id'),
        );
    }

    protected function casts(): array
    {
        return ['attendance' => AttendanceStatus::class,
            'actual_minutes' => 'integer',
            'pre_test' => 'array',
            'post_test' => 'array',
            'evidence_references' => 'array',
            'certificate_valid_from' => 'immutable_date',
            'certificate_valid_until' => 'immutable_date',
            'recorded_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'supersedes_fact_id' => 'integer', ];
    }
}
