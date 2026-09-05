<?php

use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\People\Settings\Livewire\Index;
use App\Domains\People\Settings\Models\PeopleImportJob;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use Livewire\Livewire;

afterEach(fn () => app(TenantContext::class)->clear());

it('creates a scoped reference entry with source fields and resets the form', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    Livewire::test(Index::class)->set('showReferenceEntryModal', true)
        ->set('entryCode', 'qa')->set('entryName', 'Quality assurance')
        ->set('entryLevel', '2')->set('entrySourceLabel', 'Approved setup')
        ->call('createReferenceEntry')->assertHasNoErrors()
        ->assertDispatched('notify', variant: 'success')->assertSet('showReferenceEntryModal', false)
        ->assertSet('entryCode', '')->assertSet('entrySourceLabel', null);
    $entry = PeopleReferenceEntry::query()->where('company_id', $user->company_id)->where('code', 'QA')->sole();
    expect($entry->name)->toBe('Quality assurance')->and($entry->type)->toBe('cost_center')
        ->and($entry->level)->toBe('2')->and($entry->source_system)->toBe('manual')
        ->and($entry->source_label)->toBe('Approved setup')->and($entry->source_code)->toBe('qa');
});

it('validates reference type before creating an entry', function (): void {
    $this->actingAs(createAdminUser());
    $before = PeopleReferenceEntry::query()->count();
    Livewire::test(Index::class)->set('entryCode', 'qa')->set('entryName', 'Quality')
        ->set('referenceType', 'unrecognized')->call('createReferenceEntry')
        ->assertHasErrors(['referenceType' => 'in']);
    expect(PeopleReferenceEntry::query()->count())->toBe($before);
});

it('records an explicitly empty dry run with the actor and company without importing data', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);
    $before = PeopleReferenceEntry::query()->get()->toArray();
    Livewire::test(Index::class)->call('dryRunSampleImport')
        ->assertDispatched('notify', message: 'Empty dry-run import recorded. Upload parsing is intentionally scoped to dedicated import jobs.', variant: 'success');
    $job = PeopleImportJob::query()->where('company_id', $user->company_id)->sole();
    expect($job->created_by_user_id)->toBe($user->id)->and($job->dry_run)->toBeTrue()
        ->and($job->target_type)->toBe('cost_center')->and($job->row_results)->toBe([])
        ->and(PeopleReferenceEntry::query()->get()->toArray())->toBe($before);
});

it('denies settings mutations without manage capability before writing', function (string $action): void {
    $admin = createAdminUser();
    $this->actingAs(User::factory()->create(['company_id' => $admin->company_id]));
    $entries = PeopleReferenceEntry::query()->get()->toArray();
    $jobs = PeopleImportJob::query()->get()->toArray();
    expect(fn () => Livewire::test(Index::class)->set('entryCode', 'forbidden')
        ->set('entryName', 'Forbidden')->call($action))->toThrow(AuthorizationDeniedException::class);
    expect(PeopleReferenceEntry::query()->get()->toArray())->toBe($entries)
        ->and(PeopleImportJob::query()->get()->toArray())->toBe($jobs);
})->with(['createReferenceEntry', 'dryRunSampleImport']);

it('allows known settings tabs and refuses arbitrary navigation', function (): void {
    $this->actingAs(createAdminUser());
    Livewire::test(Index::class)->call('setTab', 'imports')->assertSet('tab', 'imports')
        ->call('setTab', 'unrecognized')->assertSet('tab', 'imports');
});
