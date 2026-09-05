<?php

namespace App\Domains\People\Training;

use App\Base\Menu\Services\MenuConditionRegistry;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\People\Training\Services\UnavailableTrainingParticipationSummary;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SummarizesTrainingParticipation::class,
            UnavailableTrainingParticipationSummary::class,
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people');

        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register(
                'people.training.event-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccess($user, 'people.training.event.view'),
            );
        });
    }
}
