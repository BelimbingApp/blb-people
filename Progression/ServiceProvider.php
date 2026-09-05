<?php

namespace App\Domains\People\Progression;

use App\Domains\People\Progression\Contracts\ReadsPublishedProgressionPolicy;
use App\Domains\People\Progression\Services\ConfigPublishedProgressionPolicyReader;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/progression.php', 'people.progression');
        $this->app->bind(ReadsPublishedProgressionPolicy::class, ConfigPublishedProgressionPolicyReader::class);
    }
}
