<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class RemoveDefaultUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:remove-default-users {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove default/test users from production database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧹 Scanning for default/test users...');

        // Define patterns for default/test users
        $defaultEmails = [
            'test@example.com',
            'admin@example.com',
            'user@example.com',
            'demo@example.com',
            'test@test.com',
            'admin@admin.com',
        ];

        // Find users that match default patterns
        $defaultUsers = User::whereIn('email', $defaultEmails)
            ->orWhere('email', 'like', '%@example.%')
            ->orWhere('email', 'like', '%test%')
            ->orWhere('email', 'like', '%demo%')
            ->get();

        if ($defaultUsers->isEmpty()) {
            $this->info('✅ No default/test users found.');
            return Command::SUCCESS;
        }

        // Display found users
        $this->warn("⚠️  Found {$defaultUsers->count()} default/test users:");
        $this->table(['ID', 'Name', 'Email', 'Created'], 
            $defaultUsers->map(fn($user) => [
                $user->id,
                $user->name,
                $user->email,
                $user->created_at->format('Y-m-d H:i:s')
            ])->toArray()
        );

        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm('Are you sure you want to delete these users?')) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        // Delete users
        try {
            $deletedCount = 0;
            foreach ($defaultUsers as $user) {
                $this->info("Deleting user: {$user->email}");
                $user->delete();
                $deletedCount++;
            }

            $this->info('');
            $this->info("✅ Successfully deleted {$deletedCount} default/test users.");
            
            // Security reminder
            $this->warn('🔐 SECURITY REMINDER:');
            $this->warn('  • Make sure you have created a secure admin user');
            $this->warn('  • Verify you can still access the system');
            
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to delete users: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
