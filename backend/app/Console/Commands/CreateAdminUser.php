<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create-user 
                            {--email= : Admin email address}
                            {--name= : Admin full name}
                            {--password= : Admin password (will be prompted if not provided)}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a secure admin user for production';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔐 Creating secure admin user...');

        // Get user input
        $email = $this->getEmail();
        $name = $this->getAdminName();
        $password = $this->getPassword();

        // Validate inputs
        if (!$this->validateInputs($email, $name, $password)) {
            return Command::FAILURE;
        }

        // Check if user already exists
        if (User::where('email', $email)->exists()) {
            $this->error("❌ User with email '{$email}' already exists!");
            
            if (!$this->option('force') && !$this->confirm('Do you want to update the existing user?')) {
                return Command::FAILURE;
            }
            
            return $this->updateExistingUser($email, $name, $password);
        }

        // Create new user
        return $this->createNewUser($email, $name, $password);
    }

    private function getEmail(): string
    {
        $email = $this->option('email');
        
        if (!$email) {
            $email = $this->ask('Enter admin email address');
        }
        
        return trim($email);
    }

    private function getAdminName(): string
    {
        $name = $this->option('name');
        
        if (!$name) {
            $name = $this->ask('Enter admin full name');
        }
        
        return trim($name);
    }

    private function getPassword(): string
    {
        $password = $this->option('password');
        
        if (!$password) {
            $password = $this->secret('Enter admin password (min 8 characters)');
            
            if (!$password) {
                $this->info('🎲 Generating secure random password...');
                $password = $this->generateSecurePassword();
                $this->warn("Generated password: {$password}");
                $this->warn('⚠️  SAVE THIS PASSWORD IMMEDIATELY!');
            }
        }
        
        return $password;
    }

    private function validateInputs(string $email, string $name, string $password): bool
    {
        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            $this->error('❌ Validation errors:');
            foreach ($validator->errors()->all() as $error) {
                $this->error("  • {$error}");
            }
            return false;
        }

        return true;
    }

    private function createNewUser(string $email, string $name, string $password): int
    {
        try {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

            $this->displaySuccess($user, $password, 'created');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to create user: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function updateExistingUser(string $email, string $name, string $password): int
    {
        try {
            $user = User::where('email', $email)->first();
            
            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]);

            $this->displaySuccess($user, $password, 'updated');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Failed to update user: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function generateSecurePassword(): string
    {
        // Generate a secure password with mixed characters
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $symbols = '!@#$%^&*';
        
        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];
        
        // Fill the rest with random characters
        $allChars = $uppercase . $lowercase . $numbers . $symbols;
        for ($i = 4; $i < 16; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        // Shuffle the password
        return str_shuffle($password);
    }

    private function displaySuccess(User $user, string $password, string $action): void
    {
        $this->info('');
        $this->info('✅ Admin user successfully ' . $action . '!');
        $this->info('');
        
        $this->table(['Field', 'Value'], [
            ['ID', $user->id],
            ['Name', $user->name],
            ['Email', $user->email],
            ['Password', $password],
            ['Created', $user->created_at->format('Y-m-d H:i:s')],
        ]);
        
        $this->info('');
        $this->warn('🔐 SECURITY REMINDERS:');
        $this->warn('  • Save the password in a secure location');
        $this->warn('  • Change the password after first login');
        $this->warn('  • Enable 2FA if available');
        $this->warn('  • Remove default test users from database');
        $this->info('');
        
        $this->info('🚀 Login URL: ' . config('app.url') . '/login');
    }
}
