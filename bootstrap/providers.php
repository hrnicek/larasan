<?php

use App\Providers\AppServiceProvider;
use App\Providers\DomainEventServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    DomainEventServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
];
