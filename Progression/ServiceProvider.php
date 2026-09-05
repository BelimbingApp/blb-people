<?php

namespace App\Domains\People\Progression;

use App\Domains\People\Progression\Contracts\ReadsPublishedProgressionPolicy;
use App\Domains\People\Progression\Services\DatabasePublishedProgressionPolicyReader;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReadsPublishedProgressionPolicy::class, DatabasePublishedProgressionPolicyReader::class);
    }
}
