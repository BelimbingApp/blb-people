<?php

namespace App\Domains\People\Skills;

use App\Base\Database\Services\DataShare\DataShareDestinationMapper;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareValueNormalizer;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Core\User\Models\User;
use App\Domains\People\Organisation\Contracts\SummarizesOrganisationSkillCoverage;
use App\Domains\People\Skills\Contracts\ConfirmsAssessableRequirementVersion;
use App\Domains\People\Skills\Contracts\ReadsOwnSkillStanding;
use App\Domains\People\Skills\Contracts\ResolvesSkillRequirements;
use App\Domains\People\Skills\Listeners\SendRequirementProfileTransitionNotification;
use App\Domains\People\Skills\Services\OrganisationSkillCoverage;
use App\Domains\People\Skills\Services\OwnSkillStandingReader;
use App\Domains\People\Skills\Services\RequirementProfileDataShareDestinationMapper;
use App\Domains\People\Skills\Services\RequirementResolver;
use App\Domains\People\Skills\Services\RequirementVersionGuard;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Skills\Workflow\RequirementProfileTransitionAuthority;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResolvesSkillRequirements::class, RequirementResolver::class);
        $this->app->bind(ConfirmsAssessableRequirementVersion::class, RequirementVersionGuard::class);
        $this->app->bind(ReadsOwnSkillStanding::class, OwnSkillStandingReader::class);
        $this->app->bind(SummarizesOrganisationSkillCoverage::class, OrganisationSkillCoverage::class);
        $this->app->singleton(RequirementProfileTransitionAuthority::class);
        $this->app->extend(
            DataShareDestinationMapper::class,
            fn (): RequirementProfileDataShareDestinationMapper => new RequirementProfileDataShareDestinationMapper(
                $this->app->make(DataShareValueNormalizer::class),
                $this->app->make(DataShareScopeCatalog::class),
                $this->app->make(RequirementProfileTransitionAuthority::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people');
        Event::listen(TransitionCompleted::class, SendRequirementProfileTransitionNotification::class);

        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register(
                'people.skill.catalog-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccess($user, 'people.skill.catalog.view'),
            );
            $registry->register(
                'people.skill.assessment-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccess($user, 'people.skill.assessment.view'),
            );
        });
    }
}
