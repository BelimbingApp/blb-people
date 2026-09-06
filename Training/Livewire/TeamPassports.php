<?php

namespace App\Domains\People\Training\Livewire;

use App\Core\User\Models\User;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Services\WorkforceSubjects;
use App\Domains\People\Training\Services\TrainingPassportReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class TeamPassports extends Component
{
    public const VIEW_CAPABILITY = 'people.training.passport.view-team';

    #[Locked]
    public ?string $employeeId = null;

    public function mount(?string $employeeId = null): void
    {
        $this->actor();
        $this->employeeId = $employeeId;
    }

    public function render(
        SkillAudience $audience,
        WorkforceSubjects $workforce,
        TrainingPassportReader $passports,
    ): View {
        $actor = $this->actor();
        $companyId = (int) $actor->company_id;
        $visibleIds = $audience->visibleEmployeeEntityIdsFor(
            $actor,
            $companyId,
            self::VIEW_CAPABILITY,
            includeSelf: false,
        );
        $unitIds = $audience->visibleOrganizationUnitEntityIds($actor, $companyId, self::VIEW_CAPABILITY);
        $employees = collect($workforce->employees($companyId))
            ->filter(fn ($employee): bool => in_array((int) $employee->reference->externalId, $visibleIds, true)
                && $employee->organizationReference !== null
                && in_array((int) $employee->organizationReference->externalId, $unitIds, true)
                && $employee->reference->externalId !== (string) $actor->employee_id)
            ->sortBy(fn ($employee): string => $employee->displayName)
            ->values();

        $rows = $employees->map(function ($employee) use ($actor, $companyId, $passports): array {
            $passport = $passports->read($actor, $this->subject($companyId, $employee->reference->externalId));
            $today = CarbonImmutable::today();

            return [
                'id' => $employee->reference->externalId,
                'name' => $employee->displayName,
                'scheduled' => collect($passport->events)->where('status.value', 'scheduled')->count(),
                'attended' => collect($passport->events)->where('attended', true)->count(),
                'completed' => collect($passport->events)->where('status.value', 'completed')->count(),
                'expiring' => collect($passport->certificates)->filter(
                    fn ($certificate): bool => $certificate->validUntil !== null
                        && ! $certificate->expired
                        && CarbonImmutable::instance($certificate->validUntil)->betweenIncluded($today, $today->addDays(90)),
                )->count(),
                'passport' => $passport,
            ];
        })->all();

        $selected = null;
        if ($this->employeeId !== null) {
            $selected = collect($rows)->firstWhere('id', $this->employeeId);
            abort_unless($selected !== null, 404);
        }

        return view('people::livewire.team-passports', compact('rows', 'selected'));
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless(
            $actor instanceof User
            && $actor->tenant_id !== null
            && $actor->company_id !== null
            && $actor->employee_id !== null,
            403,
        );

        return $actor;
    }

    private function subject(int $companyId, string $employeeId): WorkforceSubject
    {
        return new WorkforceSubject(
            (int) $this->actor()->tenant_id,
            $companyId,
            WorkforceResourceType::Employee,
            $employeeId,
        );
    }
}
