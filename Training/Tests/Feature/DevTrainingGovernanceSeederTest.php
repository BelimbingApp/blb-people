<?php

use App\Base\Authz\Models\PrincipalRole;
use App\Base\Database\Exceptions\DevSeederProductionEnvironmentException;
use App\Base\Media\Models\MediaAsset;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\User\Models\User;
use App\Domains\People\Training\Database\Seeders\Dev\DevTrainingGovernanceSeeder;
use App\Domains\People\Training\Enums\TrainingRequestStatus;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingEvidenceSubmission;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingEvidenceSubmissionStore;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

afterEach(function (): void {
    $this->travelBack();
});

beforeEach(function (): void {
    Storage::fake('local');
    setupAuthzRoles();
});

test('local HR examples use audited workflows and preserve decisions and real access on replay', function (): void {
    $this->app['env'] = 'local';
    Notification::fake();
    $dispatcher = Notification::getFacadeRoot();
    $company = app(PrimaryCompanyManager::class)->platformOperatorCompany();
    $tenant = (int) $company->tenant_id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenant);
    $roles = PrincipalRole::query()->get()->toArray();
    $users = User::query()->get()->toArray();
    $this->seed(DevTrainingGovernanceSeeder::class);
    $request = TrainingRequest::query()->forCompany($tenant, $companyId)->sole();
    $evidence = TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)->sole();
    $hr = User::query()->where('email', "training-{$companyId}-hr@demo.invalid")->sole();
    expect($request->status)->toBe(TrainingRequestStatus::PendingHr)
        ->and($request->decisions()->pluck('decision')->all())->toBe(['created', 'submitted', 'hod_recommended'])
        ->and($evidence->status)->toBe('pending')
        ->and($evidence->certificate_number)->toBe('DEMO-NOT-A-QUALIFICATION')
        ->and(app(TrainingEvidenceSubmissionStore::class)->pendingQueue($hr, $companyId)->pluck('id')->all())->toBe([$evidence->id])
        ->and(Notification::getFacadeRoot())->toBe($dispatcher);
    $asset = MediaAsset::findOrFail($evidence->document_asset_id);
    expect(Storage::disk($asset->disk)->get($asset->storage_key))->toContain('DEMO ONLY - SYNTHETIC TRAINING EVIDENCE');
    expect(PrincipalRole::query()->whereNotIn('principal_id', User::query()->where('email', 'like', '%@demo.invalid')->pluck('id'))->get()->toArray())->toBe($roles);
    foreach ($users as $user) {
        expect(User::findOrFail($user['id'])->toArray())->toBe($user);
    }
    app(TrainingRequestStore::class)->review($hr, $companyId, $request->id, 'DEMO - Reviewed by walkthrough user');
    app(TrainingEvidenceSubmissionStore::class)->returnToEmployee($hr, $companyId, $evidence->id, 'DEMO - Please clarify the practice example');
    $requests = TrainingRequest::query()->forCompany($tenant, $companyId)->get()->toArray();
    $submissions = TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)->get()->toArray();
    $events = TrainingEvent::query()->forCompany($tenant, $companyId)->get()->toArray();
    $assets = MediaAsset::query()->get()->toArray();
    $allRoles = PrincipalRole::query()->get()->toArray();
    $this->travel(40)->days();
    $this->seed(DevTrainingGovernanceSeeder::class);
    expect(TrainingRequest::query()->forCompany($tenant, $companyId)->get()->toArray())->toBe($requests)
        ->and(TrainingEvidenceSubmission::query()->forCompany($tenant, $companyId)->get()->toArray())->toBe($submissions)
        ->and(TrainingEvent::query()->forCompany($tenant, $companyId)->get()->toArray())->toBe($events)
        ->and(MediaAsset::query()->get()->toArray())->toBe($assets)
        ->and(PrincipalRole::query()->get()->toArray())->toBe($allRoles)
        ->and(Notification::getFacadeRoot())->toBe($dispatcher);
    $this->travelBack();
});

test('HR demo seeder rejects nonlocal environments before creating actors or media', function (string $environment): void {
    $this->app['env'] = $environment;
    $users = User::query()->get()->toArray();
    $assets = MediaAsset::query()->get()->toArray();
    expect(fn () => app(DevTrainingGovernanceSeeder::class)->run())->toThrow(DevSeederProductionEnvironmentException::class);
    expect(User::query()->get()->toArray())->toBe($users)
        ->and(MediaAsset::query()->get()->toArray())->toBe($assets);
})->with(['production', 'staging', 'testing']);

test('HR fixture writes stay in the operator company and restore the callers tenant', function (): void {
    $this->app['env'] = 'local';
    [$otherTenant] = createTenantWithCompany();
    $company = app(PrimaryCompanyManager::class)->platformOperatorCompany();
    app(TenantContext::class)->set((int) $otherTenant->id);
    $this->seed(DevTrainingGovernanceSeeder::class);
    expect(app(TenantContext::class)->currentTenantId())->toBe((int) $otherTenant->id);
    foreach ([TrainingRequest::class, TrainingEvidenceSubmission::class] as $model) {
        $rows = $model::query()->withoutCompanyScope('Verify no HR fixture writes escape operator company')->get();
        expect($rows->pluck('tenant_id')->unique()->all())->toBe([$company->tenant_id])
            ->and($rows->pluck('company_entity_id')->unique()->all())->toBe([$company->id]);
    }
});
