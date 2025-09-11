<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CreateTenant extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create-tenant 
                            {--name= : Tenant name}
                            {--slug= : Tenant slug (URL identifier)}
                            {--domain= : Tenant domain}
                            {--user= : User email to associate with tenant}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new tenant and optionally associate it with a user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🏢 Creating new tenant...');

        // Get tenant information
        $name = $this->getTenantName();
        $slug = $this->getTenantSlug($name);
        $domain = $this->getTenantDomain();
        $userEmail = $this->getUserEmail();

        // Validate inputs
        if (!$this->validateInputs($name, $slug, $domain, $userEmail)) {
            return Command::FAILURE;
        }

        // Show summary
        $this->displaySummary($name, $slug, $domain, $userEmail);

        // Confirmation
        if (!$this->option('force')) {
            if (!$this->confirm('Create this tenant?')) {
                $this->info('❌ Operation cancelled.');
                return Command::FAILURE;
            }
        }

        // Create tenant
        return $this->createTenant($name, $slug, $domain, $userEmail);
    }

    private function getTenantName(): string
    {
        $name = $this->option('name');
        
        if (!$name) {
            $name = $this->ask('Enter tenant name (e.g., "My Company")');
        }
        
        return trim($name);
    }

    private function getTenantSlug(string $name): string
    {
        $slug = $this->option('slug');
        
        if (!$slug) {
            $defaultSlug = Str::slug($name);
            $slug = $this->ask("Enter tenant slug (URL identifier)", $defaultSlug);
        }
        
        return trim(strtolower($slug));
    }

    private function getTenantDomain(): ?string
    {
        $domain = $this->option('domain');
        
        if (!$domain) {
            $domain = $this->ask('Enter tenant domain (optional, press Enter to skip)', '');
        }
        
        return empty($domain) ? null : trim($domain);
    }

    private function getUserEmail(): ?string
    {
        $userEmail = $this->option('user');
        
        if (!$userEmail) {
            // Show available users
            $users = User::select('id', 'name', 'email')->get();
            
            if ($users->isNotEmpty()) {
                $this->info('Available users:');
                $this->table(['ID', 'Name', 'Email'], 
                    $users->map(fn($user) => [$user->id, $user->name, $user->email])->toArray()
                );
            }
            
            $userEmail = $this->ask('Enter user email to associate (optional, press Enter to skip)', '');
        }
        
        return empty($userEmail) ? null : trim($userEmail);
    }

    private function validateInputs(string $name, string $slug, ?string $domain, ?string $userEmail): bool
    {
        // Validate name
        if (empty($name)) {
            $this->error('❌ Tenant name is required');
            return false;
        }

        // Validate slug
        if (empty($slug)) {
            $this->error('❌ Tenant slug is required');
            return false;
        }

        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $this->error('❌ Tenant slug can only contain lowercase letters, numbers, and dashes');
            return false;
        }

        // Check if slug already exists
        if (Tenant::where('slug', $slug)->exists()) {
            $this->error("❌ Tenant with slug '{$slug}' already exists");
            return false;
        }

        // Validate domain if provided
        if ($domain && !filter_var('http://' . $domain, FILTER_VALIDATE_URL)) {
            $this->error("❌ Invalid domain format: {$domain}");
            return false;
        }

        // Validate user email if provided
        if ($userEmail && !User::where('email', $userEmail)->exists()) {
            $this->error("❌ User with email '{$userEmail}' not found");
            return false;
        }

        return true;
    }

    private function displaySummary(string $name, string $slug, ?string $domain, ?string $userEmail): void
    {
        $this->info('');
        $this->info('📋 Tenant Summary:');
        $this->table(['Field', 'Value'], [
            ['Name', $name],
            ['Slug', $slug],
            ['Domain', $domain ?: 'Not set'],
            ['Associated User', $userEmail ?: 'None'],
            ['URL', config('app.url') . "/tenant/{$slug}"],
        ]);
    }

    private function createTenant(string $name, string $slug, ?string $domain, ?string $userEmail): int
    {
        try {
            // Create tenant
            $tenantData = [
                'name' => $name,
                'slug' => $slug,
                'domain' => $domain,
            ];

            // Add active field only if column exists
            if (Schema::hasColumn('tenants', 'active')) {
                $tenantData['active'] = true;
            }

            $tenant = Tenant::create($tenantData);

            $this->info("✅ Tenant created successfully! (ID: {$tenant->id})");

            // Associate user if provided
            if ($userEmail) {
                $user = User::where('email', $userEmail)->first();
                
                // Associate user with tenant using pivot table
                $tenant->users()->attach($user->id, ['role' => 'admin']);
                
                $this->info("✅ User '{$userEmail}' associated with tenant '{$name}' as admin");
            }

            // Display success information
            $this->displaySuccess($tenant, $userEmail);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to create tenant: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function displaySuccess(Tenant $tenant, ?string $userEmail): void
    {
        $this->info('');
        $this->info('🎉 Tenant creation completed!');
        $this->info('');
        
        $this->table(['Field', 'Value'], [
            ['Tenant ID', $tenant->id],
            ['Name', $tenant->name],
            ['Slug', $tenant->slug],
            ['Domain', $tenant->domain ?: 'Not set'],
            ['Active', $tenant->active ? 'Yes' : 'No'],
            ['Created', $tenant->created_at->format('Y-m-d H:i:s')],
        ]);

        $this->info('');
        $this->warn('🚀 Next Steps:');
        
        if ($userEmail) {
            $this->warn("  • User can now login at: " . config('app.url') . '/login');
        } else {
            $this->warn('  • Associate users with: php artisan admin:associate-user-tenant');
        }
        
        $this->warn('  • Create knowledge bases in the admin panel');
        $this->warn('  • Configure tenant settings as needed');
    }
}
