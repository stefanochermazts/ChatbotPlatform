<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\Tenant;
use App\Services\RAG\KnowledgeBaseSelector;
use Illuminate\Console\Command;

class DebugKbSelection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'debug:kb-selection 
                          {tenant : Tenant ID}
                          {query? : Query di test (default: "orario vigili urbani")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug della selezione automatica Knowledge Base';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = (int) $this->argument('tenant');
        $query = $this->argument('query') ?: 'orario vigili urbani';

        $this->info('🔍 DEBUG KB SELECTION');
        $this->newLine();

        // 1. Verifica tenant
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato!");

            return 1;
        }

        $this->info("🏢 Tenant: {$tenant->name} (ID: {$tenantId})");
        $this->info("🔤 Query: \"{$query}\"");
        $this->info('🌍 Multi-KB Search: '.($tenant->multi_kb_search ? 'ABILITATO' : 'DISABILITATO'));
        $this->newLine();

        // 2. Lista tutte le KB del tenant
        $knowledgeBases = KnowledgeBase::where('tenant_id', $tenantId)->get();

        $this->info('📚 KNOWLEDGE BASES DISPONIBILI:');
        $kbData = [];
        foreach ($knowledgeBases as $kb) {
            $docCount = Document::where('knowledge_base_id', $kb->id)->count();
            $kbData[] = [
                $kb->id,
                $kb->name,
                $kb->is_default ? 'SÌ' : 'NO',
                $docCount,
                $kb->created_at->format('d/m/Y H:i'),
            ];
        }

        $this->table(
            ['ID', 'Nome', 'Default', 'Documenti', 'Creata'],
            $kbData
        );

        if ($knowledgeBases->isEmpty()) {
            $this->error('❌ Nessuna Knowledge Base trovata per questo tenant!');

            return 1;
        }

        $this->newLine();

        // 3. Test selezione automatica KB
        $this->info('🎯 TEST SELEZIONE AUTOMATICA KB:');

        try {
            $selector = app(KnowledgeBaseSelector::class);
            $result = $selector->selectForQuery($tenantId, $query);

            $selectedKbId = $result['knowledge_base_id'] ?? null;
            $selectedKbName = $result['kb_name'] ?? 'Unknown';
            $reason = $result['reason'] ?? 'unknown';

            if ($selectedKbId) {
                $this->info("✅ KB Selezionata: {$selectedKbName} (ID: {$selectedKbId})");
                $this->info("🔍 Motivo selezione: {$reason}");

                // Verifica documenti nella KB selezionata
                $docsInSelectedKb = Document::where('knowledge_base_id', $selectedKbId)->count();
                $this->info("📄 Documenti nella KB selezionata: {$docsInSelectedKb}");

                if ($docsInSelectedKb === 0) {
                    $this->error('🚨 PROBLEMA CRITICO: KB selezionata è VUOTA!');
                    $this->warn('   Questo spiega perché RAG Tester non trova nulla.');
                }
            } else {
                $this->warn('⚠️  Nessuna KB selezionata');
                $this->info("🔍 Motivo: {$reason}");

                // Trova KB default come fallback
                $defaultKb = KnowledgeBase::where('tenant_id', $tenantId)
                    ->where('is_default', true)
                    ->first();

                if ($defaultKb) {
                    $this->info("🔄 Fallback a KB Default: {$defaultKb->name} (ID: {$defaultKb->id})");
                    $docsInDefault = Document::where('knowledge_base_id', $defaultKb->id)->count();
                    $this->info("📄 Documenti nella KB default: {$docsInDefault}");

                    if ($docsInDefault === 0) {
                        $this->error('🚨 PROBLEMA: Anche la KB default è VUOTA!');
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Errore durante selezione: {$e->getMessage()}");
            $this->error('Stack: '.$e->getTraceAsString());
        }

        $this->newLine();

        // 4. Suggerimenti
        $this->info('💡 SUGGERIMENTI:');

        if (! $tenant->multi_kb_search) {
            $this->warn('• Abilita Multi-KB Search per cercare in tutte le KB');
            $this->line('  Comando: php artisan tinker --execute="App\\Models\\Tenant::find('.$tenantId.')->update([\'multi_kb_search\' => true]);"');
        }

        $kbWithDocs = $knowledgeBases->filter(fn ($kb) => Document::where('knowledge_base_id', $kb->id)->count() > 0);
        if ($kbWithDocs->count() > 1) {
            $this->info('• Hai documenti in multiple KB - Multi-KB Search potrebbe aiutare');
        }

        $emptyKbs = $knowledgeBases->filter(fn ($kb) => Document::where('knowledge_base_id', $kb->id)->count() === 0);
        if ($emptyKbs->isNotEmpty()) {
            $this->warn('• KB vuote trovate: '.$emptyKbs->pluck('name')->implode(', '));
        }

        return 0;
    }
}
