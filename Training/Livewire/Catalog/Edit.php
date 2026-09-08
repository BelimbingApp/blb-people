<?php

namespace App\Domains\People\Training\Livewire\Catalog;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\Catalog\Concerns\ResolvesCatalogCompany;
use App\Domains\People\Training\Livewire\Catalog\Concerns\SavesTrainingCourseForm;
use App\Domains\People\Training\Models\TrainingCourse;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Dedicated revise page for one company training course. */
final class Edit extends Component
{
    use ResolvesCatalogCompany;
    use SavesTrainingCourseForm;

    public ?int $companyEntityId = null;

    public int $courseId;

    public function mount(int $courseId, TrainingAudience $audience): void
    {
        $this->courseId = $courseId;
        $preferred = request()->integer('company') ?: null;
        $this->resolveInitialCompany($audience, $preferred === 0 ? null : $preferred);
        abort_if($this->companyEntityId === null, 404);
        $company = $this->managedCompany($audience);

        $course = TrainingCourse::query()
            ->forCompany(app(TenantContext::class)->requireTenantId(), $company)
            ->find($courseId);
        abort_if($course === null, 404);

        $this->courseForm = $this->courseFormFrom($course);
    }

    public function saveCourse(TrainingAudience $audience, TrainingCatalogStore $store): void
    {
        if (! $this->persistCourse($audience, $store, $this->courseId)) {
            return;
        }

        $this->redirect($this->catalogIndexUrl(), navigate: true);
    }

    public function cancel(): void
    {
        $this->redirect($this->catalogIndexUrl(), navigate: true);
    }

    public function render(TrainingAudience $audience): View
    {
        $company = $this->managedCompany($audience);

        return view('people::livewire.training.catalog.edit', [
            'skills' => $this->activeSkills($company),
            'employees' => $this->employeeOptions($company),
            'deliveryModes' => DeliveryMode::cases(),
            'indexUrl' => $this->catalogIndexUrl(),
        ]);
    }
}
