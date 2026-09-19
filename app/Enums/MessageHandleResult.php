<?php

namespace App\Enums;

enum MessageHandleResult: string
{
    case Ack = 'ack';
    case Retry = 'retry';
    case Dlq = 'dlq';
}
