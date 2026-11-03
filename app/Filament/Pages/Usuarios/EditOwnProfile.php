<?php

namespace App\Filament\Pages\Usuarios;

use App\Domain\Configuraciones\Actions\SavePersonnelRulesAction;
use App\Domain\Configuraciones\Queries\PersonnelRules;
use App\Domain\Usuarios\Actions\SetUserPinAction;
use App\Domain\Usuarios\Actions\UpdateOwnProfileAction;
use App\Domain\Usuarios\Actions\UpdateOwnProfilePhotoAction;
use App\Filament\Pages\ControlHorario\AssignedShifts;
use App\Filament\Resources\ControlHorario\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\ControlHorario\TimeIncidents\TimeIncidentResource;
use App\Filament\Resources\Incidencias\EconomicIncidents\EconomicIncidentResource;
use App\Filament\Resources\Incidencias\GlobalRecoveries\GlobalRecoveryResource;
use App\Filament\Widgets\Comisiones\CommissionPaymentsWidget;
use App\Filament\Widgets\Usuarios\OwnAttendanceWidget;
use App\Filament\Widgets\Usuarios\OwnCommissionsWidget;
use App\Filament\Widgets\Usuarios\OwnContributionsWidget;
use App\Filament\Widgets\Usuarios\OwnEconomicHistoryWidget;
use App\Filament\Widgets\Usuarios\OwnGlobalRecoveriesWidget;
use App\Filament\Widgets\Usuarios\OwnLeavesWidget;
use App\Filament\Widgets\Usuarios\OwnPersonnelSummaryWidget;
use App\Filament\Widgets\Usuarios\OwnProfileBalancesWidget;
use App\Filament\Widgets\Usuarios\OwnTimeAuditWidget;
use App\Filament\Widgets\Usuarios\OwnTimeHistoryWidget;
use App\Filament\Widgets\Usuarios\OwnTimeMovementsWidget;
use App\Filament\Widgets\Usuarios\OwnTimeSummaryWidget;
use App\Support\Bolivianos;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use SensitiveParameter;

class EditOwnProfile extends EditProfile
{
    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getHeading(): ?string
    {
        return null;
    }

    public static function getLabel(): string
    {
        return 'Mi perfil';
    }

