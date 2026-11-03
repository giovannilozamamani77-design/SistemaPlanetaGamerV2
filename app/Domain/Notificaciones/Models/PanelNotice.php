<?php

namespace App\Domain\Notificaciones\Models;

use App\Support\Bolivianos;
use Illuminate\Database\Eloquent\Model;

class PanelNotice extends Model
{
    protected $table = 'notice_items';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'source_id' => 'integer', 'amount_cents' => 'integer', 'occurred_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }

    public function getSummaryAttribute(): string
    {
        $parts = [];
        if ($this->box_name) {
            $parts[] = $this->box_name;
        }
        if ($this->actor_name) {
            $label = match ($this->category) {
                'opening-shortage' => 'Detectado por: ','commission-payment' => 'Registrado por: ','commission-payment-void' => 'Anulado por: ',default => 'Responsable: '
            };
            $parts[] = $label.$this->actor_name;
        }
        if ($this->amount_cents > 0) {
            $parts[] = 'Bs '.Bolivianos::format($this->amount_cents);
        }
        if ($this->source_status) {
            $parts[] = match ($this->source_status) {
                'REGISTRADO' => 'Registrado','ANULADO' => 'Anulado','PENDIENTE_RESTABLECIDO' => 'Importe devuelto a tu pendiente','RECHAZADA' => 'Rechazada','VERIFICADA' => 'Verificada','ACEPTAR' => 'Apelación aceptada','RECHAZAR' => 'Apelación rechazada','APROBADA' => 'Aprobada','DEVUELTA' => 'Devuelta para completar',default => $this->source_status
            };
        }

        return implode(' · ', $parts);
    }
}
