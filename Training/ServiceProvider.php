<?php

namespace App\Domains\People\Training;

use App\Base\Menu\Services\MenuConditionRegistry;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Services\SkillAudience;
use App\Domains\People\Training\Console\Commands\EffectivenessDueCommand;
use App\Domains\People\Training\Console\Commands\EvaluationsDueCommand;
use App\Domains\People\Training\Console\Commands\MigrationReconcileCommand;
use App\Domains\People\Training\Console\Commands\PurgeTrainingPassportDocumentsCommand;
use App\Domains\People\Training\Console\Commands\RequestsDueCommand;
use App\Domains\People\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\People\Training\Services\DatabaseTrainingParticipationSummary;
use App\Domains\People\Training\Services\TrainingBudgetStore;
use App\Domains\People\Training\Services\TrainingSubjectExporter;
use App\Domains\PeopleConnector\Connector\Contracts\ExportsSupplementalSubjectRecords;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/training.php', 'people-training');

        // Counts come from participant records (0011-e). The unavailable
        // implementation stays for the provider-outage path: a summary that
        // cannot be read is unavailable, never zero.
        $this->app->singleton(
            SummarizesTrainingParticipation::class,
            DatabaseTrainingParticipationSummary::class,
        );
        // Connector #308: tag only when the connector contract is mounted.
        if (interface_exists(ExportsSupplementalSubjectRecords::class)) {
            $this->app->singleton(TrainingSubjectExporter::class);
            $this->app->tag([TrainingSubjectExporter::class], ExportsSupplementalSubjectRecords::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                EffectivenessDueCommand::class,
                EvaluationsDueCommand::class,
                MigrationReconcileCommand::class,
                PurgeTrainingPassportDocumentsCommand::class,
                RequestsDueCommand::class,
            ]);
        }
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
            $registry->register(
                'people.training.kpi-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccess($user, 'people.training.kpi.view'),
            );
            $registry->register(
                'people.training.calendar-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccess($user, 'people.training.calendar.view'),
            );
            $registry->register(
                'people.training.budget-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(TrainingBudgetStore::class)->mayView($user, (int) $user->company_id),
            );
            $registry->register(
                'people.training.hr-governance-audience',
                static fn (Authenticatable $user): bool => $user instanceof User
                    && app(SkillAudience::class)->mayAccessAs(
                        $user,
                        'people.skill.hr.view',
                        SkillAudience::HR,
                    ),
            );
        });
    }
}
