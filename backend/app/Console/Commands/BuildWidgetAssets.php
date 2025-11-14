<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class BuildWidgetAssets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'widget:build 
                            {--tenants= : Specific tenant IDs to build (comma-separated)}
                            {--all-tenants : Build assets for all tenants}
                            {--cdn : Generate CDN-optimized assets}
                            {--force : Force rebuild even if assets exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build optimized widget assets for tenants';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🏗️  Starting Chatbot Widget Asset Build...');

        try {
            // Check if Node.js and npm are available
            $this->checkDependencies();

            // Install build dependencies if needed
            $this->installBuildDependencies();

            // Get tenant configurations
            $tenants = $this->getTenantConfigurations();

            // Build assets
            $buildResult = $this->buildAssets($tenants);

            // Update database with asset info
            $this->updateAssetInfo($buildResult);

            $this->info('✅ Widget assets built successfully!');
            $this->displayBuildSummary($buildResult);

        } catch (\Exception $e) {
            $this->error('❌ Build failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Check if required dependencies are available
     */
    protected function checkDependencies(): void
    {
        $this->info('🔍 Checking build dependencies...');

        // Check Node.js
        $nodeResult = Process::run('node --version');
        if (! $nodeResult->successful()) {
            throw new \Exception('Node.js is required but not found. Please install Node.js.');
        }

        // Check npm
        $npmResult = Process::run('npm --version');
        if (! $npmResult->successful()) {
            throw new \Exception('npm is required but not found. Please install npm.');
        }

        $this->line('   ✅ Node.js: '.trim($nodeResult->output()));
        $this->line('   ✅ npm: '.trim($npmResult->output()));
    }

    /**
     * Install build dependencies
     */
    protected function installBuildDependencies(): void
    {
        $buildDir = base_path('build');
        $packageJsonPath = $buildDir.'/package.json';

        if (! file_exists($packageJsonPath)) {
            throw new \Exception('Build package.json not found at: '.$packageJsonPath);
        }

        $nodeModulesPath = $buildDir.'/node_modules';

        // Install dependencies if node_modules doesn't exist or force rebuild
        if (! is_dir($nodeModulesPath) || $this->option('force')) {
            $this->info('📦 Installing build dependencies...');

            $installResult = Process::path($buildDir)->run('npm install');

            if (! $installResult->successful()) {
                throw new \Exception('Failed to install build dependencies: '.$installResult->errorOutput());
            }

            $this->line('   ✅ Dependencies installed');
        } else {
            $this->line('   ℹ️  Dependencies already installed');
        }
    }

    /**
     * Get tenant configurations for build
     */
    protected function getTenantConfigurations(): array
    {
        $tenants = [];

        if ($this->option('tenants')) {
            // Specific tenants
            $tenantIds = explode(',', $this->option('tenants'));
            $tenants = Tenant::whereIn('id', array_map('trim', $tenantIds))->get();

            if ($tenants->isEmpty()) {
                throw new \Exception('No tenants found with IDs: '.$this->option('tenants'));
            }

        } elseif ($this->option('all-tenants')) {
            // All tenants
            $tenants = Tenant::all();

        } else {
            // No tenants - build core only
            $this->line('   ℹ️  Building core assets only (no tenant-specific assets)');

            return [];
        }

        $this->info("🏢 Found {$tenants->count()} tenant(s) for build");

        return $tenants->map(function ($tenant) {
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'api_key' => $tenant->api_key,
                'base_url' => config('app.url'),
                'theme' => $tenant->widget_config['theme'] ?? [],
                'features' => $tenant->widget_config['features'] ?? [],
            ];
        })->toArray();
    }

    /**
     * Build assets using Node.js build system
     */
    protected function buildAssets(array $tenants): array
    {
        $this->info('🔨 Running build process...');

        $buildDir = base_path('build');
        $builderScript = $buildDir.'/widget-builder.js';

        if (! file_exists($builderScript)) {
            throw new \Exception('Build script not found at: '.$builderScript);
        }

        // Prepare build command
        $command = 'node widget-builder.js';

        // Add tenant configurations
        if (! empty($tenants)) {
            $tenantsJson = json_encode($tenants);
            $command .= " --tenants '".addslashes($tenantsJson)."'";
        }

        // Add CDN flag
        if ($this->option('cdn')) {
            $command .= ' --cdn';
        }

        // Run build process
        $buildResult = Process::path($buildDir)
            ->timeout(300) // 5 minutes timeout
            ->run($command);

        if (! $buildResult->successful()) {
            throw new \Exception('Build process failed: '.$buildResult->errorOutput());
        }

        // Parse build output for summary
        $output = $buildResult->output();
        $this->line($output);

        // Read build manifest
        $manifestPath = base_path('public/widget/dist/manifest.json');
        if (file_exists($manifestPath)) {
            return json_decode(file_get_contents($manifestPath), true);
        }

        return ['status' => 'completed', 'timestamp' => time()];
    }

    /**
     * Update database with asset information
     */
    protected function updateAssetInfo(array $buildResult): void
    {
        $this->info('💾 Updating asset information...');

        // Update tenant asset paths if tenants were built
        if ($this->option('tenants') || $this->option('all-tenants')) {
            $tenantIds = $this->option('tenants')
                ? explode(',', $this->option('tenants'))
                : Tenant::pluck('id')->toArray();

            foreach ($tenantIds as $tenantId) {
                $tenant = Tenant::find(trim($tenantId));
                if ($tenant) {
                    $assetInfo = [
                        'css_path' => "/widget/dist/tenants/{$tenantId}/custom.css",
                        'config_path' => "/widget/dist/tenants/{$tenantId}/config.js",
                        'embed_path' => "/widget/dist/tenants/{$tenantId}/embed.js",
                        'build_time' => $buildResult['buildTime'] ?? time(),
                        'version' => $buildResult['version'] ?? '1.0.0',
                    ];

                    $config = $tenant->widget_config;
                    $config['assets'] = $assetInfo;
                    $tenant->widget_config = $config;
                    $tenant->save();
                }
            }
        }

        // Store core bundle info in cache/config
        $coreAssets = [
            'css_path' => $buildResult['bundles']['css']['path'] ?? null,
            'js_path' => $buildResult['bundles']['js']['path'] ?? null,
            'build_time' => $buildResult['buildTime'] ?? time(),
            'version' => $buildResult['version'] ?? '1.0.0',
        ];

        cache(['widget_core_assets' => $coreAssets], now()->addDay());

        $this->line('   ✅ Asset information updated');
    }

    /**
     * Display build summary
     */
    protected function displayBuildSummary(array $buildResult): void
    {
        $this->newLine();
        $this->info('📊 Build Summary:');

        if (isset($buildResult['bundles'])) {
            $bundles = $buildResult['bundles'];

            if (isset($bundles['css'])) {
                $cssSize = $this->formatBytes($bundles['css']['size'] ?? 0);
                $this->line("   📄 Core CSS: {$cssSize}");
            }

            if (isset($bundles['js'])) {
                $jsSize = $this->formatBytes($bundles['js']['size'] ?? 0);
                $this->line("   📄 Core JS:  {$jsSize}");
            }
        }

        $tenantCount = 0;
        if ($this->option('tenants')) {
            $tenantCount = count(explode(',', $this->option('tenants')));
        } elseif ($this->option('all-tenants')) {
            $tenantCount = Tenant::count();
        }

        if ($tenantCount > 0) {
            $this->line("   🏢 Tenants:  {$tenantCount}");
        }

        if ($this->option('cdn')) {
            $this->line('   🌐 CDN:      Enabled');
        }

        $this->newLine();
        $this->info('🚀 Assets are ready for deployment!');
        $this->line('   Core assets: /public/widget/dist/');

        if ($tenantCount > 0) {
            $this->line('   Tenant assets: /public/widget/dist/tenants/');
        }

        if ($this->option('cdn')) {
            $this->line('   CDN assets: /public/widget/dist/cdn/');
        }
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 Bytes';
        }

        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB'];
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), 2).' '.$sizes[$i];
    }
}