    public function mount(): void
    {
        Gate::forUser($this->getUser())->authorize('perfil.ver');
        parent::mount();
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->dehydrateStateUsing(fn (#[SensitiveParameter] ?string $state): ?string => $state);
    }

    protected function getCurrentPasswordFormComponent(): Component
    {
        return parent::getCurrentPasswordFormComponent()->dehydrated();
    }

    public function content(Schema $schema): Schema
    {
        $tabs = [Tab::make('Datos personales')->icon(Heroicon::OutlinedUserCircle)->schema([
            $this->getFormContentComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
        ])];
        $tabs[] = Tab::make('Faltantes y reposiciones')->icon(Heroicon::OutlinedBanknotes)->visible(fn (): bool => $this->getUser()->can('incidencias.ver_propios'))
            ->schema([
                Livewire::make(OwnPersonnelSummaryWidget::class, ['section' => 'primary'])->lazy()->key('own-shortage-accumulated'),
                Livewire::make(OwnEconomicHistoryWidget::class)->lazy()->key('own-economic-history'),
                Section::make('Pagos e historial de reposiciones')->collapsible()->collapsed()->schema([
                    Livewire::make(OwnPersonnelSummaryWidget::class, ['section' => 'details'])->lazy()->key('own-personnel-summary'),
                    Livewire::make(OwnContributionsWidget::class)->lazy()->key('own-contributions'),
                    Livewire::make(OwnGlobalRecoveriesWidget::class)->lazy()->key('own-global-recoveries'),
                ]),
            ]);
        $tabs[] = Tab::make('Mis comisiones')->key('profile-commercial-commissions')->icon(Heroicon::OutlinedBanknotes)->schema([
            Livewire::make(OwnCommissionsWidget::class)->lazy()->key('own-commercial-commissions'),
            Section::make('Pagos recibidos')->icon(Heroicon::OutlinedReceiptRefund)->collapsible()->collapsed()->schema([
                Livewire::make(CommissionPaymentsWidget::class)->lazy()->key('own-commission-payments'),
            ]),
        ]);
        foreach ([
            'Asistencia' => OwnAttendanceWidget::class,
            'Retrasos y faltas' => OwnTimeHistoryWidget::class,
            'Crédito y movimientos' => OwnTimeMovementsWidget::class,
            'Decisiones y aplicaciones' => OwnTimeAuditWidget::class,
            'Permisos y día libre' => OwnLeavesWidget::class,
        ] as $label => $widget) {
            $tabIcons = [
                'Asistencia' => Heroicon::OutlinedCalendarDays,
                'Retrasos y faltas' => Heroicon::OutlinedClock,
                'Crédito y movimientos' => Heroicon::OutlinedArrowsRightLeft,
                'Decisiones y aplicaciones' => Heroicon::OutlinedClipboardDocumentCheck,
                'Permisos y día libre' => Heroicon::OutlinedSun,
            ];
            $tabs[] = Tab::make($label)->key($widget === OwnTimeHistoryWidget::class ? 'profile-time-delays' : $widget)->icon($tabIcons[$label])->visible(fn (): bool => $this->getUser()->can('controlhorario.ver_propios'))
                ->schema([
                    ...($widget === OwnTimeMovementsWidget::class ? [Livewire::make(OwnTimeSummaryWidget::class)->lazy()->key('own-time-summary')] : []),
                    Livewire::make($widget)->lazy()->key($widget),
                ]);
        }
        $tabs[] = Tab::make('Administración del personal')->icon(Heroicon::OutlinedShieldCheck)
            ->visible(fn (): bool => $this->getUser()->hasRole('administrador'))
            ->schema([Actions::make([
                Action::make('horariosPersonal')->label('Asignar turnos habituales')->url(fn (): string => AssignedShifts::getUrl())->visible(fn (): bool => $this->getUser()->can('controlhorario.programar')),
                Action::make('revisarHorario')->label('Revisar retrasos y faltas')->url(fn (): string => TimeIncidentResource::getUrl())->visible(fn (): bool => $this->getUser()->can('controlhorario.revisar')),
                Action::make('revisarPermisos')->label('Revisar permisos')->url(fn (): string => LeaveRequestResource::getUrl())->visible(fn (): bool => $this->getUser()->can('controlhorario.revisar')),
                Action::make('revisarReposicionesGlobales')->label('Verificar reposiciones globales')->url(fn (): string => GlobalRecoveryResource::getUrl())->visible(fn (): bool => $this->getUser()->can('incidencias.reposiciones.confirmar')),
                Action::make('revisarFaltantes')->label('Revisar faltantes')->url(fn (): string => EconomicIncidentResource::getUrl())->visible(fn (): bool => $this->getUser()->can('incidencias.ver_todos')),
            ])]);

        $tabs[] = Tab::make('Ajustes')->icon(Heroicon::OutlinedCog6Tooth)
            ->visible(fn (): bool => $this->getUser()->hasRole('administrador') && $this->getUser()->can('configuraciones.personal'))
            ->schema([
                View::make('usuarios.profile-settings')->viewData(fn (): array => [
                    'rules' => $this->getUser()->can('configuraciones.personal') ? app(PersonnelRules::class)->current() : null,
                    'settingsAction' => $this->parametrosPersonalAction(),
                ]),
            ]);

        return $schema->components([
            View::make('usuarios.profile-header')->viewData(fn (): array => [
                'user' => $this->getUser(),
                'photoAction' => $this->fotoPerfilAction(),
                'removePhotoAction' => $this->quitarFotoPerfilAction(),
            ]),
            Livewire::make(OwnProfileBalancesWidget::class)->key('own-profile-balances'),
            Tabs::make('Mi historial')->persistTabInQueryString('profileTab')->vertical()->contained(false)->activeTab(fn (): int => request()->query('section') === 'faltantes' ? 2 : 1)
                ->extraAttributes(['class' => 'pg-profile-tabs'])
                ->tabs($tabs),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->inlineLabel(false)->components([
            Section::make('Información personal')
                ->description('Tu nombre y correo de acceso al sistema.')
                ->icon(Heroicon::OutlinedIdentification)
                ->schema([
                    $this->getNameFormComponent()->label('Nombre completo'),
                    $this->getEmailFormComponent()->label('Correo electrónico'),
                ])->columns(2),
            Section::make('Seguridad de tu cuenta')
                ->description('Deja la nueva contraseña vacía si no deseas cambiarla. Para cambiar correo o contraseña se requiere tu contraseña actual.')
                ->icon(Heroicon::OutlinedLockClosed)
                ->schema([
                    $this->getPasswordFormComponent()->label('Nueva contraseña'),
                    $this->getPasswordConfirmationFormComponent()->label('Confirmar nueva contraseña'),
                    $this->getCurrentPasswordFormComponent()->label('Contraseña actual'),
                    Actions::make([$this->pinPersonalAction()])->label('Mi PIN personal'),
                ])->columns(2),
        ]);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Guardar cambios');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->label('Volver');
    }

    public function fotoPerfilAction(): Action
    {
        return Action::make('fotoPerfil')
            ->label('Cambiar foto')->icon(Heroicon::OutlinedCamera)->color('gray')
            ->visible(fn (): bool => $this->getUser()->can('perfil.actualizar'))
            ->modalHeading('Tu foto de perfil')->modalSubmitActionLabel('Guardar foto')->modalCancelActionLabel('Cancelar')
            ->modalDescription('Es opcional. Usa una imagen JPG, PNG o WebP de hasta 2 MB y 4096 × 4096 píxeles.')
            ->schema([
                FileUpload::make('photo')->label('Foto opcional')->placeholder('Arrastra tu foto o haz clic para elegir una imagen')
                    ->disk('profile_photos')->visibility('private')
                    ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)->storeFiles(false)->preventFilePathTampering(),
            ])
            ->action(function (array $data): void {
                $user = app(UpdateOwnProfilePhotoAction::class)->execute($this->getUser(), $data['photo'] ?? null);
                Filament::auth()->setUser($user);
                $this->dispatch('refresh-sidebar');
                Notification::make()->title('Foto de perfil actualizada')->success()->send();
            });
    }

    public function quitarFotoPerfilAction(): Action
    {
        return Action::make('quitarFotoPerfil')->label('Quitar foto')->link()->color('gray')
            ->visible(fn (): bool => $this->getUser()->can('perfil.actualizar') && filled($this->getUser()->profile_photo_path))
            ->requiresConfirmation()->modalHeading('¿Quitar tu foto?')
            ->modalDescription('Volverás a ver tus iniciales. Tu información y tu historial se conservan.')
            ->action(function (): void {
                $user = app(UpdateOwnProfilePhotoAction::class)->execute($this->getUser(), remove: true);
                Filament::auth()->setUser($user);
                $this->dispatch('refresh-sidebar');
                Notification::make()->title('Foto eliminada')->success()->send();
            });
    }

    public function pinPersonalAction(): Action
    {
        return Action::make('pinPersonal')->label('Configurar mi PIN')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (): bool => $this->getUser()->can('perfil.actualizar'))
            ->modalHeading('Configurar tu PIN personal')
            ->modalDescription('Exactamente 4 dígitos. El PIN no reemplaza la contraseña de acceso y no se mostrará después de guardarlo.')
            ->modalSubmitActionLabel('Guardar PIN')->modalCancelActionLabel('Cancelar')
            ->schema([
                TextInput::make('pin')->label('Nuevo PIN')->password()->inputMode('numeric')
                    ->required()->regex('/\A[0-9]{4}\z/')->same('pinConfirmation'),
                TextInput::make('pinConfirmation')->label('Confirmar nuevo PIN')->password()->inputMode('numeric')->required(),
            ])
            ->action(function (#[SensitiveParameter] array $data): void {
                $user = app(SetUserPinAction::class)->executeOwn($this->getUser(), $data['pin']);
                Filament::auth()->setUser($user);
                Notification::make()->title('Tu PIN fue actualizado')->success()->send();
            });
    }

    public function parametrosPersonalAction(): Action
    {
        return
            Action::make('parametrosPersonal')->label('Editar ajustes')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->modalHeading('Ajustes de asistencia y diferencias')->modalSubmitActionLabel('Guardar ajustes')->modalCancelActionLabel('Cancelar')
                ->visible(fn (): bool => $this->getUser()->hasRole('administrador') && $this->getUser()->can('configuraciones.personal'))
                ->modalDescription(fn (): string => 'Actual: tolerancia '.app(PersonnelRules::class)->current()['tolerance_minutes'].' min; diferencia alta mayor a Bs '.Bolivianos::format(app(PersonnelRules::class)->current()['high_difference_cents']).'. Los cambios solo afectan jornadas/casos futuros.')
                ->schema([
                    Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                    TextInput::make('tolerance_minutes')->label('Tolerancia de mañana (minutos)')->helperText('La tarde, los horarios y las cajas se configuran en Configuraciones → Control de horario. No modifica registros anteriores.')->integer()->required()->live()->default(fn (): int => app(PersonnelRules::class)->current()['tolerance_minutes']),
                    TextInput::make('high_difference')->label('Umbral de diferencia alta')->helperText('Si el faltante o sobrante de un componente supera este importe, se exige volver a ingresar el monto y explicar el motivo.')->prefix('Bs')->required()->live()->default(fn (): string => Bolivianos::format(app(PersonnelRules::class)->current()['high_difference_cents'])),
                    Select::make('delay_multiplier')->label('Factor de retraso')->options([1 => 'Normal ×1', 2 => 'Doble ×2', 3 => 'Triple ×3'])->default(fn () => app(PersonnelRules::class)->current()['delay_multiplier'])->required(),
                    TextInput::make('multiplier_minutes')->label('Minutos con ese factor')->integer()->minValue(1)->maxValue(1440)->helperText('Vacío: durante todo el retraso. Al terminar este tramo, solo el exceso cuenta normal.')->default(fn () => app(PersonnelRules::class)->current()['multiplier_minutes']),
                    Toggle::make('automatic_credit')->label('Compensar automáticamente el crédito trabajado')->default(fn () => app(PersonnelRules::class)->current()['automatic_credit'])->helperText('Si se desactiva, la cajera puede aplicar su propio crédito y Administración el de la responsable.'),
                    Placeholder::make('preview')->label('Vista previa')->content(fn (Get $get): string => 'Nueva tolerancia: '.$get('tolerance_minutes').' min. Segunda comprobación si la diferencia supera Bs '.$get('high_difference').'.'),
                    Textarea::make('reason')->label('Motivo del cambio')->helperText('Indica por qué cambias estos valores. El motivo quedará en la auditoría.')->required(),
                    TextInput::make('pin')->label('Tu PIN de Administración')->helperText('Confirma tu identidad para registrar la nueva versión.')->password()->required(),
                ])->action(function (#[SensitiveParameter] array $data): void {
                    $data['high_difference_cents'] = Bolivianos::cents($data['high_difference']);
                    app(SavePersonnelRulesAction::class)->execute($this->getUser(), $data);
                    Notification::make()->title('Nueva versión registrada para casos futuros')->success()->send();
                });
    }

    public function save(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }
        $data = $this->form->getState();
        $user = app(UpdateOwnProfileAction::class)->execute($this->getUser(), $data);
        Filament::auth()->setUser($user);
        if (request()->hasSession() && filled($data['password'] ?? null)) {
            request()->session()->put('password_hash_'.Filament::getAuthGuard(), $user->getAuthPassword());
        }
        $this->data['password'] = null;
        $this->data['passwordConfirmation'] = null;
        $this->data['currentPassword'] = null;
        $this->getSavedNotification()?->send();
    }
}
