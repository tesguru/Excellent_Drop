<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUser extends Command
{
    protected $signature = 'dropwatch:user
                            {email : The login email of the single dashboard user}
                            {--password= : The password, generated when omitted}
                            {--name= : The display name}';

    protected $description = 'Create or update the single dashboard user';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('The email address is not valid.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16));
        $generated = $this->option('password') === null;

        $user = User::firstOrNew(['email' => $email]);

        $user->name = (string) ($this->option('name') ?: ($user->name ?: 'Owner'));
        $user->password = Hash::make($password);
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->save();

        $this->info('User ready: '.$user->email);

        if ($generated) {
            $this->line('Password: '.$password);
        }

        return self::SUCCESS;
    }
}
