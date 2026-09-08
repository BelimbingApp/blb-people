<?php

namespace App\Domains\People\Training\Models;

use App\Domains\People\Skills\Models\Concerns\CompanyOwned;
use App\Domains\People\Skills\Models\TenantOwnedModel;
use App\Domains\People\Training\Enums\CutoverWorkflow;
use App\Domains\People\Training\Enums\CutoverWriter;

/**
 * One declared cutover window: who may write a workflow between two instants.
 *
 * Append-only at the database: an editable window is a window nobody can
 * prove was in force. ends_at null means until further notice.
 */
final class TrainingCutoverWindow extends TenantOwnedModel
{
    use CompanyOwned;

    protected $table = 'people_training_cutover_windows';

    protected function casts(): array
    {
        return [
            'workflow' => CutoverWorkflow::class,
            'writer' => CutoverWriter::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'declared_by_user_id' => 'integer',
            'declared_at' => 'immutable_datetime',
        ];
    }
}
