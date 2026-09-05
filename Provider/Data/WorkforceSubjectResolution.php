<?php

namespace App\Domains\People\Provider\Data;

use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use Illuminate\Database\Eloquent\Model;

final readonly class WorkforceSubjectResolution
{
    private function __construct(
        public ?Model $record,
        public ?WorkforceSubjectRefusal $refusal,
    ) {}

    public static function resolved(Model $record): self
    {
        return new self($record, null);
    }

    public static function refused(WorkforceSubjectRefusal $reason): self
    {
        return new self(null, $reason);
    }
}
