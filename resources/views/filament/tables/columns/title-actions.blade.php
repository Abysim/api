<div class="flex gap-1 items-center">
    <button
        type="button"
        wire:click="translateTitleRecord('{{ $getRecord()->getKey() }}')"
        title="Translate title to Ukrainian"
        class="p-0 m-0 border-0 bg-transparent text-base leading-none cursor-pointer hover:opacity-70 transition-opacity"
    >
        🌐
    </button>
    <button
        type="button"
        wire:click="resetTitleRecord('{{ $getRecord()->getKey() }}')"
        title="Reset title to original"
        class="p-0 m-0 border-0 bg-transparent text-base leading-none cursor-pointer hover:opacity-70 transition-opacity"
    >
        🔄
    </button>
    <button
        type="button"
        wire:click="addTagToTitleRecord('{{ $getRecord()->getKey() }}')"
        title="Add tag to title"
        class="p-0 m-0 border-0 bg-transparent text-base leading-none cursor-pointer hover:opacity-70 transition-opacity"
    >
        ⬅️
    </button>
</div>
