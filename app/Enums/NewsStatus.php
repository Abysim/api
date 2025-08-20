<?php

namespace App\Enums;

enum NewsStatus: int
{
    case CREATED  = 0;
    case REJECTED_BY_KEYWORD = 1;
    case REJECTED_BY_CLASSIFICATION = 2;
    case PENDING_REVIEW = 3;
    case REJECTED_MANUALLY = 4;
    case APPROVED = 5;
    case PUBLISHED = 6;
    case REJECTED_BY_DUP_TITLE = 7;
    case REJECTED_BY_DEEP_AI = 8;
    case REJECTED_AS_OFF_TOPIC = 9;
    case BEING_PROCESSED = 10;
    case REJECTED_BY_DEEPEST_AI = 11;
    case FAILED = 12;
}
