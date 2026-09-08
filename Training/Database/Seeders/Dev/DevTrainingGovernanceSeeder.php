<?php

namespace App\Domains\People\Training\Database\Seeders\Dev;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Seeders\DevSeeder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Settings\Models\EmployeePortalAccess;
use App\Domains\People\Settings\Models\EmployeeWorkProfile;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Data\TrainingRequestDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Enums\TrainingNeedSource;
use App\Domains\People\Training\Enums\TrainingPriority;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Models\TrainingRequest;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingEvidenceSubmissionStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use App\Domains\People\Training\Services\TrainingRequestStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Synthetic local HR decisions, built through the same stores as the UI. */
final class DevTrainingGovernanceSeeder extends DevSeeder
{
    protected array $dependencies = [DevTrainingSeeder::class];

    protected function seed(): void
    {
        $company = $this->operatorPrimaryCompany();
        if ($company === null) {
            return;
        }
        $tenant = app(TenantContext::class)->requireTenantId();
        $companyId = (int) $company->id;
        // Also supports directly invoking this seeder after a local reset.
        $this->call(DevTrainingSeeder::class);
        $course = TrainingCourse::query()->forCompany($tenant, $companyId)
            ->where('code', 'demo-safety-induction')->first();
        if ($course === null || ! $course->active) {
            return;
        }

        // Scope notification suppression to fixture construction, restoring the
        // original dispatcher even on failure. No demo mail/database notices.
        $notifications = Notification::getFacadeRoot();
        Notification::fake();
        try {
            [$learner, $hod, $hr, $department] = DB::transaction(fn () => $this->personas($companyId));
            $this->request($tenant, $companyId, $learner, $hod, $department);
            $this->evidence($tenant, $companyId, $learner, $hr, $course);
        } finally {
            Notification::swap($notifications);
        }
    }

    private function personas(int $companyId): array
    {
        $department = PeopleReferenceEntry::query()->firstOrCreate([
            'company_id' => $companyId, 'type' => PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT,
            'code' => 'demo-training-team',
        ], ['name' => 'DEMO - Learning Team', 'status' => PeopleReferenceEntry::STATUS_ACTIVE]);
        $actors = [];
        foreach (['learner' => 'people_employee', 'hod' => 'people_hod', 'hr' => 'people_hr'] as $persona => $role) {
            $employee = Employee::query()->firstOrCreate([
                'company_id' => $companyId, 'employee_number' => 'DEMO-TRAINING-'.strtoupper($persona),
            ], [
                'full_name' => 'DEMO - Training '.ucfirst($persona), 'short_name' => 'DEMO '.ucfirst($persona),
                'designation' => 'Synthetic HR walkthrough persona', 'employee_type' => 'full_time',
                'status' => 'active', 'employment_start' => now()->subMonth()->toDateString(),
                'metadata' => ['scenario' => 'training-governance-dev'],
            ]);
            EmployeeWorkProfile::query()->firstOrCreate(['employee_id' => $employee->id], [
                'organization_unit_id' => $department->id,
            ]);
            // Reserved .invalid addresses and unknown random passwords: these
            // are audit actors, not published demo login credentials.
            $user = User::query()->firstOrCreate([
                'email' => "training-{$companyId}-{$persona}@demo.invalid",
            ], [
                'company_id' => $companyId, 'employee_id' => $employee->id,
                'name' => $employee->full_name, 'password' => Str::random(64),
            ]);
            if ($user->wasRecentlyCreated) {
                PrincipalRole::query()->create([
                    'company_id' => $companyId, 'principal_type' => PrincipalType::USER->value,
                    'principal_id' => $user->id,
                    'role_id' => Role::query()->whereNull('company_id')->where('code', $role)->sole()->id,
                ]);
                EmployeePortalAccess::query()->create([
                    'employee_id' => $employee->id, 'user_id' => $user->id,
                    'display_name' => $employee->full_name, 'status' => EmployeePortalAccess::STATUS_ACTIVE,
                    'metadata' => ['scenario' => 'training-governance-dev'],
                ]);
            }
            $actors[] = $user;
        }

        return [...$actors, $department];
    }

