<?php

namespace App\Enums;

enum FlickrPhotoStatus: int
{
    case CREATED  = 0;
    case REJECTED_BY_TAG = 1;
    case REJECTED_BY_CLASSIFICATION = 2;
    case PENDING_REVIEW = 3;
    case REJECTED_MANUALLY = 4;
    case APPROVED = 5;
    case PUBLISHED = 6;
    case REMOVED_BY_AUTHOR = 7;
    case REJECTED_BY_DUPLICATION = 8;

    /** @return self[] */
    public static function rejectedCases(): array
    {
        return array_values(self::rejectedLabels());
    }

    /** @return array<string, self> */
    public static function rejectedLabels(): array
    {
        return [
            'Tag' => self::REJECTED_BY_TAG,
            'Class' => self::REJECTED_BY_CLASSIFICATION,
            'Manual' => self::REJECTED_MANUALLY,
            'Removed' => self::REMOVED_BY_AUTHOR,
            'Dupe' => self::REJECTED_BY_DUPLICATION,
        ];
    }

    /**
     * Rejected statuses that may be manually sent back to the review queue from
     * the admin panel. Excludes REMOVED_BY_AUTHOR — that photo is gone from
     * Flickr's side, so review() → loadPhotoFile() can no longer re-download the
     * image if its local file was already deleted.
     *
     * @return self[]
     */
    public static function reviewableRejectedCases(): array
    {
        return array_values(array_filter(
            self::rejectedCases(),
            static fn (self $case): bool => $case !== self::REMOVED_BY_AUTHOR,
        ));
    }

    /** @return int[] */
    public static function reviewableRejectedValues(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::reviewableRejectedCases());
    }
}
