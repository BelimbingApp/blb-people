<?php

namespace App\Domains\People\Training\Livewire\Catalog\Concerns;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Skills\Models\Skill;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Shared create/edit course form state for dedicated catalog form pages. */
trait SavesTrainingCourseForm
{
    /** @var array<string, mixed> */
    public array $courseForm = [];

    /** @return array{search?: string, status?: string, delivery?: string, sortBy?: string, sortDir?: string, page?: int} */
    protected function listReturnQuery(): array
    {
        return array_filter([
            'company' => $this->companyEntityId,
            'search' => request()->query('search'),
            'status' => request()->query('status'),
            'delivery' => request()->query('delivery'),
            'sortBy' => request()->query('sortBy'),
            'sortDir' => request()->query('sortDir'),
            'page' => request()->query('page'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function catalogIndexUrl(): string
    {
        return route('people.training.catalog.index', $this->listReturnQuery());
    }

    /**
     * @return Collection<int, object{workforce_entity_id: int, display_name: string}>
     */
    protected function employeeOptions(int $companyEntityId): Collection
    {
        return collect(app(WorkforceSubjects::class)->employees($companyEntityId))
            ->map(static fn ($employee): object => (object) [
                'workforce_entity_id' => (int) $employee->reference->externalId,
                'display_name' => $employee->displayName,
            ])
            ->sortBy('display_name')
            ->values();
    }

    /** @return Collection<int, Skill> */
    protected function activeSkills(int $companyEntityId): Collection
    {
        return Skill::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $companyEntityId)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    protected function emptyCourseForm(): array
    {
        return [
            'code' => '',
            'title' => '',
            'description' => '',
            'delivery_mode' => DeliveryMode::InternalClassroom->value,
            'skill_ids' => [],
            'internal_trainer_employee_entity_id' => null,
        ];
    }

    protected function courseFormFrom(TrainingCourse $course): array
    {
        return [
            'code' => $course->code,
            'title' => $course->title,
            'description' => $course->description ?? '',
            'delivery_mode' => $course->delivery_mode->value,
            'skill_ids' => $course->skillIds(),
            'internal_trainer_employee_entity_id' => $course->internal_trainer_employee_entity_id,
        ];
    }

    protected function persistCourse(
        TrainingAudience $audience,
        TrainingCatalogStore $store,
        ?int $courseId,
    ): bool {
        $company = $this->managedCompany($audience);
        $validated = $this->validate([
            'courseForm.code' => ['required', 'string', 'max:80'],
            'courseForm.title' => ['required', 'string', 'max:255'],
            'courseForm.description' => ['nullable', 'string'],
            'courseForm.delivery_mode' => ['required', Rule::enum(DeliveryMode::class)],
            'courseForm.skill_ids' => ['required', 'array', 'min:1'],
            'courseForm.skill_ids.*' => ['integer', 'distinct'],
            'courseForm.internal_trainer_employee_entity_id' => ['nullable', 'integer'],
        ]);
        $form = $validated['courseForm'];
        $draft = new TrainingCourseDraft(
            code: trim($form['code']),
            title: trim($form['title']),
            description: trim((string) ($form['description'] ?? '')) ?: null,
            deliveryMode: DeliveryMode::from($form['delivery_mode']),
            skillIds: array_map(intval(...), $form['skill_ids']),
            internalTrainerEmployeeEntityId: $form['internal_trainer_employee_entity_id'] === null || $form['internal_trainer_employee_entity_id'] === ''
                ? null : (int) $form['internal_trainer_employee_entity_id'],
        );

        try {
            if ($courseId === null) {
                $store->defineCourse($company, $draft);
            } else {
                $store->reviseCourse($company, $courseId, $draft);
            }
        } catch (InvalidTrainingCatalogException $exception) {
            $this->addError('courseForm', $exception->getMessage());

            return false;
        }

        session()->flash('status', __('Training course saved.'));

        return true;
    }

    protected function canManageSkillsFor(int $companyEntityId): bool
    {
        return app(SkillAudience::class)->mayManageCatalog(Auth::user(), $companyEntityId);
    }
}
