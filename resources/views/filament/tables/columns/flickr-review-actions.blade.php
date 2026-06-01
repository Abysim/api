@php
    use App\Enums\FlickrPhotoStatus;

    $record = $getRecord();
    $recordKey = $record?->getKey();

    $statusFilterState = $getLivewire()->getTableFilterState('status');
    $statusFilterValue = data_get($statusFilterState, 'value');
    $statusFilterValue = is_numeric($statusFilterValue) ? (int) $statusFilterValue : null;

    $isPendingReview = ($statusFilterValue === FlickrPhotoStatus::PENDING_REVIEW->value);
    $isApproved = ($statusFilterValue === FlickrPhotoStatus::APPROVED->value);
    $isReviewableRejected = in_array($statusFilterValue, FlickrPhotoStatus::reviewableRejectedValues(), true);
@endphp

@if (($isPendingReview || $isApproved || $isReviewableRejected) && $recordKey)
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
        @endif

        @if ($isApproved || $isReviewableRejected)
            <x-filament::link
                color="warning"
                size="sm"
                tag="button"
                class="w-24 justify-center"
                wire:click.stop="reviewRecord('{{ $recordKey }}')"
            >
                Review
            </x-filament::link>
        @endif
    </div>
@endif
