<?php

use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Leave\Models\LeaveType;
use Illuminate\Support\Facades\Artisan;

/*
 * Every console command this domain ships runs inside one tenant (#316).
 *
 * The audit `blb:domain-commands --audit` (belimbing#710) is the platform's
 * check; this is the domain's own, so a command added here without the base
 * class fails in this repository's suite before the composed audit sees it.
 * Self-contained: helpers are prefixed tenantScoped and live here.
 */

/** @return list<string> every console command class under this domain */
function tenantScopedCommandClasses(): array
{
    $root = realpath(__DIR__.'/../../..');
    $classes = [];

    foreach (glob($root.'/*/Console/Commands/*Command.php') as $file) {
        $relative = substr($file, strlen($root) + 1, -4);
        $classes[] = 'App\\Domains\\People\\'.str_replace('/', '\\', $relative);
    }

    sort($classes);

    return $classes;
}

/** @return array<string, string> command name => class */
function tenantScopedCommandNames(): array
{
    $names = [];

    foreach (tenantScopedCommandClasses() as $class) {
        $names[app($class)->getName()] = $class;
    }

    return $names;
}

test('every console command extends TenantScopedCommand', function (): void {
    $plain = array_filter(tenantScopedCommandClasses(), static fn (string $class): bool => ! is_subclass_of($class, TenantScopedCommand::class));

    expect(array_values($plain))->toBe([]);
});

test('a command run without --tenant exits non-zero before its handler runs', function (string $name): void {
    $exit = Artisan::call($name, tenantScopedArguments($name));

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('--tenant')
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
})->with(array_keys(tenantScopedCommandNames()));

test('a command run with an unknown tenant exits non-zero with the base message', function (string $name): void {
    $exit = Artisan::call($name, ['--tenant' => 999_999] + tenantScopedArguments($name));

    expect($exit)->not->toBe(0)
        ->and(Artisan::output())->toContain('999999')
        ->and(app(TenantContext::class)->hasTenant())->toBeFalse();
})->with(array_keys(tenantScopedCommandNames()));

test('a writing command refused for tenancy leaves no side effect', function (): void {
    $before = LeaveType::query()->count();

    // seed-sbg-pack writes leave types; without a tenant it must write none.
    expect(Artisan::call('blb:leave:seed-sbg-pack'))->not->toBe(0)
        ->and(LeaveType::query()->count())->toBe($before);
});

/**
 * The arguments each command needs to get past its own input validation, so
 * a refusal here is the tenant guard's and not a missing-argument error.
 *
 * @return array<string, mixed>
 */
function tenantScopedArguments(string $name): array
{
    return match ($name) {
        'blb:attendance:roster' => ['action' => 'validate', '--company' => 1, '--from' => '2026-01-01', '--to' => '2026-01-31'],
        'blb:attendance:policy:simulate' => ['policy' => 'none', '--company' => 1],
        'blb:attendance:policy:validate' => ['policy' => 'none', '--company' => 1],
        'blb:claim:policy:simulate' => ['employee' => '1', 'line' => '1', 'date' => '2026-01-01', 'amount' => '1'],
        'blb:claim:policy:validate' => ['policy' => 'none', '--company' => 1],
        'people:skills-workbook-dry-run' => ['workbook' => __FILE__],
        'people:performance:cutover-check', 'people:performance:overdue', 'people:reminders-due',
        'people:training:effectiveness-due', 'people:training:evaluations-due' => ['--company' => 1],
        default => [],
    };
}
