<?php

namespace App\Enums;

enum AuditStatus: string
{
    case Success = 'SUCCESS';
    case Dispatched = 'DISPATCHED';
    case Error = 'ERROR';
}
