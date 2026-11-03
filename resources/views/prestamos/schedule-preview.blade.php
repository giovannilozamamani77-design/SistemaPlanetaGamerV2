<div class="fi-fo-field">
    <div class="grid gap-y-2">
        <label for="loan-automatic-due-date" class="fi-fo-field-label"><span class="fi-fo-field-label-content">Vencimiento</span></label>
        <x-filament::input.wrapper>
            <x-filament::input id="loan-automatic-due-date" type="date" :value="$schedule['due_date'] ?? ''" readonly aria-readonly="true" />
        </x-filament::input.wrapper>
    </div>
</div>
