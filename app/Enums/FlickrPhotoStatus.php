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
}
