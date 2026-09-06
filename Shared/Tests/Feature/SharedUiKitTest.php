<?php

use App\Domains\People\Shared\Livewire\AsOfDatePicker;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Shared People UI kit (#235, plan 0005-h)
|--------------------------------------------------------------------------
|
| Presentational components only: proficiency badges (published standing),
| requirement version pills (pinned versions), and the as-of date picker
| that emits the date the read services expect. No data access lives in
| these components; every test below renders from explicit props.
*/

it('never renders unpublished standing in the proficiency badge', function (): void {
    $html = Blade::render(
        '<x-people-standing-badge :skill="$skill" :assessed-level="2" :required-level="4" gap="2" result-band="below requirement" :published="false" />',
        ['skill' => 'Welding'],
    );

    expect(trim($html))->toBe('');
});

it('renders published standing with band label and levels', function (): void {
    $html = Blade::render(
        '<x-people-standing-badge :skill="$skill" :assessed-level="2" :required-level="4" gap="2" result-band="below requirement" :published="true" assessed-at="2026-08-20" />',
        ['skill' => 'Welding'],
    );

    expect($html)->toContain('Welding')
        ->and($html)->toContain('below requirement')
        ->and($html)->toContain('2')
        ->and($html)->toContain('4');
});

it('renders the requirement version pill with pinned version and link', function (): void {
    $html = Blade::render(
        '<x-people-requirement-version-pill reference="welding.l3" :version="7" url="https://example.test/profiles/9" status="published" />',
    );

    expect($html)->toContain('welding.l3')
        ->and($html)->toContain('v7')
        ->and($html)->toContain('https://example.test/profiles/9');
});

it('emits the chosen as-of date for the read services', function (): void {
    Livewire::test(AsOfDatePicker::class)
        ->set('date', '2026-08-20')
        ->assertDispatched('standing-as-of-changed', '2026-08-20')
        ->assertHasNoErrors();
});

it('rejects a future as-of date without emitting', function (): void {
    Livewire::test(AsOfDatePicker::class)
        ->set('date', now()->addDay()->toDateString())
        ->assertHasErrors(['date'])
        ->assertNotDispatched('standing-as-of-changed');
});
