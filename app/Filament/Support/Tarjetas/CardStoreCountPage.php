<?php

namespace App\Filament\Support\Tarjetas;

use App\Domain\Arqueos\Calculators\CashCountCalculator;
use App\Domain\Tarjetas\Actions\RecordCardStoreCountAction;
use App\Domain\Tarjetas\Models\CardProduct;
use App\Domain\Tarjetas\Queries\CardStoreSessionQuery;
use App\Filament\Pages\Tarjetas\QuickCardSale;
use App\Filament\Support\Arqueos\InteractsWithCashCount;
use App\Support\Bolivianos;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

abstract class CardStoreCountPage extends Page
{
    use InteractsWithCashCount;

    protected string $view = 'tarjetas.store-count-page';

    protected static string $countKind = 'OPEN';

    protected static bool $shouldRegisterNavigation = false;

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows(static::$countKind === 'OPEN' ? 'openSession' : 'closeSession', CardProduct::class);
    }

    public function isOpeningCount(): bool
    {
        return static::$countKind === 'OPEN';
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        Gate::authorize(static::$countKind === 'OPEN' ? 'openSession' : 'closeSession', CardProduct::class);
        $status = app(CardStoreSessionQuery::class)->status(auth()->user());
        if (! $status['initialized'] || (static::$countKind === 'OPEN' ? (bool) $status['session'] : ! $status['is_owner'])) {
            $this->redirect(QuickCardSale::getUrl());

            return;
        }
        $this->form->fill(app(CardStoreSessionQuery::class)->form(auth()->user(), static::$countKind));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->schema(CardStoreSessionForms::schema(static::$countKind));
    }

    public function confirmCount(): void
    {
        Gate::authorize(static::$countKind === 'OPEN' ? 'openSession' : 'closeSession', CardProduct::class);
        try {
            app(RecordCardStoreCountAction::class)->{static::$countKind === 'OPEN' ? 'open' : 'close'}(auth()->user(), $this->form->getState());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors['data.'.($key === 'request_key' ? 'cash' : $key)] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        Notification::make()->title(static::$countKind === 'OPEN' ? 'Tarjetas abierta con lo recibido' : 'Tarjetas cerrada; conteo registrado')->success()->send();
        $this->redirect(QuickCardSale::getUrl());
    }

    public function cancelCount(): void
    {
        Gate::authorize(static::$countKind === 'OPEN' ? 'openSession' : 'closeSession', CardProduct::class);
        $this->resetCashCount();
        $this->data = [];
        $this->redirect(QuickCardSale::getUrl());
    }

    protected function authorizeCashCountAccess(): void
    {
        Gate::authorize(static::$countKind === 'OPEN' ? 'openSession' : 'closeSession', CardProduct::class);
    }

    protected function authorizeCashCountStep(): void
    {
        $this->authorizeCashCountAccess();
        $status = app(CardStoreSessionQuery::class)->status(auth()->user());
        abort_unless($status['initialized'] && (static::$countKind === 'OPEN' ? ! $status['session'] : $status['is_owner']), 403);
    }

    protected function applyAcceptedCashCount(array $snapshot): void
    {
        $this->data['cash'] = Bolivianos::formatCompact($snapshot['total_cents']);
    }

    public function updatedData(mixed $value, string $key): void
    {
        if ($key === 'cash' && $this->acceptedCashCount && ! app(CashCountCalculator::class)->matchesCash($this->acceptedCashCount, $value)) {
            $this->acceptedCashCount = null;
        }
    }
}
