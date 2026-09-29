<?php

namespace App\Enums;

/**
 * Where a USD/LBP observation came from (docs/04-BUSINESS-RULES.md).
 */
enum RateSource: string
{
    case Provider = 'provider';
    case Manual = 'manual';
    case Fixture = 'fixture';
}
