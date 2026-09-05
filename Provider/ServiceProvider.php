<?php

namespace App\Domains\People\Provider;

use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges;
use App\Domains\People\Provider\Contracts\ResolvesWorkforceSubjects;
use App\Domains\People\Provider\Services\NativeWorkforceBootstrapReader;
use App\Domains\People\Provider\Services\NativeWorkforceChangeReader;
use App\Domains\People\Provider\Services\NativeWorkforceSubjectResolver;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReadsWorkforceBootstrap::class, NativeWorkforceBootstrapReader::class);
        $this->app->bind(ReadsWorkforceChanges::class, NativeWorkforceChangeReader::class);
        $this->app->bind(ResolvesWorkforceSubjects::class, NativeWorkforceSubjectResolver::class);
    }
}
