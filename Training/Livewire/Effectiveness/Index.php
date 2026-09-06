<?php

namespace App\Domains\People\Training\Livewire\Effectiveness;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Training\Data\OpenEffectivenessCheckpoint;
use App\Domains\People\Training\Enums\EffectivenessCheckpoint;
use App\Domains\People\Training\Exceptions\InvalidTrainingEffectivenessException;
use App\Domains\People\Training\Models\TrainingEffectivenessAnswer;
use App\Domains\People\Training\Models\TrainingEvent;
use App\Domains\People\Training\Services\TrainingEffectivenessCheckpoints;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The 30/60/90-day effectiveness questions waiting on the signed-in HOD.
 *
 * The list is filtered to this actor's own department by the service, and the
 * answer goes back through the service too — so a participant id typed into a
 * request reaches the same department check either way. Rendering fewer rows
 * is a courtesy; the refusal that matters is the store's.
 */
final class Index extends Component
{
    public const VIEW_CAPABILITY = 'people.training.effectiveness.review';

    /** @var array<int, int> */
    public array $rating = [];

    /** @var array<int, string> */
    public array $comment = [];

    public function render(TrainingEffectivenessCheckpoints $checkpoints, TenantContext $tenants): View
    {
        $actor = Auth::user();
        $companyId = (int) $actor->company_id;

        $mine = array_values(array_filter(
            $checkpoints->open($tenants->requireTenantId(), $companyId),
            static fn (OpenEffectivenessCheckpoint $row): bool => $row->hodUserId === (int) $actor->getKey(),
        ));

        return view('people::livewire.effectiveness.index', [
            'rows' => $mine,
            'names' => $this->names($companyId, $mine),
            'courses' => $this->courses($tenants->requireTenantId(), $companyId, $mine),
            'answers' => $this->answers($tenants->requireTenantId(), $companyId, $mine),
        ]);
    }

    public function save(int $participantId, TrainingEffectivenessCheckpoints $checkpoints, TenantContext $tenants): void
    {
        $actor = Auth::user();
        $companyId = (int) $actor->company_id;

        $row = collect($checkpoints->open($tenants->requireTenantId(), $companyId))
            ->first(static fn (OpenEffectivenessCheckpoint $open): bool => $open->participantId === $participantId);

        try {
            $checkpoints->answer(
                $actor,
                $companyId,
                $participantId,
                $row->checkpoint ?? EffectivenessCheckpoint::Day30,
                (int) ($this->rating[$participantId] ?? 0),
                trim((string) ($this->comment[$participantId] ?? '')),
            );
        } catch (InvalidTrainingEffectivenessException $exception) {
            $this->addError('effectiveness', $exception->getMessage());

            return;
        }

        session()->flash('training-effectiveness-status', __('Your answer was recorded.'));
    }

    /**
     * @param  list<OpenEffectivenessCheckpoint>  $rows
     * @return array<int, string>
     */
    private function names(int $companyId, array $rows): array
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_map(static fn (OpenEffectivenessCheckpoint $row): int => $row->employeeEntityId, $rows))
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * @param  list<OpenEffectivenessCheckpoint>  $rows
     * @return array<int, string>
     */
    private function courses(int $tenantId, int $companyId, array $rows): array
    {
        return TrainingEvent::query()->forCompany($tenantId, $companyId)
            ->whereIn('id', array_map(static fn (OpenEffectivenessCheckpoint $row): int => $row->eventId, $rows))
            ->pluck('course_title_snapshot', 'id')
            ->all();
    }

    /**
     * @param  list<OpenEffectivenessCheckpoint>  $rows
     * @return array<int, TrainingEffectivenessAnswer>
     */
    private function answers(int $tenantId, int $companyId, array $rows): array
    {
        return TrainingEffectivenessAnswer::query()->forCompany($tenantId, $companyId)
            ->whereIn('participant_id', array_map(static fn (OpenEffectivenessCheckpoint $row): int => $row->participantId, $rows))
            ->get()
            ->keyBy('participant_id')
            ->all();
    }
}
