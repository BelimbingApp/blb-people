<?php

use App\Base\Authz\Models\PrincipalRole;
use App\Base\Database\Exceptions\DevSeederProductionEnvironmentException;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Training\Database\Seeders\Dev\DevTrainingSeeder;
use App\Domains\People\Training\Enums\TrainingEventStatus;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingEventAuditEvent;
use App\Domains\People\Training\Models\TrainingParticipant;
use Illuminate\Support\Facades\Notification;

test('development training examples survive repeated runs without overwriting edits or expanding access', function (): void {
    $this->app['env'] = 'local';
    $this->travelTo(now()->setDate(2026, 9, 8)->setTime(12, 0));
    Notification::fake();
    $company = app(PrimaryCompanyManager::class)->platformOperatorCompany();
    app(TenantContext::class)->set((int) $company->tenant_id);
    app(TimezoneSettings::class)->setCompanyTimezone((int) $company->id, 'Asia/Kuala_Lumpur');
    $rolesBefore = PrincipalRole::query()->get()->toArray();
    $this->seed(DevTrainingSeeder::class);

    $courses = TrainingCourse::query()->withoutCompanyScope('Verify all seeder writes across companies')->orderBy('code')->get();
    expect($courses->pluck('code')->all())->toBe([
        'demo-customer-service', 'demo-excel-fundamentals', 'demo-safety-induction',
    ]);
    foreach ($courses as $course) {
        expect((int) $course->tenant_id)->toBe((int) $company->tenant_id)
            ->and((int) $course->company_entity_id)->toBe((int) $company->id)
            ->and($course->skillIds())->toHaveCount(1);
        $skill = Skill::query()->forCompany((int) $company->tenant_id, (int) $company->id)->findOrFail($course->skillIds()[0]);
        expect((int) $skill->company_entity_id)->toBe((int) $company->id);
    }
    expect($courses->pluck('delivery_mode')->map(fn ($mode) => $mode->value)->all())
        ->toBe(['coaching', 'elearning', 'internal_classroom']);

    $events = TrainingEvent::query()->withoutCompanyScope('Verify all seeder writes across companies')->orderBy('id')->get();
    expect($events)->toHaveCount(3);
    foreach ($events as $event) {
        $organizer = Employee::findOrFail($event->organizer_employee_entity_id);
        expect($event->status)->toBe(TrainingEventStatus::Scheduled)
            ->and($event->ends_at->greaterThan($event->starts_at))->toBeTrue()
            ->and($event->starts_at->isFuture())->toBeTrue()
            ->and($event->starts_at->copy()->setTimezone('Asia/Kuala_Lumpur')->format('H:i'))->toBe('09:00')
            ->and($event->capacity)->toBe(12)
            ->and($organizer->employee_number)->toBe('DEMO-TRAINING-ORGANIZER')
            ->and((int) $organizer->company_id)->toBe((int) $company->id);
    }

    $courses[0]->update(['title' => 'DEMO - Edited during walkthrough']);
    $courses[1]->update(['active' => false]);
    $events[0]->update(['venue' => 'DEMO - User chosen room']);
    $courseState = TrainingCourse::query()->withoutCompanyScope('Verify all seeder writes across companies')->orderBy('id')->get()->toArray();
    $eventState = TrainingEvent::query()->withoutCompanyScope('Verify all seeder writes across companies')->orderBy('id')->get()->toArray();
    $audits = TrainingEventAuditEvent::query()->withoutCompanyScope('Verify all seeder writes across companies')->get()->toArray();
    $this->travel(40)->days();
    $this->seed(DevTrainingSeeder::class);

    expect(TrainingCourse::query()->withoutCompanyScope('Verify all seeder writes across companies')->orderBy('id')->get()->toArray())->toBe($courseState)
        ->and(TrainingEvent::query()->withoutCompanyScope('Verify all seeder writes across companies')->orderBy('id')->get()->toArray())->toBe($eventState)
        ->and(TrainingEventAuditEvent::query()->withoutCompanyScope('Verify all seeder writes across companies')->get()->toArray())->toBe($audits)
        ->and(Skill::query()->withoutCompanyScope('Verify all seeder writes across companies')->count())->toBe(3)
        ->and(SkillCategory::query()->withoutCompanyScope('Verify all seeder writes across companies')->count())->toBe(1)
        ->and(Employee::query()->where('employee_number', 'DEMO-TRAINING-ORGANIZER')->count())->toBe(1)
        ->and(PrincipalRole::query()->get()->toArray())->toBe($rolesBefore)
        ->and(TrainingParticipant::query()->withoutCompanyScope('Verify all seeder writes across companies')->count())->toBe(0);
    Notification::assertNothingSent();
    $this->travelBack();
});

test('training seeder targets the operator tenant and restores the calling tenant context', function (): void {
    $this->app['env'] = 'local';
    [$otherTenant, $otherCompany] = createTenantWithCompany();
    $context = app(TenantContext::class);
    $context->set((int) $otherTenant->id);
    $company = app(PrimaryCompanyManager::class)->platformOperatorCompany();
    expect($company->id)->not->toBe($otherCompany->id);

    $this->seed(DevTrainingSeeder::class);

    expect($context->currentTenantId())->toBe((int) $otherTenant->id)
        ->and(TrainingCourse::query()->withoutCompanyScope('Verify all seeder writes across companies')->pluck('company_entity_id')->unique()->all())->toBe([$company->id])
        ->and(Skill::query()->withoutCompanyScope('Verify all seeder writes across companies')->pluck('tenant_id')->unique()->all())->toBe([$company->tenant_id])
        ->and(TrainingEvent::query()->withoutCompanyScope('Verify all seeder writes across companies')->pluck('company_entity_id')->unique()->all())->toBe([$company->id])
        ->and(Employee::query()->where('employee_number', 'DEMO-TRAINING-ORGANIZER')->value('company_id'))->toBe($company->id);
});

test('training demo data refuses non-local environments before writing', function (string $environment): void {
    $this->app['env'] = $environment;
    expect(fn () => app(DevTrainingSeeder::class)->run())
        ->toThrow(DevSeederProductionEnvironmentException::class);
    expect(TrainingCourse::query()->withoutCompanyScope('Verify all seeder writes across companies')->count())->toBe(0)
        ->and(SkillCategory::query()->withoutCompanyScope('Verify all seeder writes across companies')->count())->toBe(0)
        ->and(Employee::query()->where('employee_number', 'DEMO-TRAINING-ORGANIZER')->exists())->toBeFalse();
})->with(['production', 'staging', 'testing']);
