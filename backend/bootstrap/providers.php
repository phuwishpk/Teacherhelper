<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\QaPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    QaPanelProvider::class,
];
