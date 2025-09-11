<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

class ListTenants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:list-tenants {--active : Show only active tenants}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all tenants with their details and associated users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🏢 Tenant Management Dashboard');
        $this->info('');

        // Build query
        $query = Tenant::query();
        
        if ($this->option('active')) {
            $query->where('active', true);
            $title = 'Active Tenants';
        } else {
            $title = 'All Tenants';
        }

        $tenants = $query->orderBy('created_at', 'desc')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found. Create one with: php artisan admin:create-tenant');
            return Command::SUCCESS;
        }

        // Display tenants
        $this->info("📋 {$title} ({$tenants->count()}):");
        $this->info('');

        foreach ($tenants as $tenant) {
            $this->displayTenantDetails($tenant);
            $this->info('');
        }

        // Show summary
        $this->displaySummary();

        return Command::SUCCESS;
    }

    private function displayTenantDetails(Tenant $tenant): void
    {
        // Get associated users
        $users = $tenant->users()->get();
        $usersList = $users->map(function($user) {
            $role = $user->pivot->role ?? 'user';
            return "{$user->name} ({$user->email}) [{$role}]";
        })->join(', ');
        
        $this->table(['Field', 'Value'], [
            ['ID', $tenant->id],
            ['Name', $tenant->name],
            ['Slug', $tenant->slug],
            ['Domain', $tenant->domain ?: 'Not set'],
            ['Active', $tenant->active ? '✅ Yes' : '❌ No'],
            ['Users', $users->count() > 0 ? $usersList : 'None'],
            ['User Count', $users->count()],
            ['Created', $tenant->created_at->format('Y-m-d H:i:s')],
            ['URL', config('app.url') . "/tenant/{$tenant->slug}"],
        ]);
    }

    private function displaySummary(): void
    {
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('active', true)->count();
        $inactiveTenants = Tenant::where('active', false)->count();
        $tenantsWithUsers = Tenant::whereHas('users')->count();
        $tenantsWithoutUsers = Tenant::whereDoesntHave('users')->count();

        $this->info('📊 Summary:');
        $this->table(['Metric', 'Count'], [
            ['Total Tenants', $totalTenants],
            ['Active Tenants', $activeTenants],
            ['Inactive Tenants', $inactiveTenants],
            ['Tenants with Users', $tenantsWithUsers],
            ['Tenants without Users', $tenantsWithoutUsers],
        ]);

        if ($tenantsWithoutUsers > 0) {
            $this->info('');
            $this->warn('💡 Quick actions:');
            $this->warn('  • Associate user: php artisan admin:associate-user-tenant');
            $this->warn('  • Create new tenant: php artisan admin:create-tenant');
        }
    }
}
