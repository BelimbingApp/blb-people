<?php

namespace App\Domains\People\Training\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Department;
use App\Core\Company\Models\DepartmentType;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Training\Data\ParticipationFactDraft;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\AttendanceStatus;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEffectivenessCheckpoints;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Local fictional checkpoint answers, never real employee performance. */
final class DevTrainingEffectivenessSeeder extends DevSeeder
{
    protected array $dependencies = [DevTrainingGovernanceSeeder::class];

    protected function seed(): void
    {
        $company = $this->operatorPrimaryCompany();
        if ($company === null) {
            return;
        }
        $this->call(DevTrainingGovernanceSeeder::class);
        $tenant = app(TenantContext::class)->requireTenantId();
        $companyId = (int) $company->id;
        $source = TrainingCourse::query()->forCompany($tenant, $companyId)->where('code', 'demo-safety-induction')->first();
        if ($source === null || ! $source->active) {
            return;
        }
        $actors = User::query()->with('company')->where('company_id', $companyId)->whereIn('email', [
            "training-{$companyId}-learner@demo.invalid", "training-{$companyId}-hod@demo.invalid", "training-{$companyId}-hr@demo.invalid",
        ])->get()->keyBy('email');
        $learner = $actors->get("training-{$companyId}-learner@demo.invalid");
        $hod = $actors->get("training-{$companyId}-hod@demo.invalid");
        $hr = $actors->get("training-{$companyId}-hr@demo.invalid");
        if ($learner === null || $hod === null || $hr === null) {
            return;
        }

        $type = DepartmentType::query()->firstOrCreate(['code' => 'demo-training-practice'], [
            'name' => 'DEMO - Training Practice', 'category' => 'operational', 'is_active' => true,
        ]);
        $department = Department::query()->firstOrCreate([
            'company_id' => $companyId, 'department_type_id' => $type->id,
        ], ['head_id' => $hod->employee_id, 'status' => 'active']);
        // The checkpoint service uses Core department/head relationships.
        // Set only missing links on our synthetic employees; preserve transfers.
        foreach ($department->wasRecentlyCreated ? [$learner, $hod] : [] as $actor) {
            Employee::query()->where('company_id', $companyId)->whereKey($actor->employee_id)
                ->where('employee_number', 'like', 'DEMO-TRAINING-%')->whereNull('department_id')
                ->update(['department_id' => $department->id]);
        }
        foreach ([35 => true, 65 => false] as $daysAgo => $answered) {
            $code = $answered ? 'demo-effectiveness-answered' : 'demo-effectiveness-due';
            $course = TrainingCourse::query()->forCompany($tenant, $companyId)->where('code', $code)->first()
                ?? app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
                    code: $code,
                    title: $answered ? 'DEMO - Incident Reporting: Applied Practice' : 'DEMO - Hazard Awareness: Follow-up Due',
                    deliveryMode: DeliveryMode::Coaching, skillIds: $source->skillIds(),
                    description: 'DEMO ONLY - Synthetic historical practice to explain HOD effectiveness checkpoints and HR summary. No real performance assessment.',
                ));
            if (! $course->active || TrainingEvent::query()->forCompany($tenant, $companyId)->where('course_id', $course->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($tenant, $companyId, $learner, $hod, $hr, $course, $daysAgo, $answered): void {
                $ends = CarbonImmutable::now()->subDays($daysAgo)->startOfHour();
                $starts = $ends->subHours(2);
                $events = app(TrainingEventStore::class);
                $event = CarbonImmutable::withTestNow($starts->subDay(), fn () => $events->schedule($companyId, new TrainingEventDraft(
                    courseId: (int) $course->id, startsAt: $starts, endsAt: $ends, capacity: 4,
                    organizerEmployeeEntityId: (int) $hr->employee_id,
                    venue: 'DEMO - Fictional role-play room', externalTrainerName: 'DEMO - Training Facilitator',
                ), (int) $hr->id));
                CarbonImmutable::withTestNow($starts, fn () => $events->start($companyId, (int) $event->id, (int) $hr->id));
                $participation = app(TrainingParticipationStore::class);
                $session = $participation->defineSession($hr, $companyId, (int) $event->id, $course->code, $starts, $ends);
                $fact = $participation->recordAttendance($hr, $companyId, (int) $session->id,
                    new WorkforceSubject($tenant, $companyId, WorkforceResourceType::Employee, (string) $learner->employee_id,
                        new ExternalReference(WorkforceResourceType::Employee, (string) $learner->employee_id)),
                    new ParticipationFactDraft(AttendanceStatus::Present, 120, 'manual', $course->code));
                $events->complete($companyId, (int) $event->id, 'DEMO ONLY - Fictional local practice, no real attendance.', (int) $hr->id);
                if ($answered) {
                    $checkpoints = app(TrainingEffectivenessCheckpoints::class);
                    $row = collect($checkpoints->open($tenant, $companyId))->firstWhere('participantId', $fact->participant_id);
                    // Respect an operator's policy or department-head edits.
                    if ($row !== null && $row->hodUserId === (int) $hod->id) {
                        $checkpoints->answer($hod, $companyId, (int) $fact->participant_id, $row->checkpoint, 4,
                            'DEMO - The learner used the incident-reporting checklist correctly in a fictional follow-up exercise. One reminder was needed; this is synthetic feedback, not a real performance record.');
                    }
                }
            });
        }
    }
}
