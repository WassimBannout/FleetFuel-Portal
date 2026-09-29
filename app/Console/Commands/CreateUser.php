<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Station;
use App\Services\UserAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class CreateUser extends Command
{
    protected $signature = 'users:create
        {email : Sign-in email address}
        {--name= : Display name}
        {--role= : admin, company_manager or station_operator}
        {--company= : Company ID (company managers only)}
        {--station= : Station ID (station operators only)}';

    protected $description = 'Create a user account (the password is typed at a hidden prompt and never shown)';

    public function handle(UserAccountService $accounts): int
    {
        $input = [
            'email' => Str::lower(trim((string) $this->argument('email'))),
            'name' => $this->option('name'),
            'role' => $this->option('role'),
            'company' => $this->option('company'),
            'station' => $this->option('station'),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'company' => ['nullable', 'integer', 'exists:companies,id'],
            'station' => ['nullable', 'integer', 'exists:stations,id'],
        ]);

        if ($validator->fails()) {
            return $this->refuse(...$validator->errors()->all());
        }

        $role = UserRole::from((string) $input['role']);
        $company = $input['company'] !== null ? Company::query()->findOrFail((int) $input['company']) : null;
        $station = $input['station'] !== null ? Station::query()->findOrFail((int) $input['station']) : null;

        try {
            $accounts->ensureValidScope($role, $company, $station);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        // A password passed as an option would end up in shell history.
        if (! $this->input->isInteractive()) {
            return $this->refuse('Run this command interactively; the password is asked for at a hidden prompt.');
        }

        $password = (string) $this->secret('Password (at least 12 characters)');

        if ($password !== (string) $this->secret('Repeat the password')) {
            return $this->refuse('The passwords do not match.');
        }

        $passwordCheck = Validator::make(['password' => $password], ['password' => ['required', Password::min(12)]]);

        if ($passwordCheck->fails()) {
            return $this->refuse(...$passwordCheck->errors()->all());
        }

        $user = $accounts->create((string) $input['name'], $input['email'], $password, $role, $company, $station);

        $this->info("Created {$role->label()} {$user->email} (user ID {$user->id}).");

        return self::SUCCESS;
    }

    private function refuse(string ...$messages): int
    {
        foreach ($messages as $message) {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
