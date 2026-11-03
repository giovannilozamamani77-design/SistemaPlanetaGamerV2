<?php

use App\Domain\Accesorios\Providers\AccesoriosServiceProvider;
use App\Domain\Cajas\Providers\CajasServiceProvider;
use App\Domain\Configuraciones\Providers\ConfiguracionesServiceProvider;
use App\Domain\ControlHorario\Providers\ControlHorarioServiceProvider;
use App\Domain\Envios\Providers\EnviosServiceProvider;
use App\Domain\Incidencias\Providers\IncidenciasServiceProvider;
use App\Domain\Notificaciones\Providers\NotificacionesServiceProvider;
use App\Domain\Prestamos\Providers\PrestamosServiceProvider;
use App\Domain\Reportes\Providers\ReportesServiceProvider;
use App\Domain\Tarjetas\Providers\TarjetasServiceProvider;
use App\Domain\Usuarios\Providers\UsuariosServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\InicioPanelProvider;

return [
    AccesoriosServiceProvider::class,
    PrestamosServiceProvider::class,
    CajasServiceProvider::class,
    TarjetasServiceProvider::class,
    ConfiguracionesServiceProvider::class,
    ControlHorarioServiceProvider::class,
    IncidenciasServiceProvider::class,
    NotificacionesServiceProvider::class,
    UsuariosServiceProvider::class,
    ReportesServiceProvider::class,
    AppServiceProvider::class,
    EnviosServiceProvider::class,
    InicioPanelProvider::class,
];
