<?php

namespace App\Domains\People\Training\Database\Seeders\Dev;

use App\Base\Database\Seeders\DevSeeder;
use App\Base\DateTime\Services\TimezoneSettings;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Models\SkillCategory;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use Carbon\CarbonImmutable;

/** Local catalog and schedule examples; never assigns user roles or attendance. */
final class DevTrainingSeeder extends DevSeeder
{
    protected function seed(): void
    {
        $company = $this->operatorPrimaryCompany();
        if ($company === null) {
            return;
        }

        $tenantId = app(TenantContext::class)->requireTenantId();
        $companyId = (int) $company->id;
        $skills = app(SkillCatalogStore::class);
        $courses = app(TrainingCatalogStore::class);
        $category = SkillCategory::query()->forCompany($tenantId, $companyId)
            ->where('code', 'demo-training-walkthrough')->first()
            ?? $skills->defineCategory($companyId, 'demo-training-walkthrough', 'DEMO - Training Walkthrough',
                'Local demonstration references for the Training walkthrough.');

        // Preserve deliberate deactivation and edits on repeated runs.
        if (! $category->active) {
            return;
        }

        $organizer = Employee::query()->firstOrCreate([
            'company_id' => $companyId,
            'employee_number' => 'DEMO-TRAINING-ORGANIZER',
        ], [
            'full_name' => 'DEMO - Training Coordinator',
            'short_name' => 'DEMO Coordinator',
            'designation' => 'Synthetic training organizer',
            'employee_type' => 'full_time',
            'status' => 'active',
            'employment_start' => now()->toDateString(),
            'metadata' => ['scenario' => 'training-dev'],
        ]);

        foreach ($this->examples() as $offset => $example) {
            $skill = Skill::query()->forCompany($tenantId, $companyId)
                ->where('code', $example['skill_code'])->first()
                ?? $skills->defineSkill($companyId, new SkillDraft(
                    code: $example['skill_code'],
                    name: $example['skill_name'],
                    definition: $example['definition'],
                    categoryId: (int) $category->id,
                ));

            if (! $skill->active) {
                continue;
            }

            $course = TrainingCourse::query()->forCompany($tenantId, $companyId)
                ->where('code', $example['code'])->first()
                ?? $courses->defineCourse($companyId, new TrainingCourseDraft(
                    code: $example['code'],
                    title: $example['title'],
                    deliveryMode: $example['mode'],
                    skillIds: [(int) $skill->id],
                    description: $example['description'],
                ));

            // One initial event per demo course. Never move an existing session
            // forward, revive a cancelled one, or overwrite walkthrough edits.
            if (! $course->active || $organizer->status !== 'active'
                || TrainingEvent::query()->forCompany($tenantId, $companyId)
                    ->where('course_id', $course->id)->exists()) {
                continue;
            }

            $startsAt = CarbonImmutable::now(app(TimezoneSettings::class)->companyTimezone($companyId))
                ->addDays(7 + $offset)->setTime(9, 0)->utc();

            app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
                courseId: (int) $course->id,
                startsAt: $startsAt,
                endsAt: $startsAt->addHours(2),
                capacity: 12,
                organizerEmployeeEntityId: (int) $organizer->id,
                venue: $example['mode'] === DeliveryMode::Elearning ? 'DEMO - Online classroom' : 'DEMO - Training room',
                externalTrainerName: 'DEMO - Learning Provider',
            ));
        }
    }

    /** @return list<array{code: string, title: string, mode: DeliveryMode, skill_code: string, skill_name: string, definition: string, description: string}> */
    private function examples(): array
    {
        return [
            [
                'code' => 'demo-safety-induction',
                'title' => 'DEMO - Workplace Safety Induction',
                'mode' => DeliveryMode::InternalClassroom,
                'skill_code' => 'demo-workplace-safety',
                'skill_name' => 'DEMO - Workplace Safety Awareness',
                'definition' => 'Identify common workplace hazards, follow site safety procedures and explain how to report an incident.',
                'description' => 'Local walkthrough sample: hazard awareness, safe working practices and incident reporting. Adapt to actual site procedures before operational use.',
            ],
            [
                'code' => 'demo-excel-fundamentals',
                'title' => 'DEMO - Excel Fundamentals',
                'mode' => DeliveryMode::Elearning,
                'skill_code' => 'demo-spreadsheet-basics',
                'skill_name' => 'DEMO - Spreadsheet Fundamentals',
                'definition' => 'Create a structured worksheet, use basic formulas, and sort and filter a small demonstration dataset.',
                'description' => 'Local walkthrough sample: worksheet layout, SUM and basic formulas, sorting and filtering using fictional data.',
            ],
            [
                'code' => 'demo-customer-service',
                'title' => 'DEMO - Customer Service Essentials',
                'mode' => DeliveryMode::Coaching,
                'skill_code' => 'demo-customer-communication',
                'skill_name' => 'DEMO - Customer Communication',
                'definition' => 'Listen to a fictional customer enquiry, clarify the request and propose an appropriate next step in a role-play.',
                'description' => 'Local walkthrough sample: active listening, clear explanations and handling routine enquiries through fictional role-play.',
            ],
        ];
    }
}
