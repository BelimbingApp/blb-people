<?php

namespace App\Domains\People\Provider;

use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Services\NativeWorkforceBootstrapReader;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReadsWorkforceBootstrap::class, NativeWorkforceBootstrapReader::class);
    }
}
