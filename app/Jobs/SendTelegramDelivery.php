<?php

namespace App\Jobs;

use App\Domain\Notificaciones\Actions\ProcessTelegramDeliveryAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SendTelegramDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public string $claimToken;

    public function __construct(public int $deliveryId)
    {
        $this->claimToken = (string) Str::uuid();
        $this->onConnection('telegram')->onQueue('telegram')->afterCommit();
    }

    public function handle(ProcessTelegramDeliveryAction $action): void
    {
        $action->execute($this->deliveryId, $this->claimToken);
    }

    public function failed(?\Throwable $exception): void
    {
        app(ProcessTelegramDeliveryAction::class)->markUncertain($this->deliveryId, $this->claimToken);
    }
}
