<section class="pg-profile-header" aria-label="Tu perfil personal">
    <div class="pg-profile-cover" aria-hidden="true"><span>PLANETA GAMER</span></div>
    <div class="pg-profile-identity">
        <div class="pg-profile-avatar">
            @if ($photoUrl = $user->getFilamentAvatarUrl())
                <img src="{{ $photoUrl }}" alt="Tu foto de perfil" width="104" height="104">
            @else
                <span aria-label="Iniciales de tu nombre">{{ \Illuminate\Support\Str::of($user->name)->trim()->substr(0, 2)->upper() }}</span>
            @endif
        </div>
        <div class="pg-profile-name">
            <span class="pg-profile-eyebrow">MI ESPACIO PERSONAL</span>
            <h2>{{ $user->name }}</h2>
            <p>Tu información, tu asistencia y tu historial en un solo lugar.</p>
        </div>
        <div class="pg-profile-photo-actions">
            @if ($photoAction->isVisible()) {{ $photoAction }} @endif
            @if ($removePhotoAction->isVisible()) {{ $removePhotoAction }} @endif
            <small>Foto opcional · Solo en tu espacio personal</small>
        </div>
    </div>
    <div class="pg-profile-footer">
        <span>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedShieldCheck) }} Historial privado</span>
        <span>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedUserCircle) }} Cuenta {{ $user->is_active ? 'activa' : 'inactiva' }}</span>
        <span>{{ \Filament\Support\generate_icon_html(\Filament\Support\Icons\Heroicon::OutlinedCalendarDays) }} Miembro desde {{ $user->created_at?->timezone('America/La_Paz')->locale('es')->isoFormat('MMM YYYY') ?? '—' }}</span>
    </div>
</section>
