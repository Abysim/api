@php
    use App\Enums\NewsStatus;

    $record = $getRecord();
    $recordKey = $record?->getKey();

    $statusFilterState = $getLivewire()->getTableFilterState('status');
    $statusFilterValue = data_get($statusFilterState, 'value');
    $statusFilterValue = is_numeric($statusFilterValue) ? (int) $statusFilterValue : null;

    $isPendingReview = ($statusFilterValue === NewsStatus::PENDING_REVIEW->value);
    $isApproved = ($statusFilterValue === NewsStatus::APPROVED->value);
    $isRejectedManually = ($statusFilterValue === NewsStatus::REJECTED_MANUALLY->value);
    $isRejectedOffTopic = ($statusFilterValue === NewsStatus::REJECTED_AS_OFF_TOPIC->value);
@endphp

@if (($isPendingReview || $isApproved || $isRejectedManually || $isRejectedOffTopic) && $recordKey)
    <div
        class="flex flex-col items-end gap-0.5"
    >
        @if ($isPendingReview)
            <x-filament::link
                color="success"
                size="sm"
                tag="button"
                class="w-24 justify-center"
                wire:click.stop="approveRecord('{{ $recordKey }}')"
            >
                Approve
            </x-filament::link>

            <x-filament::link
                color="danger"
                size="sm"
                tag="button"
                class="w-24 justify-center"
                wire:click.stop="declineRecord('{{ $recordKey }}')"
            >
                Decline
            </x-filament::link>

            <x-filament::link
                color="warning"
                size="sm"
                tag="button"
                class="w-24 justify-center"
                wire:click.stop="offtopicRecord('{{ $recordKey }}')"
            >
                Off-topic
            </x-filament::link>
        @endif

        @if ($isApproved)
            <x-filament::link
                color="success"
                size="sm"
                tag="button"
                class="w-24 justify-center"
                wire:click.stop="publishRecord('{{ $recordKey }}')"
            >
                Publish
            </x-filament::link>

            <x-filament::link
                color="warning"
                size="sm"
                tag="button"
                class="w-24 justify-center"
                wire:click.stop="sendToReviewRecord('{{ $recordKey }}')"
            >
                Review
            </x-filament::link>
        @endif

        @if ($isRejectedManually || $isRejectedOffTopic)
            <x-filament::link
                color="info"
                size="sm"
                tag="button"
                class="w-24 justify-center"
                wire:click.stop="restoreRecord('{{ $recordKey }}')"
            >
                Restore
            </x-filament::link>
        @endif
    </div>
@endif
