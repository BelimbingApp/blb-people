<?php

namespace App\Domains\People\Provider\Services;

use App\Domains\People\Provider\Data\WorkforceBootstrapCursor;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceBootstrapCursorException;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Encryption\Encrypter;

final class WorkforceBootstrapCursorCodec
{
    private const VERSION = 1;

    public function __construct(private readonly Encrypter $encrypter) {}

    public function encodePage(WorkforceBootstrapCursor $cursor): string
    {
        return $this->encode([
            'v' => self::VERSION,
            'kind' => 'page',
            'tenant_id' => $cursor->tenantId,
            'after_employee_id' => $cursor->afterEmployeeId,
            'through_employee_id' => $cursor->throughEmployeeId,
            'started_at' => $cursor->startedAt->format('U.u'),
        ]);
    }

    public function decodePage(string $encoded, int $currentTenantId): WorkforceBootstrapCursor
    {
        try {
            $payload = $this->decode($encoded);

            if (($payload['v'] ?? null) !== self::VERSION
                || ($payload['kind'] ?? null) !== 'page'
                || ! is_int($payload['tenant_id'] ?? null)
                || ! is_int($payload['after_employee_id'] ?? null)
                || ! is_int($payload['through_employee_id'] ?? null)
                || ! is_string($payload['started_at'] ?? null)) {
                throw InvalidWorkforceBootstrapCursorException::malformed();
            }

            $startedAt = DateTimeImmutable::createFromFormat('!U.u', $payload['started_at']);

            if (! $startedAt instanceof DateTimeImmutable) {
                throw InvalidWorkforceBootstrapCursorException::malformed();
            }

            $cursor = new WorkforceBootstrapCursor(
                $payload['tenant_id'],
                $payload['after_employee_id'],
                $payload['through_employee_id'],
                $startedAt,
            );
        } catch (InvalidWorkforceBootstrapCursorException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw InvalidWorkforceBootstrapCursorException::malformed($exception);
        }

        if ($cursor->tenantId !== $currentTenantId) {
            throw InvalidWorkforceBootstrapCursorException::forDifferentTenant();
        }

        return $cursor;
    }

    public function encodeResume(int $tenantId, DateTimeImmutable $asOf): string
    {
        return $this->encode([
            'v' => self::VERSION,
            'kind' => 'bootstrap_complete',
            'tenant_id' => $tenantId,
            'as_of' => $asOf->format(DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    /** @param array<string, int|string> $payload */
    private function encode(array $payload): string
    {
        try {
            $encrypted = $this->encrypter->encrypt($payload);
        } catch (\Throwable $exception) {
            throw InvalidWorkforceBootstrapCursorException::malformed($exception);
        }

        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function decode(string $encoded): array
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            throw InvalidWorkforceBootstrapCursorException::malformed();
        }

        $padding = (4 - strlen($encoded) % 4) % 4;
        $encrypted = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', $padding), true);

        if ($encrypted === false) {
            throw InvalidWorkforceBootstrapCursorException::malformed();
        }

        try {
            $payload = $this->encrypter->decrypt($encrypted);
        } catch (\Throwable $exception) {
            throw InvalidWorkforceBootstrapCursorException::malformed($exception);
        }

        if (! is_array($payload)) {
            throw InvalidWorkforceBootstrapCursorException::malformed();
        }

        return $payload;
    }
}
