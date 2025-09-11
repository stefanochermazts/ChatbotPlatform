<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Console\Command;

class MakeUserSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:make-super-admin 
                            {email : User email to make super admin}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make a user super admin with access to all tenants and admin features';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("❌ User with email '{$email}' not found!");
            return Command::FAILURE;
        }

        // Show current status
        $this->displayCurrentStatus($user);

        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm("Make '{$user->name}' ({$user->email}) a super admin?")) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        return $this->makeSuperAdmin($user);
    }

    private function displayCurrentStatus(User $user): void
    {
        $currentTenants = $user->tenants()->get();
        $adminTenants = $user->tenants()->wherePivot('role', 'admin')->get();
        
        $this->info('📋 Current User Status:');
        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Name', $user->name],
            ['Email', $user->email],
            ['Email Verified', $user->email_verified_at ? '✅ Yes' : '❌ No'],
            ['Active', $user->is_active ? '✅ Yes' : '❌ No'],
            ['Current Tenants', $currentTenants->count()],
            ['Admin of Tenants', $adminTenants->pluck('name')->join(', ') ?: 'None'],
            ['Is Super Admin', $user->isAdmin() ? '✅ Yes' : '❌ No'],
        ]);

        if ($adminTenants->isNotEmpty()) {
            $this->info('');
            $this->info('Current admin access:');
            foreach ($adminTenants as $tenant) {
                $this->info("  • {$tenant->name} (ID: {$tenant->id})");
            }
        }
    }

    private function makeSuperAdmin(User $user): int
    {
        try {
            // Get all tenants
            $allTenants = Tenant::all();
            
            if ($allTenants->isEmpty()) {
                $this->warn('⚠️  No tenants found in the system!');
                $this->warn('   Create a tenant first with: php artisan admin:create-tenant');
                return Command::FAILURE;
            }

            $newAssociations = 0;
            $updatedRoles = 0;

            foreach ($allTenants as $tenant) {
                // Check if user is already associated with this tenant
                $existingAssociation = $user->tenants()->where('tenant_id', $tenant->id)->first();
                
                if ($existingAssociation) {
                    // Update role to admin if not already
                    if ($existingAssociation->pivot->role !== 'admin') {
                        $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'admin']);
                        $updatedRoles++;
                        $this->info("  ✅ Updated role to admin for tenant: {$tenant->name}");
                    } else {
                        $this->info("  ℹ️  Already admin of tenant: {$tenant->name}");
                    }
                } else {
                    // Associate user as admin
                    $tenant->users()->attach($user->id, ['role' => 'admin']);
                    $newAssociations++;
                    $this->info("  ✅ Added as admin to tenant: {$tenant->name}");
                }
            }

            $this->info('');
            $this->info("🎉 Super admin setup completed!");
            $this->info("   • New tenant associations: {$newAssociations}");
            $this->info("   • Updated roles: {$updatedRoles}");
            $this->info("   • Total tenants with admin access: {$allTenants->count()}");

            $this->info('');
            $this->warn('🚀 Admin Dashboard Access:');
            $this->warn('  • Login at: ' . config('app.url') . '/login');
            $this->warn('  • Admin panel: ' . config('app.url') . '/admin');
            $this->warn('  • User can now manage all tenants and users');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to make user super admin: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
}
