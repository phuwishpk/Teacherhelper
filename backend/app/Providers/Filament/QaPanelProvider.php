<?php

namespace App\Providers\Filament;

use App\Filament\Qa\Pages\ManualTestChecklist;
use App\Filament\Qa\Pages\MyResults;
use App\Filament\Qa\Pages\QaOverview;
use App\Filament\Qa\Pages\SusSurvey;
use App\Filament\Qa\Pages\TeamBoard;
use App\Filament\Qa\Pages\TestCaseDetail;
use App\Filament\Qa\Pages\Testers;
use App\Filament\Qa\Pages\TestPlan;
use App\Filament\Qa\Pages\UsabilityReport;
use App\Http\Controllers\Qa\ManualChecklistFileController;
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
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Krucheck Test Lab at /qa (DESIGN §39): the test plan, every tester's
 * results and the SUS survey. Its own guard (`qa`, model QaTester), so a
 * Krucheck account never opens it and a tester never opens /admin.
 */
class QaPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('qa')
            ->path('qa')
            ->authGuard('qa')
            ->login()
            ->brandName('Krucheck Test Lab')
            ->colors(['primary' => Color::Blue])
            ->pages([
                QaOverview::class,
                TestPlan::class,
                ManualTestChecklist::class,
                TestCaseDetail::class,
                MyResults::class,
                UsabilityReport::class,
                SusSurvey::class,
                TeamBoard::class,
                Testers::class,
            ])
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
            ->authenticatedRoutes(function (): void {
                Route::get('checklist/files/{file}', ManualChecklistFileController::class)->name('checklist.file');
            })
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
