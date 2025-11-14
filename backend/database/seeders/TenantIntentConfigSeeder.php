<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantIntentConfigSeeder extends Seeder
{
    /**
     * Configura gli intent per il tenant 5 con le impostazioni specificate.
     */
    public function run(): void
    {
        $tenant = Tenant::find(5);

        if (! $tenant) {
            $this->command->error('Tenant con ID 5 non trovato');

            return;
        }

        // Abilita tutti gli intent
        $tenant->intents_enabled = [
            'thanks' => true,
            'phone' => true,
            'email' => true,
            'address' => true,
            'schedule' => true,
        ];

        // Configura keyword aggiuntive per ogni intent
        $tenant->extra_intent_keywords = [
            'thanks' => ['mille grazie', 'ti ringrazio molto', 'molto gentile'],
            'phone' => ['centralino', 'call center'],
            'schedule' => ['ricevimento', 'sportello'],
            'address' => ['sede legale', 'ubicazione'],
            'email' => ['pec', 'posta istituzionale'],
        ];

        // Imposta modalità relaxed per KB scope
        $tenant->kb_scope_mode = 'relaxed';

        // Soglia intent a null (default)
        $tenant->intent_min_score = null;

        $tenant->save();

        $this->command->info('Configurazione intent per tenant 5 completata:');
        $this->command->info('- Intent abilitati: thanks, phone, email, address, schedule');
        $this->command->info('- Keywords extra configurate per ogni intent');
        $this->command->info('- KB scope mode: relaxed');
        $this->command->info('- Intent min score: null');
    }
}
