<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Console\Command;

class AssociateUserTenant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:associate-user-tenant 
                            {--user= : User email}
                            {--tenant= : Tenant slug or ID}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Associate a user with a tenant';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔗 Associating user with tenant...');

        // Get user
        $userEmail = $this->getUserEmail();
        $user = User::where('email', $userEmail)->first();

        if (!$user) {
            $this->error("❌ User with email '{$userEmail}' not found!");
            return Command::FAILURE;
        }

        // Get tenant
        $tenantIdentifier = $this->getTenantIdentifier();
        $tenant = $this->findTenant($tenantIdentifier);

        if (!$tenant) {
            $this->error("❌ Tenant '{$tenantIdentifier}' not found!");
            return Command::FAILURE;
        }

        // Show current association
        $this->displayCurrentStatus($user, $tenant);

        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm("Associate user '{$user->email}' with tenant '{$tenant->name}'?")) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        // Associate
        return $this->associateUserTenant($user, $tenant);
    }

    private function getUserEmail(): string
    {
        $userEmail = $this->option('user');
        
        if (!$userEmail) {
            // Show available users
            $users = User::with('tenants')->select('id', 'name', 'email')->get();
            
            if ($users->isNotEmpty()) {
                $this->info('Available users:');
                $this->table(['ID', 'Name', 'Email', 'Current Tenants'], 
                    $users->map(function($user) {
                        $tenantNames = $user->tenants->pluck('name')->join(', ');
                        return [$user->id, $user->name, $user->email, $tenantNames ?: 'None'];
                    })->toArray()
                );
            }
            
            $userEmail = $this->ask('Enter user email');
        }
        
        return trim($userEmail);
    }

    private function getTenantIdentifier(): string
    {
        $tenantIdentifier = $this->option('tenant');
        
        if (!$tenantIdentifier) {
            // Show available tenants
            $tenants = Tenant::select('id', 'name', 'slug', 'active')->get();
            
            if ($tenants->isNotEmpty()) {
                $this->info('Available tenants:');
                $this->table(['ID', 'Name', 'Slug', 'Active'], 
                    $tenants->map(fn($tenant) => [
                        $tenant->id, 
                        $tenant->name, 
                        $tenant->slug, 
                        $tenant->active ? 'Yes' : 'No'
                    ])->toArray()
                );
            }
            
            $tenantIdentifier = $this->ask('Enter tenant slug or ID');
        }
        
        return trim($tenantIdentifier);
    }

    private function findTenant(string $identifier): ?Tenant
    {
        // Try to find by ID first
        if (is_numeric($identifier)) {
            $tenant = Tenant::find($identifier);
            if ($tenant) return $tenant;
        }
        
        // Try to find by slug
        return Tenant::where('slug', $identifier)->first();
    }

    private function displayCurrentStatus(User $user, Tenant $tenant): void
    {
        $currentTenants = $user->tenants()->get();
        $currentTenantNames = $currentTenants->pluck('name')->join(', ');
        
        $this->info('');
        $this->info('📋 Association Details:');
        
        $this->table(['Field', 'Value'], [
            ['User ID', $user->id],
            ['User Name', $user->name],
            ['User Email', $user->email],
            ['Current Tenants', $currentTenantNames ?: 'None'],
            ['New Tenant ID', $tenant->id],
            ['New Tenant Name', $tenant->name],
            ['New Tenant Slug', $tenant->slug],
        ]);

        if ($currentTenants->isNotEmpty()) {
            $this->warn("⚠️  User is currently associated with: {$currentTenantNames}");
            $this->warn('   This will add another tenant association.');
        }
    }

    private function associateUserTenant(User $user, Tenant $tenant): int
    {
        try {
            // Check if already associated
            if ($user->tenants()->where('tenant_id', $tenant->id)->exists()) {
                $this->warn("⚠️  User is already associated with tenant '{$tenant->name}'!");
                return Command::SUCCESS;
            }
            
            // Associate user with tenant
            $tenant->users()->attach($user->id, ['role' => 'admin']);

            $this->info('');
            $this->info("✅ User '{$user->email}' successfully associated with tenant '{$tenant->name}' as admin!");

            $this->info('');
            $this->warn('🚀 User can now login at: ' . config('app.url') . '/login');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to associate user with tenant: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
