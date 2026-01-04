<div style="display: flex; gap: 4px; align-items: center;">
    <x-filament::button
        tabindex="0"
        wire:click="translateTitleRecord('{{ $getRecord()->getKey() }}')"
        size="sm"
        color="primary"
        title="Translate title to Ukrainian"
        style="padding:0 6px;font-size:18px;line-height:1;min-width:unset;min-height:unset;background:none;border:none;box-shadow:none;"
    >
        🌐
    </x-filament::button>
    <x-filament::button
        tabindex="0"
        wire:click="resetTitleRecord('{{ $getRecord()->getKey() }}')"
        size="sm"
        color="secondary"
        title="Reset title to original"
        style="padding:0 6px;font-size:18px;line-height:1;min-width:unset;min-height:unset;background:none;border:none;box-shadow:none;"
    >
        🔄
    </x-filament::button>
</div>
