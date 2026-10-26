<?php

declare(strict_types=1);

namespace App\Enums;

enum Department: string
{
    case Clerk = 'clerk';
    case PublicWorks = 'public_works';
    case Parks = 'parks';
    case Planning = 'planning';
    case Police = 'police';
}
