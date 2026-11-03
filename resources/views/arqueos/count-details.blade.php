<section class="pg-count-details">
    @if (is_array($count) && isset($count['total_cents']))
        <div class="pg-count-details-heading"><strong>Con arqueo</strong><span>Total: Bs {{ \App\Support\Bolivianos::format($count['total_cents']) }}</span></div>
        <details><summary>Ver detalle del arqueo</summary><table><thead><tr><th>Corte Bs</th><th>Piezas sueltas</th><th>Embalados Bs</th></tr></thead><tbody>
            @foreach ($count['denominations_cents'] ?? [] as $key => $denomination)
                @if (($count['quantities'][$key] ?? 0) > 0 || ($count['packed_amounts_cents'][$key] ?? 0) > 0)
                    <tr><td>{{ \App\Support\Bolivianos::format($denomination) }}</td><td>{{ $count['quantities'][$key] ?? 0 }}</td><td>{{ \App\Support\Bolivianos::format($count['packed_amounts_cents'][$key] ?? 0) }}</td></tr>
                @endif
            @endforeach
        </tbody></table></details>
    @else
        <p>No se tiene arqueo.</p>
    @endif
</section>
