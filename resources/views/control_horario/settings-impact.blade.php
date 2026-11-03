<aside class="pg-schedule-impact">
    <h3>Qué cambia al guardar</h3>
    <p>{{ $effects[0] }}</p>
    <details><summary>Ver efectos y condiciones</summary><ul>@foreach (array_slice($effects, 1) as $effect)<li>{{ $effect }}</li>@endforeach</ul></details>
</aside>
