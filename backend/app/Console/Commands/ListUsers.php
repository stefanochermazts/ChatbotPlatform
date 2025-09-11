<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ListUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:list-users 
                            {--unverified : Show only unverified users}
                            {--verified : Show only verified users}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all users with their verification status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('👥 User Management Dashboard');
        $this->info('');

        // Build query based on options
        $query = User::query();

        if ($this->option('unverified')) {
            $query->whereNull('email_verified_at');
            $title = 'Unverified Users';
        } elseif ($this->option('verified')) {
            $query->whereNotNull('email_verified_at');
            $title = 'Verified Users';
        } else {
            $title = 'All Users';
        }

        $users = $query->orderBy('created_at', 'desc')->get();

        if ($users->isEmpty()) {
            $this->warn("No users found matching criteria.");
            return Command::SUCCESS;
        }

        // Display users
        $this->info("📋 {$title} ({$users->count()}):");
        $this->info('');

        $tableData = $users->map(function ($user) {
            return [
                $user->id,
                $user->name,
                $user->email,
                $user->email_verified_at ? '✅ YES' : '❌ NO',
                $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i') : '-',
                $user->created_at->format('Y-m-d H:i'),
            ];
        })->toArray();

        $this->table([
            'ID',
            'Name', 
            'Email',
            'Verified',
            'Verified At',
            'Created At'
        ], $tableData);

        // Show summary
        $this->info('');
        $this->displaySummary();

        return Command::SUCCESS;
    }

    private function displaySummary(): void
    {
        $totalUsers = User::count();
        $verifiedUsers = User::whereNotNull('email_verified_at')->count();
        $unverifiedUsers = User::whereNull('email_verified_at')->count();

        $this->info('📊 Summary:');
        $this->table(['Status', 'Count'], [
            ['Total Users', $totalUsers],
            ['Verified', $verifiedUsers],
            ['Unverified', $unverifiedUsers],
        ]);

        if ($unverifiedUsers > 0) {
            $this->info('');
            $this->warn('💡 Quick actions:');
            $this->warn('  • Verify specific user: php artisan admin:verify-email user@example.com');
            $this->warn('  • Verify all users: php artisan admin:verify-email --all');
        }
    }
}
