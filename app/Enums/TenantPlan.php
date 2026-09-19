<?php

namespace App\Enums;

enum TenantPlan: string
{
    case Basic = 'basic';
    case Pro = 'pro';
    case Enterprise = 'enterprise';
}
