<?php

namespace App\Domains\People\Shared\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * As-of date control for standing and history views.
 *
 * Emits the chosen Y-m-d date on `standing-as-of-changed` so the owning
 * page can pass it to the read services, which expect that exact shape.
 * No queries run here; a future date is rejected without emitting.
 */
final class AsOfDatePicker extends Component
{
    public string $date = '';

    public function mount(?string $date = null): void
    {
        $this->date = $date ?? now()->toDateString();
    }

    /** @return array<string, string> */
    protected function rules(): array
    {
        return ['date' => 'required|date_format:Y-m-d|before_or_equal:today'];
    }

    public function updatedDate(): void
    {
        $this->validate();

        $this->dispatch('standing-as-of-changed', $this->date);
    }

    public function render(): View
    {
        return view('people::livewire.shared.as-of-date-picker');
    }
}
