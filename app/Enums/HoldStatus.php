<?php

declare(strict_types=1);

namespace App\Enums;

enum HoldStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
