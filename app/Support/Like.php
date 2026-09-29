<?php

namespace App\Support;

/**
 * Builds a bound LIKE pattern from a user's search text. "%" and "_" typed
 * by the user are matched literally instead of acting as wildcards.
 */
final class Like
{
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }
}
