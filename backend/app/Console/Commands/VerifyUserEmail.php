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
    protected $signature = 'admin:verify-email 
                            {email? : User email address to verify}
                            {--all : Verify all unverified users}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually verify user email addresses (bypass email verification)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📧 Manual email verification tool...');

        if ($this->option('all')) {
            return $this->verifyAllUsers();
        }

        $email = $this->argument('email');
        
        if (!$email) {
            $email = $this->ask('Enter user email address to verify');
        }

        return $this->verifySingleUser($email);
    }

    private function verifySingleUser(string $email): int
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("❌ User with email '{$email}' not found!");
            return Command::FAILURE;
        }

        if ($user->email_verified_at) {
            $this->info("✅ User '{$email}' is already verified!");
            $this->info("   Verified at: {$user->email_verified_at->format('Y-m-d H:i:s')}");
            return Command::SUCCESS;
        }

        // Show user info
        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Name', $user->name],
            ['Email', $user->email],
            ['Status', 'UNVERIFIED'],
            ['Created', $user->created_at->format('Y-m-d H:i:s')],
        ]);

        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm("Verify email for '{$user->name}' ({$user->email})?")) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        // Verify email
        try {
            $user->email_verified_at = now();
            $user->save();

            $this->info('');
            $this->info("✅ Email verified successfully for '{$user->name}'!");
            $this->info("   User can now login at: " . config('app.url') . '/login');
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to verify email: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function verifyAllUsers(): int
    {
        $unverifiedUsers = User::whereNull('email_verified_at')->get();

        if ($unverifiedUsers->isEmpty()) {
            $this->info('✅ All users are already verified!');
            return Command::SUCCESS;
        }

        $this->warn("⚠️  Found {$unverifiedUsers->count()} unverified users:");
        
        $this->table(['ID', 'Name', 'Email', 'Created'], 
            $unverifiedUsers->map(fn($user) => [
                $user->id,
                $user->name,
                $user->email,
                $user->created_at->format('Y-m-d H:i:s')
            ])->toArray()
        );

        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm('Verify all these users?')) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        // Verify all
        try {
            $verifiedCount = 0;
            
            foreach ($unverifiedUsers as $user) {
                $user->email_verified_at = now();
                $user->save();
                $verifiedCount++;
                
                $this->info("✓ Verified: {$user->email}");
            }

            $this->info('');
            $this->info("✅ Successfully verified {$verifiedCount} users!");
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to verify users: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}