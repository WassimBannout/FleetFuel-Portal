<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ActivateUser extends Command
{
    protected $signature = 'users:activate {email : Email of the account to re-enable}';

    protected $description = 'Re-enable a disabled account (revoked API tokens stay revoked)';

    public function handle(UserAccountService $accounts): int
    {
        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No account has that email.');

            return self::FAILURE;
        }

        if ($user->is_active) {
            $this->info('That account is already active; nothing changed.');

            return self::SUCCESS;
        }

        $accounts->activate($user);

        $this->info("Re-enabled {$user->email}. The user signs in again with their existing password.");

        return self::SUCCESS;
    }
}
