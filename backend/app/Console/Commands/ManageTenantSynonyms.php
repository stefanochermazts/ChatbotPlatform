<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Database\Seeders\DefaultSynonymsSeeder;
use Illuminate\Console\Command;

class ManageTenantSynonyms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:synonyms:manage 
                            {tenant_id : ID del tenant} 
                            {--show : Mostra sinonimi attuali}
                            {--reset : Ripristina sinonimi di default}
                            {--set= : Imposta sinonimi personalizzati (JSON)}
                            {--add= : Aggiungi nuovo sinonimo (formato: "termine=sinonimi")}
                            {--remove= : Rimuovi sinonimo per termine}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gestisce i sinonimi personalizzati per un tenant specifico';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("Tenant con ID {$tenantId} non trovato");

            return 1;
        }

        $this->info("🏢 Gestione sinonimi per: {$tenant->name} (ID: {$tenant->id})");
        $this->line('');

        // Mostra sinonimi attuali
        if ($this->option('show')) {
            return $this->showSynonyms($tenant);
        }

        // Ripristina sinonimi di default
        if ($this->option('reset')) {
            return $this->resetSynonyms($tenant);
        }

        // Imposta sinonimi personalizzati
        if ($jsonData = $this->option('set')) {
            return $this->setSynonyms($tenant, $jsonData);
        }

        // Aggiungi singolo sinonimo
        if ($addData = $this->option('add')) {
            return $this->addSynonym($tenant, $addData);
        }

        // Rimuovi sinonimo
        if ($removeTerm = $this->option('remove')) {
            return $this->removeSynonym($tenant, $removeTerm);
        }

        // Se nessuna opzione, mostra help
        $this->showUsageExamples();

        return 0;
    }

    private function showSynonyms(Tenant $tenant): int
    {
        $synonyms = $tenant->custom_synonyms ?? DefaultSynonymsSeeder::getDefaultSynonyms();

        if (empty($synonyms)) {
            $this->warn('🔍 Nessun sinonimo configurato');

            return 0;
        }

        $this->info('📋 Sinonimi configurati:');

        if ($tenant->custom_synonyms === null) {
            $this->line('<comment>   (usando sinonimi di default del sistema)</comment>');
        }

        $this->line('');

        foreach ($synonyms as $term => $alternatives) {
            $this->line("  🔗 <info>{$term}</info> → <comment>{$alternatives}</comment>");
        }

        $this->line('');
        $this->info('✅ Totale: '.count($synonyms).' sinonimi configurati');

        return 0;
    }

    private function resetSynonyms(Tenant $tenant): int
    {
        $tenant->custom_synonyms = DefaultSynonymsSeeder::getDefaultSynonyms();
        $tenant->save();

        $this->info('🔄 Sinonimi ripristinati ai valori di default del sistema');
        $this->line('   Il tenant userà ora i sinonimi standard per tutti i servizi');

        return 0;
    }

    private function setSynonyms(Tenant $tenant, string $jsonData): int
    {
        $synonyms = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('❌ Formato JSON non valido: '.json_last_error_msg());
            $this->line('   Esempio valido: \'{"vigili":"polizia locale","comune":"municipio"}\'');

            return 1;
        }

        if (! is_array($synonyms)) {
            $this->error('❌ I sinonimi devono essere un oggetto JSON chiave-valore');

            return 1;
        }

        $tenant->custom_synonyms = $synonyms;
        $tenant->save();

        $this->info('💾 Sinonimi personalizzati salvati');
        $this->line('   Configurati '.count($synonyms).' sinonimi per il tenant');

        return 0;
    }

    private function addSynonym(Tenant $tenant, string $addData): int
    {
        if (! str_contains($addData, '=')) {
            $this->error('❌ Formato non valido. Usa: termine=sinonimi');
            $this->line('   Esempio: vigili="polizia locale municipale"');

            return 1;
        }

        [$term, $alternatives] = explode('=', $addData, 2);
        $term = trim($term);
        $alternatives = trim($alternatives, '"\'');

        if (empty($term) || empty($alternatives)) {
            $this->error('❌ Termine e sinonimi non possono essere vuoti');

            return 1;
        }

        $currentSynonyms = $tenant->custom_synonyms ?? DefaultSynonymsSeeder::getDefaultSynonyms();
        $currentSynonyms[$term] = $alternatives;

        $tenant->custom_synonyms = $currentSynonyms;
        $tenant->save();

        $this->info("➕ Sinonimo aggiunto: <info>{$term}</info> → <comment>{$alternatives}</comment>");

        return 0;
    }

    private function removeSynonym(Tenant $tenant, string $term): int
    {
        $currentSynonyms = $tenant->custom_synonyms ?? DefaultSynonymsSeeder::getDefaultSynonyms();

        if (! isset($currentSynonyms[$term])) {
            $this->warn("⚠️  Sinonimo '{$term}' non trovato");

            return 1;
        }

        unset($currentSynonyms[$term]);
        $tenant->custom_synonyms = $currentSynonyms;
        $tenant->save();

        $this->info("🗑️  Sinonimo rimosso: <info>{$term}</info>");

        return 0;
    }

    private function showUsageExamples(): void
    {
        $this->info('📚 Esempi di utilizzo:');
        $this->line('');
        $this->line('  <comment># Mostra sinonimi attuali</comment>');
        $this->line('  php artisan tenant:synonyms:manage 5 --show');
        $this->line('');
        $this->line('  <comment># Ripristina sinonimi di default</comment>');
        $this->line('  php artisan tenant:synonyms:manage 5 --reset');
        $this->line('');
        $this->line('  <comment># Imposta sinonimi personalizzati</comment>');
        $this->line('  php artisan tenant:synonyms:manage 5 --set=\'{"vigili":"polizia locale","comune":"municipio"}\'');
        $this->line('');
        $this->line('  <comment># Aggiungi singolo sinonimo</comment>');
        $this->line('  php artisan tenant:synonyms:manage 5 --add="biblioteca=centro lettura"');
        $this->line('');
        $this->line('  <comment># Rimuovi sinonimo</comment>');
        $this->line('  php artisan tenant:synonyms:manage 5 --remove="biblioteca"');
    }
}
