<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * For financial history and audit rows: they may be inserted, never updated
 * or deleted through Eloquent. A correction is a new row.
 *
 * This guards model saves and deletes. Raw query-builder statements bypass
 * model events, so application code must not issue them for these tables.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (Model $model): never {
            throw new LogicException(class_basename($model).' records are append-only and cannot be updated.');
        });

        static::deleting(function (Model $model): never {
            throw new LogicException(class_basename($model).' records are append-only and cannot be deleted.');
        });
    }
}
