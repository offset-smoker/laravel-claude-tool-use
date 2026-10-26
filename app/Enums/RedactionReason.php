<?php

declare(strict_types=1);

namespace App\Enums;

enum RedactionReason: string
{
    case PersonalContact = 'personal_contact';
    case PersonalIdentifier = 'personal_identifier';
    case Medical = 'medical';
    case Minor = 'minor';
    case SecuritySensitive = 'security_sensitive';
}
