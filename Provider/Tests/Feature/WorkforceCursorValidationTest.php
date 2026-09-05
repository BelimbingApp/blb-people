<?php

use App\Domains\People\Provider\Data\WorkforceBootstrapCursor;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;
use App\Domains\People\Provider\Data\WorkforceChangeCursor;
use App\Domains\People\Provider\Data\WorkforceChangeRequest;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceBootstrapCursorException;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceChangeCursorException;
use App\Domains\People\Provider\Services\WorkforceBootstrapCursorCodec;
use Illuminate\Contracts\Encryption\Encrypter;

function providerCursorToken(mixed $payload): string
{
    return rtrim(strtr(base64_encode(app(Encrypter::class)->encrypt($payload)), '+/', '-_'), '=');
}

function providerCursorPayload(string $kind): array
{
    return match ($kind) {
        'page' => ['v' => 1, 'kind' => 'page', 'tenant_id' => 17, 'after_employee_id' => 3, 'through_employee_id' => 9, 'started_at' => '1788577200.123456'],
        'change_page' => ['v' => 1, 'kind' => 'change_page', 'tenant_id' => 17, 'after_employee_id' => 3, 'through_employee_id' => 9, 'since' => '1788490800.123456', 'started_at' => '1788577200.123456'],
        'bootstrap_complete', 'changes_complete' => ['v' => 1, 'kind' => $kind, 'tenant_id' => 17, 'as_of' => '2026-09-05T03:00:00.123+00:00'],
    };
}

dataset('provider cursor decoders', [
    ['page', 'decodePage', InvalidWorkforceBootstrapCursorException::class],
    ['change_page', 'decodeChangePage', InvalidWorkforceChangeCursorException::class],
    ['bootstrap_complete', 'decodeResume', InvalidWorkforceChangeCursorException::class],
    ['changes_complete', 'decodeResume', InvalidWorkforceChangeCursorException::class],
]);

it('preserves exact page windows and subsecond instants in both page cursor formats', function (): void {
    $codec = app(WorkforceBootstrapCursorCodec::class);
    $since = new DateTimeImmutable('2026-09-04T03:00:00.123456+00:00');
    $start = new DateTimeImmutable('2026-09-05T03:00:00.654321+00:00');
    $bootstrap = new WorkforceBootstrapCursor(17, 3, 9, $start);
    $change = new WorkforceChangeCursor(17, $since, $start, 3, 9);
    expect($codec->decodePage($codec->encodePage($bootstrap), 17))->toEqual($bootstrap)
        ->and($codec->decodeChangePage($codec->encodeChangePage($change), 17))->toEqual($change);
});

it('accepts both completed read kinds as the exact millisecond resume boundary', function (): void {
    $codec = app(WorkforceBootstrapCursorCodec::class);
    $asOf = new DateTimeImmutable('2026-09-05T03:00:00.123+00:00');
    expect($codec->decodeResume($codec->encodeResume(17, $asOf), 17))->toEqual($asOf)
        ->and($codec->decodeResume($codec->encodeChangeResume(17, $asOf), 17))->toEqual($asOf);
});

it('refuses every authentic cursor when used by a different tenant', function ($kind, $method, $exception): void {
    expect(fn () => app(WorkforceBootstrapCursorCodec::class)->$method(providerCursorToken(providerCursorPayload($kind)), 18))
        ->toThrow($exception, 'does not belong to the current tenant');
})->with('provider cursor decoders');

it('refuses authenticated payloads with an unsupported cursor version', function ($kind, $method, $exception): void {
    $payload = providerCursorPayload($kind);
    $payload['v'] = 2;
    expect(fn () => app(WorkforceBootstrapCursorCodec::class)->$method(providerCursorToken($payload), 17))
        ->toThrow($exception, 'cursor is invalid');
})->with('provider cursor decoders');

it('refuses authentic tokens used at the wrong cursor boundary', function ($kind, $method, $exception): void {
    $payload = providerCursorPayload($kind);
    $payload['kind'] = 'unrelated_cursor';
    expect(fn () => app(WorkforceBootstrapCursorCodec::class)->$method(providerCursorToken($payload), 17))
        ->toThrow($exception, 'cursor is invalid');
})->with('provider cursor decoders');

it('rejects malformed encrypted payloads without leaking the encryption exception', function ($kind, $method, $exception): void {
    foreach (['', '*not-base64*', 'authenticated-looking-but-invalid', providerCursorToken('not an array')] as $token) {
        expect(fn () => app(WorkforceBootstrapCursorCodec::class)->$method($token, 17))
            ->toThrow($exception, 'cursor is invalid');
    }
})->with('provider cursor decoders');

it('refuses malformed fields in authenticated page and resume payloads', function ($kind, $method, $exception): void {
    $payload = providerCursorPayload($kind);
    $corruptions = ['tenant_id' => '17'];
    if (str_ends_with($kind, 'complete')) {
        $corruptions['as_of'] = [];
    } else {
        $corruptions += ['after_employee_id' => '3', 'through_employee_id' => '9', 'started_at' => []];
        if ($kind === 'change_page') {
            $corruptions['since'] = [];
        }
    }
    foreach ($corruptions as $field => $invalid) {
        expect(fn () => app(WorkforceBootstrapCursorCodec::class)->$method(providerCursorToken(array_replace($payload, [$field => $invalid])), 17))
            ->toThrow($exception, 'cursor is invalid');
    }
})->with('provider cursor decoders');

it('refuses impossible page dates and ranges as domain cursor failures', function ($kind, $method, $exception): void {
    $payload = providerCursorPayload($kind);
    foreach ([
        ['started_at' => 'not-a-date'],
        ['tenant_id' => 0],
        ['after_employee_id' => -1],
        ['after_employee_id' => 10],
    ] as $invalid) {
        expect(fn () => app(WorkforceBootstrapCursorCodec::class)->$method(providerCursorToken(array_replace($payload, $invalid)), 17))
            ->toThrow($exception, 'cursor is invalid');
    }
})->with([
    ['page', 'decodePage', InvalidWorkforceBootstrapCursorException::class],
    ['change_page', 'decodeChangePage', InvalidWorkforceChangeCursorException::class],
]);

it('refuses a change window whose resume instant follows its start', function (): void {
    $payload = providerCursorPayload('change_page');
    $payload['since'] = '1788577201.123456';
    expect(fn () => app(WorkforceBootstrapCursorCodec::class)->decodeChangePage(providerCursorToken($payload), 17))
        ->toThrow(InvalidWorkforceChangeCursorException::class, 'cursor is invalid');
});

it('refuses invalid resume dates as domain cursor failures', function (): void {
    $payload = providerCursorPayload('bootstrap_complete');
    $payload['as_of'] = 'not-a-date';
    expect(fn () => app(WorkforceBootstrapCursorCodec::class)->decodeResume(providerCursorToken($payload), 17))
        ->toThrow(InvalidWorkforceChangeCursorException::class, 'cursor is invalid');
});

it('rejects invalid bootstrap request bounds and blank page cursors before reading', function (): void {
    foreach ([0, 1001] as $limit) {
        expect(fn () => new WorkforceBootstrapRequest(limit: $limit))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => new WorkforceBootstrapRequest('  '))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid change request bounds and blank required or optional cursors before reading', function (): void {
    foreach ([0, 1001] as $limit) {
        expect(fn () => new WorkforceChangeRequest('resume', limit: $limit))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => new WorkforceChangeRequest('  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new WorkforceChangeRequest('resume', '  '))->toThrow(InvalidArgumentException::class);
});
