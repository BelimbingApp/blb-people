<?php

namespace App\Domains\People\Training\Livewire\Catalog;

use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Livewire\Catalog\Concerns\ResolvesCatalogCompany;
use App\Domains\People\Training\Livewire\Catalog\Concerns\SavesTrainingCourseForm;
use App\Domains\People\Training\Services\TrainingAudience;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Dedicated create page for a company training course. */
final class Create extends Component
{
    use ResolvesCatalogCompany;
    use SavesTrainingCourseForm;

    public ?int $companyEntityId = null;

    public function mount(TrainingAudience $audience): void
    {
        $preferred = request()->integer('company') ?: null;
        $this->resolveInitialCompany($audience, $preferred === 0 ? null : $preferred);
        abort_if($this->companyEntityId === null, 404);
        $this->managedCompany($audience);

        if ($this->activeSkills($this->companyEntityId)->isEmpty()) {
            session()->flash('status', __('Add an active skill before defining a course.'));
            $this->redirect($this->catalogIndexUrl(), navigate: true);

            return;
        }

        $this->courseForm = $this->emptyCourseForm();
    }

    public function saveCourse(TrainingAudience $audience, TrainingCatalogStore $store): void
    {
        if (! $this->persistCourse($audience, $store, null)) {
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
        $skills = $this->activeSkills($company);

        return view('people::livewire.training.catalog.create', [
            'skills' => $skills,
            'employees' => $this->employeeOptions($company),
            'deliveryModes' => DeliveryMode::cases(),
            'indexUrl' => $this->catalogIndexUrl(),
        ]);
    }
}
