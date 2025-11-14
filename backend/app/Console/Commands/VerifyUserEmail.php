<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class VerifyUserEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:verify-email {email : The email address of the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually verify a user\'s email address';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return 1;
        }

        if ($user->hasVerifiedEmail()) {
            $this->info("User '{$user->name}' ({$email}) already has verified email.");

            return 0;
        }

        $user->email_verified_at = now();
        $user->save();

        $this->info("✅ Email verified successfully for user '{$user->name}' ({$email})");

        return 0;
    }
}
