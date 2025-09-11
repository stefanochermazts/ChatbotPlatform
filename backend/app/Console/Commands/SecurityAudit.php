<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SecurityAudit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:security-audit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a security audit of the application';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Running security audit...');
        $this->info('');

        $issues = [];
        $recommendations = [];

        // Check environment
        $issues = array_merge($issues, $this->checkEnvironment());
        
        // Check users
        $issues = array_merge($issues, $this->checkUsers());
        
        // Check database
        $issues = array_merge($issues, $this->checkDatabase());
        
        // Check configuration
        $issues = array_merge($issues, $this->checkConfiguration());

        // Display results
        $this->displayResults($issues);

        return empty($issues) ? Command::SUCCESS : Command::FAILURE;
    }

    private function checkEnvironment(): array
    {
        $issues = [];

        $this->info('🌍 Checking environment...');

        // Check if in production
        if (!app()->environment('production')) {
            $issues[] = "Environment is not set to 'production'";
        }

        // Check debug mode
        if (config('app.debug')) {
            $issues[] = 'Debug mode is enabled (security risk)';
        }

        // Check HTTPS
        if (!request()->isSecure() && !str_starts_with(config('app.url'), 'https://')) {
            $issues[] = 'HTTPS not configured';
        }

        // Check app key
        if (config('app.key') === 'base64:' || empty(config('app.key'))) {
            $issues[] = 'Application key not set or invalid';
        }

        return $issues;
    }

    private function checkUsers(): array
    {
        $issues = [];

        $this->info('👥 Checking users...');

        // Check for default users
        $defaultUsers = User::whereIn('email', [
            'test@example.com',
            'admin@example.com', 
            'user@example.com'
        ])->count();

        if ($defaultUsers > 0) {
            $issues[] = "Found {$defaultUsers} default/test users";
        }

        // Check for weak passwords (users created with 'password')
        $testUsers = User::where('email', 'like', '%@example.%')
            ->orWhere('email', 'like', '%test%')
            ->count();

        if ($testUsers > 0) {
            $issues[] = "Found {$testUsers} test/example users";
        }

        // Check total admin users
        $totalUsers = User::count();
        if ($totalUsers === 0) {
            $issues[] = 'No users found - system may be inaccessible';
        }

        return $issues;
    }

    private function checkDatabase(): array
    {
        $issues = [];

        $this->info('🗄️ Checking database...');

        try {
            // Test database connection
            DB::connection()->getPdo();
            
            // Check for migrations
            $migrations = DB::table('migrations')->count();
            if ($migrations === 0) {
                $issues[] = 'No migrations found - database may not be set up';
            }

        } catch (\Exception $e) {
            $issues[] = 'Database connection failed: ' . $e->getMessage();
        }

        return $issues;
    }

    private function checkConfiguration(): array
    {
        $issues = [];

        $this->info('⚙️ Checking configuration...');

        // Check cache driver
        if (config('cache.default') === 'array') {
            $issues[] = 'Cache driver is set to array (not persistent)';
        }

        // Check session driver
        if (config('session.driver') === 'array') {
            $issues[] = 'Session driver is set to array (not persistent)';
        }

        // Check queue driver
        if (config('queue.default') === 'sync') {
            $issues[] = 'Queue driver is set to sync (not suitable for production)';
        }

        // Check mail configuration
        if (config('mail.default') === 'log') {
            $issues[] = 'Mail driver is set to log (emails not being sent)';
        }

        return $issues;
    }

    private function displayResults(array $issues): void
    {
        $this->info('');
        
        if (empty($issues)) {
            $this->info('✅ Security audit passed! No critical issues found.');
        } else {
            $this->error('❌ Security audit found issues:');
            $this->info('');
            
            foreach ($issues as $issue) {
                $this->error("  • {$issue}");
            }
            
            $this->info('');
            $this->warn('🔧 RECOMMENDED ACTIONS:');
            $this->warn('  • Run: php artisan admin:remove-default-users');
            $this->warn('  • Run: php artisan admin:create-user');
            $this->warn('  • Set APP_DEBUG=false in .env');
            $this->warn('  • Set APP_ENV=production in .env');
            $this->warn('  • Configure HTTPS and update APP_URL');
            $this->warn('  • Set proper cache/session/queue drivers');
        }
    }
}