    private function request(int $tenant, int $companyId, User $learner, User $hod, PeopleReferenceEntry $department): void
    {
        // Any retained request by this isolated persona is the walkthrough's
        // request. Never reopen a reviewed/cancelled request or overwrite edits.
        if (TrainingRequest::query()->forCompany($tenant, $companyId)
            ->where('created_by_user_id', $learner->id)->exists()) {
            return;
        }
        DB::transaction(function () use ($tenant, $companyId, $learner, $hod, $department): void {
            $store = app(TrainingRequestStore::class);
            $request = $store->create($learner, $companyId, new TrainingRequestDraft(
                requestor: new WorkforceSubject($tenant, $companyId, WorkforceResourceType::Employee, (string) $learner->employee_id, new ExternalReference(WorkforceResourceType::Employee, (string) $learner->employee_id)),
                department: new WorkforceSubject($tenant, $companyId, WorkforceResourceType::OrganizationUnit, (string) $department->id),
                needSource: TrainingNeedSource::PerformanceImprovement,
                need: 'DEMO - Practise safe incident reporting before joining the fictional workshop team.',
                learningObjective: 'DEMO - Identify three sample hazards and complete a fictional incident report.',
                expectedResult: 'DEMO - Learner explains the escalation steps in a supervised role-play; no real qualification is awarded.',
                priority: TrainingPriority::Medium, estimatedCost: '250.0000',
            ));
            $store->submit($learner, $companyId, (int) $request->id);
            $store->recommend($hod, $companyId, (int) $request->id,
                'DEMO - Team lead recommends the practice session. HR: review the learning objective and proposed cost.');
        });
    }

    private function evidence(int $tenant, int $companyId, User $learner, User $hr, TrainingCourse $source): void
    {
        $course = TrainingCourse::query()->forCompany($tenant, $companyId)
            ->where('code', 'demo-evidence-practice')->first()
            ?? app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
                code: 'demo-evidence-practice', title: 'DEMO - Safety Reporting Practice (synthetic history)',
                deliveryMode: DeliveryMode::InternalClassroom, skillIds: $source->skillIds(),
                description: 'DEMO ONLY: fictional attended session demonstrating evidence review. Not a qualification or real attendance.',
            ));
        if (! $course->active || TrainingEvent::query()->forCompany($tenant, $companyId)->where('course_id', $course->id)->exists()) {
            return;
        }
        $startsAt = CarbonImmutable::now()->subDays(2)->startOfHour();
        $events = app(TrainingEventStore::class);
        $participation = app(TrainingParticipationStore::class);
        DB::transaction(function () use ($companyId, $tenant, $learner, $hr, $course, $startsAt, $events, $participation): void {
            $event = CarbonImmutable::withTestNow($startsAt->subDay(), fn () => $events->schedule($companyId, new TrainingEventDraft(
                courseId: (int) $course->id, startsAt: $startsAt, endsAt: $startsAt->addHours(2), capacity: 4,
                organizerEmployeeEntityId: (int) $hr->employee_id,
                venue: 'DEMO - Fictional practice room', externalTrainerName: 'DEMO - Training Facilitator',
            ), (int) $hr->id));
            CarbonImmutable::withTestNow($startsAt, fn () => $events->start($companyId, (int) $event->id, (int) $hr->id));
            $session = $participation->defineSession($hr, $companyId, (int) $event->id,
                'demo-evidence-practice', $startsAt, $startsAt->addHours(2));
            $participation->recordAttendance($hr, $companyId, (int) $session->id,
                new WorkforceSubject($tenant, $companyId, WorkforceResourceType::Employee, (string) $learner->employee_id, new ExternalReference(WorkforceResourceType::Employee, (string) $learner->employee_id)),
                new ParticipationFactDraft(AttendanceStatus::Present, 120, 'manual', 'demo-synthetic-attendance'));
            $events->complete($companyId, (int) $event->id, 'DEMO - Synthetic local exercise only; no real attendance or qualification.', (int) $hr->id);

            $path = tempnam(sys_get_temp_dir(), 'blb-demo-evidence-');
            try {
                file_put_contents($path, "DEMO ONLY - SYNTHETIC TRAINING EVIDENCE\nFictional learner practised reporting three sample hazards.\nThis document certifies nothing and records no real training.\nHR walkthrough: review the reflection and confirm or return this sample.\n");
                app(TrainingEvidenceSubmissionStore::class)->submit($learner, $companyId, (int) $event->id,
                    'DEMO - I practised identifying three fictional hazards and writing a sample incident report. I will use the reporting checklist in the next role-play. HR: review this synthetic evidence, then confirm or return it with feedback.',
                    'DEMO-NOT-A-QUALIFICATION', null,
                    new UploadedFile($path, 'DEMO-synthetic-training-evidence.txt', 'text/plain', null, true));
            } finally {
                if (is_string($path) && is_file($path)) {
                    unlink($path);
                }
            }
        });
    }
}
