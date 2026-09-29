<?php

namespace App\Enums;

/**
 * Active and blocked cards can switch back and forth; archived is terminal.
 */
enum CardStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Archived = 'archived';
}
