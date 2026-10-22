<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\QaPanelProvider;
use App\Providers\Filament\SusPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    QaPanelProvider::class,
    SusPanelProvider::class,
];
