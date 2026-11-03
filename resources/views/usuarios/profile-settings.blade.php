<div class="pg-profile-settings">
    @if ($rules !== null)
    <header class="pg-settings-header">
        <div>
            <span class="pg-profile-eyebrow">SOLO ADMINISTRACIÓN</span>
            <h2>Ajustes de asistencia y diferencias</h2>
            <p>Son reglas para el personal del sistema; no son preferencias de tu cuenta.</p>
        </div>
        {{ $settingsAction }}
    </header>
    <div class="pg-settings-grid">
        <section class="pg-setting-card">
            <h3>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedClock) }} Tolerancia de llegada</h3>
            <strong>{{ $rules['tolerance_minutes'] }} <small>minutos</small></strong>
            <p>Margen a partir del inicio de la jornada. Sin minutos adicionales de gracia.</p>
            <p>La primera apertura BCP confirmada determina el retraso; el PIN registra la llegada.</p>
        </section>
        <section class="pg-setting-card">
            <h3>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedBanknotes) }} Diferencia alta</h3>
            <strong>Bs {{ \App\Support\Bolivianos::format($rules['high_difference_cents']) }}</strong>
            <p>Se aplica a faltantes y sobrantes por componente. Superar este importe exige segunda comprobación: volver a ingresar el monto y registrar un motivo.</p>
            <p>Este umbral no perdona deudas ni aplica descuentos de sueldo.</p>
        </section>
        <section class="pg-setting-card">
            <h3>Ponderación y crédito de horario</h3>
            <strong>Factor ×{{ $rules['delay_multiplier'] }}</strong>
            <p>{{ $rules['multiplier_minutes'] === null ? 'Se aplica a todo el retraso.' : 'Primeros '.$rules['multiplier_minutes'].' minutos después de la tolerancia; el resto cuenta una vez.' }}</p>
            <p>Crédito automático: {{ $rules['automatic_credit'] ? 'activado' : 'desactivado' }}. La cajera puede aplicar su crédito propio y Administración también puede registrarlo. Nunca crea horas nuevas.</p>
        </section>
    </div>
    <section class="pg-setting-card pg-settings-instructions">
        <h3>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedInformationCircle) }} Cómo cambiar estos ajustes</h3>
        <ol>
            <li>Selecciona <strong>Editar ajustes</strong> y revisa los valores actuales.</li>
            <li>Ingresa los nuevos valores y comprueba la vista previa.</li>
            <li>Escribe el motivo y confirma con tu PIN de Administración.</li>
        </ol>
        <p>Se guardará una nueva versión auditada para jornadas y casos futuros. Los registros anteriores conservarán sus valores originales.</p>
    </section>
    @endif
</div>