<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Last attempt/success of an external sync such as rates:sync (M04).
 */
#[Fillable(['name', 'last_attempt_at', 'last_success_at', 'last_error_code'])]
class IntegrationSyncState extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
