@php
    $active = $item->isActive();
    $icon = $active ? ($item->getActiveIcon() ?? $item->getIcon()) : $item->getIcon();
    $label = $item->getLabel();
    $labelText = trim(strip_tags($label instanceof \Illuminate\Contracts\Support\Htmlable ? $label->toHtml() : $label));
@endphp
<a
    {{ \Filament\Support\generate_href_html($item->getUrl(), $item->shouldOpenUrlInNewTab()) }}
    @class(['pg-nav-link', 'pg-active' => $active])
    @if ($active) aria-current="page" @endif
    @if ($item->shouldOpenUrlInNewTab()) rel="noopener noreferrer" @endif
    aria-label="{{ $labelText }}"
    x-data="{}"
    x-tooltip="{ content: @js($labelText), placement: 'right', theme: $store.theme }"
>
    @if ($icon)
        {{ \Filament\Support\generate_icon_html($icon) }}
    @else
        <span class="pg-nav-bullet" aria-hidden="true"></span>
    @endif
    <span class="pg-nav-label">{{ $label }}</span>
    @if (filled($badge = $item->getBadge()))
        <span class="pg-nav-badge">{{ $badge }}</span>
    @endif
</a>
@if ($children = $item->getChildItems())
    <div class="pg-nested-items">
        @foreach ($children as $child)
            @include('filament.navigation.item', ['item' => $child])
        @endforeach
    </div>
@endif
