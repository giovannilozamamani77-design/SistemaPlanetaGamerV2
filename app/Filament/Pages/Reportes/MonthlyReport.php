<?php

namespace App\Filament\Pages\Reportes;

use App\Domain\Reportes\Actions\ConfirmMonthlyReportAdjustmentAction;
use App\Domain\Reportes\Actions\ConfirmMonthlyReportCloseAction;
use App\Domain\Reportes\Queries\MonthlyReportAdjustmentQuery;
use App\Domain\Reportes\Queries\MonthlyReportCloseQuery;
use App\Domain\Reportes\Queries\MonthlyReportSnapshotQuery;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class MonthlyReport extends Page
{
    use WithPagination;

    protected string $view = 'reportes.monthly-report';

    protected static ?string $title = 'Cierre mensual';

    protected static ?string $slug = 'reportes/cierre-mensual';

    protected static bool $shouldRegisterNavigation = false;

    public string $month = '';

    public string $pin = '';

    public bool $acceptPending = false;

    public string $adjustmentReason = '';

    public bool $acceptAdjustmentPending = false;

    #[Locked]
    public array $adjustmentPreview = [];

    #[Locked]
    public string $adjustmentUuid = '';

    #[Locked]
    public ?int $selectedAdjustmentId = null;

    #[Locked]
    public array $preview = [];

    #[Locked]
    public string $previewHash = '';

    #[Locked]
    public string $requestUuid = '';

    #[Locked]
    public ?int $selectedCloseId = null;

    #[Locked]
    public string $evidenceKind = 'records';

    public static function canAccess(): bool
    {
        return auth()->check() && Gate::allows('viewDailyReports');
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function mount(): void
    {
        Gate::authorize('viewDailyReports');
        $this->month = now('America/La_Paz')->startOfMonth()->subMonth()->format('Y-m');
    }

    public function prepare(): void
    {
        Gate::authorize('viewDailyReports');
        $this->pin = '';
        $this->preview = [];
        $this->previewHash = '';
        $this->requestUuid = '';
        $this->selectedCloseId = null;
        $this->adjustmentPreview = [];
        $this->selectedAdjustmentId = null;
        $this->resetErrorBag();
        if ($existing = app(MonthlyReportCloseQuery::class)->forMonth(auth()->user(), $this->month)) {
            $this->viewClose($existing->id);

            return;
        }
        $capture = app(MonthlyReportSnapshotQuery::class)->execute(auth()->user(), $this->month);
        $this->preview = ['month' => $this->month, 'report' => $capture['snapshot']['report'], 'pending_count' => count($capture['snapshot']['pending']), 'source_count' => count($capture['snapshot']['records']), 'pending' => $capture['snapshot']['pending']];
        $this->previewHash = $capture['hash'];
        $this->requestUuid = (string) Str::uuid();
        $this->acceptPending = false;
        $this->resetPage('monthlyPendingPreview');
    }

    public function confirm(): void
    {
        Gate::authorize('viewDailyReports');
        try {
            $close = app(ConfirmMonthlyReportCloseAction::class)->execute(auth()->user(), ['month' => $this->month,
                'request_uuid' => $this->requestUuid, 'preview_hash' => $this->previewHash, 'accept_pending' => $this->acceptPending], $this->pin);
            $this->preview = [];
            $this->selectedCloseId = $close->id;
            $this->resetPage('monthlyHistoryPage');
            $this->resetPage('monthlyEvidencePage');
            Notification::make()->success()->title('Cierre mensual conservado')->body('Se muestra el original. Repetir la solicitud no crea otro cierre.')->send();
        } finally {
            $this->pin = '';
        }
    }

    public function viewClose(int $id): void
    {
        app(MonthlyReportCloseQuery::class)->detail(auth()->user(), $id);
        $this->selectedCloseId = $id;
        $this->adjustmentPreview = [];
        $this->selectedAdjustmentId = null;
        $this->resetPage('monthlyAdjustmentHistory');
        $this->resetPage('monthlyAdjustmentDetails');
        $this->preview = [];
        $this->pin = '';
        $this->evidenceKind = 'records';
        $this->resetPage('monthlyEvidencePage');
    }

    public function changeEvidence(string $kind): void
    {
        app(MonthlyReportCloseQuery::class)->detail(auth()->user(), $this->selectedCloseId ?? 0, $kind);
        $this->evidenceKind = $kind;
        $this->resetPage('monthlyEvidencePage');
    }

    public function prepareAdjustment(): void
    {
        Gate::authorize('viewDailyReports');
        $this->pin = '';
        $this->adjustmentPreview = [];
        $this->resetErrorBag();
        $quote = app(MonthlyReportAdjustmentQuery::class)->preview(auth()->user(), $this->selectedCloseId ?? 0);
        $this->adjustmentPreview = ['hash' => $quote['preview_hash'], 'base_sequence' => $quote['base_sequence'], 'changes' => $quote['changes'],
            'sales_delta_cents' => $quote['sales_delta_cents'], 'collections_delta_cents' => $quote['collections_delta_cents'], 'pending_count' => count($quote['snapshot']['pending'])];
        $this->adjustmentUuid = (string) Str::uuid();
        $this->adjustmentReason = '';
        $this->acceptAdjustmentPending = false;
        $this->resetPage('monthlyAdjustmentPreview');
    }

    public function confirmAdjustment(): void
    {
        Gate::authorize('viewDailyReports');
        try {
            $adjustment = app(ConfirmMonthlyReportAdjustmentAction::class)->execute(auth()->user(), [
                'close_id' => $this->selectedCloseId ?? 0, 'base_sequence' => $this->adjustmentPreview['base_sequence'] ?? 0,
                'request_uuid' => $this->adjustmentUuid, 'preview_hash' => $this->adjustmentPreview['hash'] ?? '',
                'reason' => $this->adjustmentReason, 'accept_pending' => $this->acceptAdjustmentPending], $this->pin);
            $this->resetErrorBag();
            $this->selectedAdjustmentId = $adjustment->id;
            $this->adjustmentPreview = [];
            $this->resetPage('monthlyAdjustmentHistory');
            $this->resetPage('monthlyAdjustmentDetails');
            Notification::make()->success()->title('Ajuste conservado')->body('El original permanece intacto. Repetir la solicitud no aplica otra diferencia.')->send();
        } finally {
            $this->pin = '';
        }
    }

    public function viewAdjustment(int $id): void
    {
        app(MonthlyReportAdjustmentQuery::class)->detail(auth()->user(), $this->selectedCloseId ?? 0, $id);
        $this->selectedAdjustmentId = $id;
        $this->resetPage('monthlyAdjustmentDetails');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('summary')->label('Volver a Reportes')->icon('heroicon-o-arrow-left')->url(DailyReport::getUrl())];
    }

    protected function getViewData(): array
    {
        $query = app(MonthlyReportCloseQuery::class);

        $adjustments = app(MonthlyReportAdjustmentQuery::class);

        return ['adjusted' => $this->selectedCloseId === null ? null : $adjustments->state(auth()->user(), $this->selectedCloseId),
            'adjustmentHistory' => $this->selectedCloseId === null ? null : $adjustments->history(auth()->user(), $this->selectedCloseId, $this->getPage('monthlyAdjustmentHistory')),
            'adjustmentDetail' => $this->selectedAdjustmentId === null || $this->selectedCloseId === null ? null : $adjustments->detail(auth()->user(), $this->selectedCloseId, $this->selectedAdjustmentId, $this->getPage('monthlyAdjustmentDetails')),
            'adjustmentPreviewRows' => new LengthAwarePaginator(array_slice($this->adjustmentPreview['changes'] ?? [], ($this->getPage('monthlyAdjustmentPreview') - 1) * 5, 5), count($this->adjustmentPreview['changes'] ?? []), 5, $this->getPage('monthlyAdjustmentPreview'), ['pageName' => 'monthlyAdjustmentPreview']),
            'previewPending' => new LengthAwarePaginator(array_slice($this->preview['pending'] ?? [], ($this->getPage('monthlyPendingPreview') - 1) * 5, 5), count($this->preview['pending'] ?? []), 5, $this->getPage('monthlyPendingPreview'), ['pageName' => 'monthlyPendingPreview']), 'history' => $query->history(auth()->user(), $this->getPage('monthlyHistoryPage')),
            'saved' => $this->selectedCloseId === null ? null : $query->detail(auth()->user(), $this->selectedCloseId, $this->evidenceKind, $this->getPage('monthlyEvidencePage'))];
    }
}
