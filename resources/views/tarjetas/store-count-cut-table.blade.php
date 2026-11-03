<div class="pg-count-table-scroll"><table class="pg-count-stock-table pg-count-cut-table">
    <thead><tr><th>Corte</th><th>Esperado</th><th>Contado</th><th>Faltantes</th><th>Sobrantes</th></tr></thead>
    <tbody>
        @foreach ($rows as $line)
            <tr><th>{{ $line['label'] }}</th><td>{{ $line['expected'] }}</td><td>{{ $line['counted'] }}</td><td data-tone="{{ $line['missing'] ? 'missing' : 'balanced' }}">{{ $line['missing'] }}</td><td data-tone="{{ $line['surplus'] ? 'surplus' : 'balanced' }}">{{ $line['surplus'] }}</td></tr>
        @endforeach
    </tbody>
</table></div>
