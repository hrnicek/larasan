<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainEventServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RouteBindingServiceProvider;

return [
    AppServiceProvider::class,
    DomainEventServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    RouteBindingServiceProvider::class,
];
