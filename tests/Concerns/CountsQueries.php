<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Counts the SQL statements one page request runs. A test compares the count
 * before and after adding rows: if it grows, a relation is loaded per row
 * (the N+1 problem) instead of once per page.
 */
trait CountsQueries
{
    protected function queriesFor(User $user, string $uri): int
    {
        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($uri)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
