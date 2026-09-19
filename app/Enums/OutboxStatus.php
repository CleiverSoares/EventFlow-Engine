<?php

namespace App\Enums;

enum OutboxStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Processed = 'PROCESSED';
    case Failed = 'FAILED';
}
