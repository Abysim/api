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
        ];
    }
}
