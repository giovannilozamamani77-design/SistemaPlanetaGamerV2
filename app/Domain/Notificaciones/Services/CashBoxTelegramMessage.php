<?php

namespace App\Domain\Notificaciones\Services;

use App\Domain\Cajas\Models\CashBoxClose;
use App\Domain\Cajas\Models\CashBoxSession;
use App\Models\User;
use App\Support\Bolivianos;
use Illuminate\Support\Facades\DB;

class CashBoxTelegramMessage
{
    public function opening(CashBoxSession $session): string
    {
        $session->loadMissing(['box', 'user', 'configuration']);
        $lines = ['🟢 CAJA ABIERTA · Apertura registrada', 'Caja: '.$this->line($session->box->name), 'Responsable: '.$this->line($session->user->name), 'Sesión #'.$session->id.' · '.$session->opened_at->timezone('America/La_Paz')->format('d/m/Y H:i').' · Bolivia'];
        $lines[] = '';
        $lines[] = 'DETALLE DE SALDOS';
        $lines = [...$lines, ...$this->balances($session, $session->expected_balances, $session->opening_balances)];
        $lines[] = 'Total contado: '.$this->money(array_sum($session->opening_balances));
        $lines[] = 'Capital operativo de referencia: '.$this->money($session->operating_capital_cents ?? $session->base_capital_cents);
        $difference = (int) ($session->comparison['difference_cents'] ?? 0);
        $receipt = DB::table('cash_box_receipt_discrepancies')->where('cash_box_session_id', $session->id)->first();
        if ($difference < 0) {
            $lines[] = 'Faltante registrado: '.$this->money(-$difference);
            if ($receipt?->economic_incident_id) {
                $incident = DB::table('economic_incidents')->where('id', $receipt->economic_incident_id)->first();
                $owner = $incident ? User::find($incident->user_id) : null;
                if ($owner) {
                    $lines[] = 'Responsable anterior: '.$this->line($owner->name).' · Incidencia #'.$receipt->economic_incident_id;
                }
                $lines[] = 'Deuda registrada automáticamente; no requiere confirmar el faltante.';
            } else {
                $lines[] = 'Responsabilidad pendiente de revisión; no se atribuye una deuda sin registro.';
            }
        } elseif ($difference > 0) {
            $lines[] = 'Sobrante registrado: '.$this->money($difference).' · No aumenta el capital operativo.';
        } else {
            $lines[] = '✅ Sin diferencias de recepción.';
        }
        $lines[] = 'Estado: Abierta';
        $lines[] = $this->link('filament.inicio.pages.cajas', 'Ver cajas');

        return implode("\n", array_filter($lines, fn ($line) => $line !== ''));
    }

    public function closing(CashBoxClose $close): string
    {
        $close->loadMissing(['box', 'user']);
        $session = CashBoxSession::with('configuration')->findOrFail($close->cash_box_session_id);
        $lines = [($close->released_at ? '🔴 CAJA CERRADA' : '🟠 CIERRE PENDIENTE').' · Cierre registrado', 'Caja: '.$this->line($close->box->name), 'Responsable: '.$this->line($close->user->name), 'Turno: '.($close->period === 'manana' ? 'Mañana' : 'Tarde'), 'Cierre #'.$close->id.' · Sesión #'.$session->id.' · '.$close->created_at->timezone('America/La_Paz')->format('d/m/Y H:i').' · Bolivia'];
        $lines[] = '';
        $lines[] = 'DETALLE DE SALDOS';
        $lines = [...$lines, ...$this->balances($session, $close->expected_balances, $close->counted_balances)];
        $lines[] = 'Esperado: '.$this->money(array_sum($close->expected_balances));
        $lines[] = 'Contado: '.$this->money(array_sum($close->counted_balances));
        $difference = (int) $close->difference_cents;
        $lines[] = 'Faltante registrado: '.$this->money(max(0, -$difference));
        $lines[] = 'Sobrante registrado: '.$this->money(max(0, $difference));
        if ($close->economic_incident_id) {
            $lines[] = 'Incidencia #'.$close->economic_incident_id.' · El faltante íntegro se conserva a cargo de '.$this->line($close->user->name).'.';
        }
        $lines[] = 'Capital operativo de referencia: '.$this->money((int) $close->capital_cents);
        $lines[] = 'Saldo posterior: '.$this->money(array_sum($close->real_balances));
        if (! $close->requires_liquidation) {
            $lines[] = 'Liquidación: no requerida';
        } elseif (! $close->liquidation_completed) {
            $lines[] = 'Liquidación: pendiente';
        } elseif ((int) $close->liquidated_cents > 0) {
            $lines[] = 'Liquidación realizada: '.$this->money((int) $close->liquidated_cents);
        } else {
            $lines[] = 'Liquidación cumplida: Bs 0 · Sin importe para transferir.';
        }
        if ($close->commission_account_id) {
            $account = DB::table('cash_box_commission_accounts')->where('id', $close->commission_account_id)->first();
            if ($account) {
                $lines[] = 'Destino de liquidación: '.$this->line($account->name);
            }
        }
        $lines[] = 'Estado: '.($close->released_at ? 'Cerrada y disponible' : (! $close->closure_approved_at ? 'Pendiente de autorización administrativa' : 'Pendiente de liberación'));
        $lines[] = $this->link('filament.inicio.resources.historial-cierres.index', 'Ver historial de cierres');

        return implode("\n", array_filter($lines, fn ($line) => $line !== ''));
    }

    private function balances(CashBoxSession $session, array $expected, array $counted): array
    {
        $lines = [];
        $accounts = $session->configuration->accounts ?? [];
        foreach (array_slice($counted, 0, 8, true) as $code => $amount) {
            $label = $this->line($accounts[$code]['name'] ?? $code, 60);
            $lines[] = $label.' · esperado '.$this->money((int) ($expected[$code] ?? 0)).' · contado '.$this->money((int) $amount);
        }

        if (count($counted) > 8) {
            $lines[] = '… y '.(count($counted) - 8).' cuentas más en el registro; los totales incluyen todas las cuentas.';
        }

        return $lines;
    }

    private function money(int $cents): string
    {
        return 'Bs '.Bolivianos::formatCompact($cents);
    }

    private function line(string $value, int $limit = 80): string
    {
        $value = preg_replace('~https?://\S+|tg://\S+|www\.\S+~iu', '[enlace omitido]', $value) ?? '';
        $value = preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? '';

        return mb_substr(trim(str_replace('@', '', $value)), 0, $limit);
    }

    private function link(string $route, string $label): string
    {
        $root = rtrim((string) config('app.url'), ' /');
        $url = parse_url($root);
        if (strlen($root) > 512 || ! filter_var($root, FILTER_VALIDATE_URL) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            return '';
        }

        return $label.': '.$root.route($route, [], false);
    }
}
