<?php

namespace App\Filament\Support\Cajas;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

final class CashBoxComponentIcon
{
    public static function for(string $code, array $account): string|Htmlable
    {
        $identity = mb_strtolower($code.' '.($account['name'] ?? ''));
        $logo = match (true) {
            $code === 'efectivo' || ($account['category'] ?? null) === 'efectivo' => ['efectivo-v2.png', 'Efectivo'],
            str_contains($identity, 'bcp') => ['bcp.png', 'BCP'],
            str_contains($identity, 'yape') => ['yape.png', 'Yape'],
            str_contains($identity, 'entel') => [($account['category'] ?? null) === 'recarga' ? 'entel-pdv.png' : 'entel.jpg', 'Entel'],
            str_contains($identity, 'viva') => ['viva.jpg', 'Viva'],
            $code === 'tigo_money' || (str_contains($identity, 'tigo') && str_contains($identity, 'money')) => ['tigo-money.png', 'Tigo Money'],
            str_contains($identity, 'tigo') => ['tigo.png', 'Tigo'],
            default => null,
        };
        if ($logo) {
            return new HtmlString(view('cajas.component-logo', ['file' => $logo[0], 'brand' => $logo[1]])->render());
        }
        if (str_contains($code, 'paquete')) {
            return 'heroicon-o-squares-plus';
        }

        return match ($account['category'] ?? $code) {
            'efectivo' => 'heroicon-o-banknotes',
            'banco', 'bcp' => 'heroicon-o-building-library',
            'billetera' => 'heroicon-o-wallet',
            'tigo_money' => 'heroicon-o-signal',
            'recarga' => 'heroicon-o-device-phone-mobile',
            default => 'heroicon-o-wallet',
        };
    }
}
