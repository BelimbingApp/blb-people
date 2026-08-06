<?php

namespace App\Domains\People\Leave\Exceptions;

use App\Domains\People\Leave\Data\LeaveValidationIssue;
use RuntimeException;

class LeaveRequestValidationException extends RuntimeException
{
    /** @var list<LeaveValidationIssue> */
    public readonly array $issues;

    /** @param list<LeaveValidationIssue> $issues */
    public function __construct(array $issues, string $message = 'Leave request failed validation.')
    {
        parent::__construct($message);
        $this->issues = $issues;
    }
}
