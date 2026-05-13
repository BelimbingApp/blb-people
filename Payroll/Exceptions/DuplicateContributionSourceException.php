<?php

namespace App\Modules\People\Payroll\Exceptions;

use App\Base\Foundation\Exceptions\BlbInvariantViolationException;

class DuplicateContributionSourceException extends BlbInvariantViolationException
{
    public function __construct(string $sourceType, int $sourceId, string $payItemCode, string $periodAnchor)
    {
        parent::__construct(
            "A payroll contribution already exists for {$sourceType}#{$sourceId} pay_item={$payItemCode} period={$periodAnchor}.",
            context: [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'pay_item_code' => $payItemCode,
                'period_anchor' => $periodAnchor,
            ],
        );
    }
}
