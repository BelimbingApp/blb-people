<?php

namespace App\Domains\People\Provider\Services;

use App\Domains\People\Provider\Data\WorkforceBootstrapCursor;
use App\Domains\People\Provider\Data\WorkforceChangeCursor;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceBootstrapCursorException;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceChangeCursorException;
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

    /**
     * The instant a resume cursor names, whichever read minted it. A resume
     * cursor is the one value that crosses from the bootstrap seam to the
     * incremental seam, so both kinds decode here and both are tenant-bound.
     */
    public function decodeResume(string $encoded, int $currentTenantId): DateTimeImmutable
    {
        try {
            $payload = $this->decode($encoded);

            if (($payload['v'] ?? null) !== self::VERSION
                || ! in_array($payload['kind'] ?? null, ['bootstrap_complete', 'changes_complete'], true)
                || ! is_int($payload['tenant_id'] ?? null)
                || ! is_string($payload['as_of'] ?? null)) {
                throw InvalidWorkforceChangeCursorException::malformed();
            }

            $asOf = new DateTimeImmutable($payload['as_of']);
        } catch (InvalidWorkforceChangeCursorException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw InvalidWorkforceChangeCursorException::malformed($exception);
        }

        if ($payload['tenant_id'] !== $currentTenantId) {
            throw InvalidWorkforceChangeCursorException::forDifferentTenant();
        }

        return $asOf;
    }

    public function encodeChangeResume(int $tenantId, DateTimeImmutable $asOf): string
    {
        return $this->encode([
            'v' => self::VERSION,
            'kind' => 'changes_complete',
            'tenant_id' => $tenantId,
            'as_of' => $asOf->format(DateTimeInterface::RFC3339_EXTENDED),
        ]);
    }

    public function encodeChangePage(WorkforceChangeCursor $cursor): string
    {
        return $this->encode([
            'v' => self::VERSION,
            'kind' => 'change_page',
            'tenant_id' => $cursor->tenantId,
            'since' => $cursor->since->format('U.u'),
            'started_at' => $cursor->startedAt->format('U.u'),
            'after_employee_id' => $cursor->afterEmployeeId,
            'through_employee_id' => $cursor->throughEmployeeId,
        ]);
    }

    public function decodeChangePage(string $encoded, int $currentTenantId): WorkforceChangeCursor
    {
        try {
            $payload = $this->decode($encoded);

            if (($payload['v'] ?? null) !== self::VERSION
                || ($payload['kind'] ?? null) !== 'change_page'
                || ! is_int($payload['tenant_id'] ?? null)
                || ! is_string($payload['since'] ?? null)
                || ! is_string($payload['started_at'] ?? null)
                || ! is_int($payload['after_employee_id'] ?? null)
                || ! is_int($payload['through_employee_id'] ?? null)) {
                throw InvalidWorkforceChangeCursorException::malformed();
            }

            $since = DateTimeImmutable::createFromFormat('!U.u', $payload['since']);
            $startedAt = DateTimeImmutable::createFromFormat('!U.u', $payload['started_at']);

            if (! $since instanceof DateTimeImmutable || ! $startedAt instanceof DateTimeImmutable) {
                throw InvalidWorkforceChangeCursorException::malformed();
            }

            $cursor = new WorkforceChangeCursor(
                $payload['tenant_id'],
                $since,
                $startedAt,
                $payload['after_employee_id'],
                $payload['through_employee_id'],
            );
        } catch (InvalidWorkforceChangeCursorException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw InvalidWorkforceChangeCursorException::malformed($exception);
        }

        if ($cursor->tenantId !== $currentTenantId) {
            throw InvalidWorkforceChangeCursorException::forDifferentTenant();
        }

        return $cursor;
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
