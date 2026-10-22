<?php

namespace App\Providers\Filament;

use App\Filament\Sus\Pages\SusEvaluation;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The SUS questionnaire at /sus (DESIGN §39.7): its own guard (`sus`) over
 * the Test Lab accounts, open only to the role `evaluator`. An evaluator
 * sees nothing but the questionnaire; testers never answer it.
 */
class SusPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('sus')
            ->path('sus')
            ->authGuard('sus')
            ->login()
            ->brandName('Krucheck · แบบประเมิน SUS')
            ->colors(['primary' => Color::Teal])
            ->topNavigation()
            ->pages([SusEvaluation::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
