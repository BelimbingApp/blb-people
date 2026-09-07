<?php

namespace App\Domains\People\Skills\Services;

use App\Domains\People\Skills\Import\WorkbookDefect;
use RuntimeException;

/** Aborts an assessment-log apply on one row; carries the defect, never a cell value. */
final class RefusedAssessmentLogRow extends RuntimeException
{
    public function __construct(public readonly WorkbookDefect $defect)
    {
        parent::__construct($defect->kind.' at '.$defect->cell);
    }
}
