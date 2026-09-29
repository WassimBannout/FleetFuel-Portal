<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DeactivateUser extends Command
{
    protected $signature = 'users:deactivate {email : Email of the account to disable}';

    protected $description = 'Disable an account: refuse sign-in, revoke its API tokens and end its sessions';

    public function handle(UserAccountService $accounts): int
    {
        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No account has that email.');

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->info('That account is already disabled; nothing changed.');

            return self::SUCCESS;
        }

        $result = $accounts->deactivate($user);

        $this->info("Disabled {$user->email}: revoked {$result['tokens']} API token(s) and ended {$result['sessions']} session(s).");

        return self::SUCCESS;
    }
}
