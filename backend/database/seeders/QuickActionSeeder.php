<?php

namespace Database\Seeders;

use App\Models\QuickAction;
use App\Models\Tenant;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class QuickActionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenants = Tenant::all();
        
        foreach ($tenants as $tenant) {
            $this->createDefaultActionsForTenant($tenant);
        }
        
        $this->command->info('Quick actions created for ' . $tenants->count() . ' tenants');
    }
    
    private function createDefaultActionsForTenant(Tenant $tenant): void
    {
        $defaultActions = [
            [
                'tenant_id' => $tenant->id,
                'action_type' => 'contact_support',
                'label' => 'Contatta Supporto',
                'icon' => '💬',
                'description' => 'Invia un messaggio al team di supporto',
                'action_method' => 'POST',
                'required_fields' => ['email', 'name', 'message'],
                'display_order' => 1,
                'button_style' => 'primary',
                'confirmation_message' => 'Vuoi inviare una richiesta di supporto?',
                'success_message' => 'La tua richiesta è stata inviata con successo!',
                'success_action' => 'message',
                'rate_limit_per_user' => 3,
                'rate_limit_global' => 50
            ],
            [
                'tenant_id' => $tenant->id,
                'action_type' => 'request_callback',
                'label' => 'Richiedi Richiamata',
                'icon' => '📞',
                'description' => 'Richiedi di essere ricontattato dal nostro team',
                'action_method' => 'POST',
                'required_fields' => ['phone', 'name'],
                'display_order' => 2,
                'button_style' => 'secondary',
                'confirmation_message' => 'Vuoi richiedere una richiamata?',
                'success_message' => 'Ti ricontatteremo il prima possibile!',
                'success_action' => 'message',
                'rate_limit_per_user' => 2,
                'rate_limit_global' => 30
            ],
            [
                'tenant_id' => $tenant->id,
                'action_type' => 'download_brochure',
                'label' => 'Scarica Brochure',
                'icon' => '📄',
                'description' => 'Scarica la nostra brochure informativa',
                'action_method' => 'GET',
                'required_fields' => ['email'],
                'display_order' => 3,
                'button_style' => 'outline',
                'success_message' => 'Download avviato!',
                'success_action' => 'download',
                'rate_limit_per_user' => 5,
                'rate_limit_global' => 100
            ]
        ];
        
        foreach ($defaultActions as $actionData) {
            QuickAction::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'action_type' => $actionData['action_type']
                ],
                $actionData
            );
        }
    }
}
