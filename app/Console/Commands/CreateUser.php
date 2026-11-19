<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'user:create
        {email : The new user\'s email address}
        {--name= : Full name (asked for when left out)}
        {--admin : Make the user an admin}';

    protected $description = 'Create a verified user, optionally as an admin. The password is asked for, never passed as an option.';

    public function handle(): int
    {
        $input = [
            'email' => strtolower(trim((string) $this->argument('email'))),
            'name' => (string) ($this->option('name') ?: $this->ask('Name')),
        ];

        // Check these first, so nobody types a password only to hear the email is taken.
        $errors = $this->errorsFor($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        // Typed at a hidden prompt, so it never lands in shell history or the process list.
        $password = '';

        if ($errors === []) {
            $password = (string) $this->secret('Password');

            $errors = $this->errorsFor($input + ['password' => $password, 'password_confirmation' => (string) $this->secret('Confirm password')], [
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ]);
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $input['name'], 'email' => $input['email'], 'password' => $password]);
        // Not mass assignable on purpose: only an operator with shell access sets these.
        $user->forceFill([
            'is_admin' => (bool) $this->option('admin'),
            'email_verified_at' => now(),
        ])->save();

        $this->info("Created {$user->email}".($user->is_admin ? ' as an admin.' : '.'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $input
     * @param  array<string, array<int, mixed>>  $rules
     * @return list<string>
     */
    private function errorsFor(array $input, array $rules): array
    {
        $validator = Validator::make($input, $rules);

        return $validator->fails() ? array_values($validator->errors()->all()) : [];
    }
}
