<?php

namespace App\Domains\People\Provider\Data;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

final readonly class WorkforceChangePage implements JsonSerializable
{
    /** @param  list<WorkforceUpsert|WorkforceDeactivation>  $changes */
    public function __construct(
        public array $changes,
        public DateTimeImmutable $since,
        public DateTimeImmutable $asOf,
        public ?string $nextPageCursor,
        public ?string $resumeCursor,
        public bool $complete,
    ) {
        foreach ($changes as $change) {
            if (! $change instanceof WorkforceUpsert && ! $change instanceof WorkforceDeactivation) {
                throw new \InvalidArgumentException('Workforce change pages accept only typed workforce changes.');
            }
        }
        if ($since > $asOf) {
            throw new \InvalidArgumentException('A workforce change page cannot end before it begins.');
        }
        if (! $complete && ($nextPageCursor === null || trim($nextPageCursor) === '')) {
            throw new \InvalidArgumentException('An incomplete workforce change page requires a next-page cursor.');
        }
        if ($complete && $nextPageCursor !== null) {
            throw new \InvalidArgumentException('A complete workforce change page cannot have a next-page cursor.');
        }
        if (! $complete && $resumeCursor !== null) {
            throw new \InvalidArgumentException('Only a complete workforce change page may advance the resume cursor.');
        }
        if ($complete && ($resumeCursor === null || trim($resumeCursor) === '')) {
            throw new \InvalidArgumentException('A complete workforce change page requires a resume cursor.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'provider_id' => ExternalReference::PROVIDER_ID,
            'provider_version' => WorkforceBootstrapPage::PROVIDER_VERSION,
            'contract_version' => WorkforceBootstrapPage::CONTRACT_VERSION,
            'since' => $this->since->format(DateTimeInterface::RFC3339_EXTENDED),
            'as_of' => $this->asOf->format(DateTimeInterface::RFC3339_EXTENDED),
            'next_page_cursor' => $this->nextPageCursor,
            'resume_cursor' => $this->resumeCursor,
            'complete' => $this->complete,
            'changes' => array_map(
                static fn (WorkforceUpsert|WorkforceDeactivation $change): array => $change->jsonSerialize(),
                $this->changes,
            ),
        ];
    }
}
