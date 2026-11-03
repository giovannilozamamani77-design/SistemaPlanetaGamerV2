<?php

namespace App\Domain\Notificaciones\Providers;

use App\Domain\Cajas\Models\CashBox;
use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxCorrection;
use App\Domain\Comisiones\Models\CommissionPayment;
use App\Domain\Comisiones\Models\CommissionPaymentReversal;
use App\Domain\ControlHorario\Models\TimeIncident;
use App\Domain\Incidencias\Models\EconomicIncident;
use App\Domain\Incidencias\Models\GlobalRecovery;
use App\Domain\Incidencias\Models\RecoveryPayment;
use App\Domain\Notificaciones\Observers\NoticeSourceObserver;
use App\Models\User;
use Illuminate\Support\ServiceProvider;

class NotificacionesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([
            CashBoxClose::class,
            CashBoxCorrection::class,
            CashBox::class,
            EconomicIncident::class,
            RecoveryPayment::class,
            GlobalRecovery::class,
            TimeIncident::class,
            CommissionPayment::class,
            CommissionPaymentReversal::class,
            User::class,
        ] as $model) {
            $model::observe(NoticeSourceObserver::class);
        }
        $this->loadMigrationsFrom(database_path('migrations/notificaciones'));
    }
}
