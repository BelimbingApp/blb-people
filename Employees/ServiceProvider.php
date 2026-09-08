<?php

namespace App\Domains\People\Employees;

use App\Base\Menu\Services\MenuConditionRegistry;
use App\Domains\People\Training\Services\TrainingPassportAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Views', 'people-employees');

        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register(
                'people.training.passport-eligible',
                static fn (Authenticatable $user): bool => app(TrainingPassportAccess::class)->mayViewOwn($user),
            );
        });
    }
}
