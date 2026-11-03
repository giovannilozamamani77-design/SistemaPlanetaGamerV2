<?php

namespace App\Filament\Support\Prestamos;

use App\Domain\Prestamos\Models\Loan;
use App\Domain\Prestamos\Models\LoanObjectType;
use App\Domain\Prestamos\Queries\LoanQuery;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class LoanForms
{
    public static function key(): Hidden
    {
        return Hidden::make('request_key')->default(fn () => (string) Str::uuid())
            ->afterStateHydrated(function (Hidden $component, mixed $state): void {
                if ($state === null || $state === '') {
                    $component->state((string) Str::uuid());
                }
            });
    }

    public static function draft(Get $get): array
    {
        $values = [];
        foreach (['type_id', 'type_revision', 'capital', 'days', 'start_date', 'due_date', 'schedule_mode', 'interest_mode', 'manual_interest'] as $key) {
            $values[$key] = $get($key);
        }
        $values['schedule_mode'] = $values['schedule_mode'] ?: 'AUTO';
        $values['interest_mode'] = $values['interest_mode'] ?: 'AUTO';

        return $values;
    }

    public static function schema(): array
    {
        return [Grid::make(['default' => 1, 'lg' => 2])->extraAttributes(['class' => 'pg-loan-registration-grid'])->schema([
            Section::make('Cliente')->icon('heroicon-o-user')->extraAttributes(['class' => 'pg-loan-card pg-loan-card-client'])->schema([
                TextInput::make('ci')->label('CI del cliente')->required()->maxLength(60)->live(onBlur: true)->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    $client = trim($state ?? '') !== '' ? app(LoanQuery::class)->borrower(auth()->user(), $state) : null;
                    if ($client) {
                        $set('name', $client['name']);
                        $set('phone', $client['phone']);
                    } elseif ($get('recognized_client')) {
                        $set('name', '');
                        $set('phone', '');
                    }
                    $set('recognized_client', $client['ci'] ?? null);
                }),
                TextInput::make('name')->label('Nombre completo')->required()->maxLength(160)->columnSpan(['default' => 1, 'md' => 2]),
                TextInput::make('phone')->label('Teléfono o WhatsApp')->prefix('+591')->tel()->inputMode('numeric')->minLength(8)->maxLength(8)->rules(['regex:/\A[0-9]{8}\z/'])->validationMessages(['regex' => 'Ingresa exactamente 8 números, sin el código +591.'])->columnSpan(['default' => 1, 'md' => 2]),
                Hidden::make('recognized_client')->dehydrated(false), View::make('prestamos.client-status')->visible(fn (Get $get) => filled($get('recognized_client')))->viewData(fn (Get $get) => ['recognized' => $get('recognized_client')])->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 3]),
            Section::make('Objeto en garantía')->icon('heroicon-o-archive-box')->extraAttributes(['class' => 'pg-loan-card pg-loan-card-object'])->schema([
                Select::make('type_id')->label('Tipo de objeto')->options(fn () => app(LoanQuery::class)->types(auth()->user())->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->required()->searchable()->position('bottom')->live()->afterStateUpdated(fn ($state, Set $set) => $set('type_revision', LoanObjectType::find($state)?->revision)),
                Hidden::make('type_revision'), Textarea::make('description')->label('Descripción del objeto')->rows(2)->required()->maxLength(2000),
                Hidden::make('serial'), Hidden::make('condition'), Hidden::make('accessories'), View::make('prestamos.object-details-button')->columnSpanFull(),
            ])->columns(2),
            Section::make('Fechas y plazo')->icon('heroicon-o-calendar-days')->extraAttributes(['class' => 'pg-loan-card pg-loan-card-conditions'])->schema([
                ToggleButtons::make('days')->label('Plazo del préstamo')->options(fn () => Gate::allows('manualTerms', Loan::class) ? [15 => '15 días', 30 => '30 días', 'MANUAL' => 'Manual'] : [15 => '15 días', 30 => '30 días'])->inline()->grouped()->default(30)->required()->live()->afterStateUpdated(function ($state, $old, Get $get, Set $set): void {
                    if ($state === 'MANUAL') {
                        $draft = array_replace(self::draft($get), ['schedule_mode' => 'AUTO', 'days' => $old]);
                        $schedule = app(LoanQuery::class)->schedulePreview(auth()->user(), $draft);
                        $set('due_date', $schedule['due_date'] ?? $get('due_date'));
                    }
                    $set('schedule_mode', $state === 'MANUAL' ? 'MANUAL' : 'AUTO');
                })->columnSpanFull(),
                Hidden::make('schedule_mode')->default('AUTO'),
                DatePicker::make('start_date')->label('Inicio')->native()->default(fn () => CarbonImmutable::now('America/La_Paz')->toDateString())->maxDate(fn () => CarbonImmutable::now('America/La_Paz')->toDateString())->required()->live(),
                DatePicker::make('due_date')->label('Vencimiento')->native()->visible(fn (Get $get) => $get('schedule_mode') === 'MANUAL')->required(fn (Get $get) => $get('schedule_mode') === 'MANUAL')->afterOrEqual('start_date')->live(),
                View::make('prestamos.schedule-preview')->visible(fn (Get $get) => $get('schedule_mode') !== 'MANUAL')->viewData(fn (Get $get) => ['schedule' => app(LoanQuery::class)->schedulePreview(auth()->user(), self::draft($get))]),
            ])->columns(2),
            Section::make('Importes del préstamo')->icon('heroicon-o-banknotes')->extraAttributes(['class' => 'pg-loan-card pg-loan-card-summary'])->schema([
                TextInput::make('capital')->label('Capital a prestar')->prefix('Bs')->inputMode('decimal')->placeholder('0')->required()->live(debounce: 350)->afterStateUpdated(fn ($livewire) => $livewire->resetValidation('data.capital')),
                Select::make('interest_mode')->label('Cálculo de interés')->helperText(fn (Get $get) => $get('schedule_mode') === 'MANUAL' ? 'Si el plazo supera 30 días, elige interés manual.' : null)->options(['AUTO' => 'Automático', 'MANUAL' => 'Manual'])->default('AUTO')->live()->visible(fn () => Gate::allows('manualTerms', Loan::class)),
                TextInput::make('manual_interest')->label('Interés manual')->prefix('Bs')->inputMode('decimal')->visible(fn (Get $get) => $get('interest_mode') === 'MANUAL')->required(fn (Get $get) => $get('interest_mode') === 'MANUAL')->live(debounce: 350),
                View::make('prestamos.terms-preview')->viewData(fn (Get $get) => ['terms' => app(LoanQuery::class)->preview(auth()->user(), self::draft($get))])->columnSpanFull(),
            ])->columns(2),
        ])->columnSpanFull(), self::key()];
    }
}
