<?php

namespace App\Filament\Support\ControlHorario;

use App\Domain\ControlHorario\Actions\SaveScheduleSectionAction;
use App\Domain\ControlHorario\Calculators\ScheduleSettingsPreview;
use App\Domain\ControlHorario\Queries\ScheduleSettingsQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleSettingsActions
{
    public static function configure(string $name, string $section, string $title, string $description, array $effects, array $fields): Action
    {
        return Action::make($name)->label('Configurar')->icon('heroicon-o-adjustments-horizontal')->link()->size('sm')
            ->modalHeading($title)->modalDescription($description)->modalWidth('3xl')->stickyModalFooter()
            ->fillForm(function (): array {
                $state = app(ScheduleSettingsQuery::class)->execute(auth()->user());

                return [...$state['settings'], 'all_boxes' => false,
                    'expected_version_id' => $state['version_id'], 'request_key' => (string) Str::uuid(), 'reason' => '', 'pin' => ''];
            })
            ->schema([
                View::make('control_horario.settings-impact')->viewData(['effects' => $effects]),
                Grid::make(2)->schema($fields),
                Grid::make(2)->schema([
                    Textarea::make('reason')->label('Motivo del cambio')->rows(2)->required()->minLength(3)->maxLength(2000),
                    TextInput::make('pin')->label('Tu PIN de Administración')->password()->revealable()->required()->length(4),
                ]),
                Hidden::make('expected_version_id'), Hidden::make('request_key'),
            ])->modalSubmitActionLabel('Guardar cambios')->modalCancelActionLabel('Volver sin guardar')
            ->action(function (array $data, $livewire) use ($section): void {
                try {
                    app(SaveScheduleSectionAction::class)->execute(auth()->user(), $section, $data);
                } catch (ValidationException $error) {
                    Notification::make()->danger()->title('No se guardaron los cambios')->body(collect($error->errors())->flatten()->implode(' '))->send();
                    throw $error;
                }
                $livewire->reloadSettings();
                Notification::make()->success()->title('Configuración guardada')->send();
            });
    }

    public static function simulate(array $fields): Action
    {
        return Action::make('simulateSchedule')->label('Probar un ejemplo')->icon('heroicon-o-play')->link()->size('sm')
            ->modalHeading('Simular sin registrar')->modalDescription('Ejemplo con las reglas guardadas de mañana. No crea asistencias, retrasos, créditos ni cierres.')
            ->modalWidth('3xl')->modalSubmitAction(false)->modalCancelActionLabel('Cerrar simulación')
            ->fillForm(function (): array {
                $s = app(ScheduleSettingsQuery::class)->execute(auth()->user())['settings'];

                return ['example_start' => $s['morning_start'], 'example_opening' => $s['morning_start'],
                    'example_end' => $s['morning_end'], 'example_closing' => $s['morning_end']];
            })
            ->schema([
                Grid::make(2)->schema($fields),
                View::make('control_horario.simulation-result')->viewData(function (Get $get): array {
                    $settings = app(ScheduleSettingsQuery::class)->execute(auth()->user())['settings'];
                    $input = $settings;
                    foreach (['example_start', 'example_opening', 'example_end', 'example_closing'] as $key) {
                        $input[$key] = $get($key);
                    }
                    $input['example_closing_minutes'] = $settings['morning_closing_minutes'];

                    return ['preview' => app(ScheduleSettingsPreview::class)->calculate($input)];
                }),
            ]);
    }
}
