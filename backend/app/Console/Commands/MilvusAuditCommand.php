<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Tenant;
use App\Services\RAG\MilvusClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MilvusAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'milvus:audit 
                            {--tenant= : Tenant ID da verificare (lascia vuoto per tutti)}
                            {--fix : Rimuovi automaticamente documenti zombie da Milvus}
                            {--dry-run : Mostra cosa verrebbe fatto senza eseguire}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica consistency tra PostgreSQL e Milvus, identifica documenti zombie';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = $this->option('tenant');
        $fix = $this->option('fix');
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Milvus Audit Report');
        $this->newLine();

        // Determina quali tenant verificare
        $tenants = $tenantId 
            ? [Tenant::findOrFail($tenantId)]
            : Tenant::all();

        $totalZombies = 0;
        $totalSynced = 0;
        $zombiesByTenant = [];

        foreach ($tenants as $tenant) {
            $this->info("📊 Tenant {$tenant->id}: {$tenant->name}");
            
            // Conta documenti in PostgreSQL
            $pgCount = Document::where('tenant_id', $tenant->id)->count();
            $this->line("   PostgreSQL: {$pgCount} documenti");

            // Conta documenti in Milvus
            $milvus = app(MilvusClient::class);
            $milvusResult = $this->executePythonCount($tenant->id);
            $milvusCount = $milvusResult['count'] ?? 0;
            $this->line("   Milvus: {$milvusCount} chunk");

            // Ottieni tutti doc_id da PostgreSQL per questo tenant
            $pgDocIds = Document::where('tenant_id', $tenant->id)
                ->pluck('id')
                ->toArray();

            // Ottieni tutti doc_id da Milvus per questo tenant
            $milvusDocIds = $this->extractDocIdsFromMilvus($tenant->id);

            // Trova zombie (in Milvus ma non in PostgreSQL)
            $zombies = array_diff($milvusDocIds, $pgDocIds);
            $zombieCount = count($zombies);

            if ($zombieCount > 0) {
                $this->warn("   ❌ {$zombieCount} documenti zombie in Milvus!");
                $zombiesByTenant[$tenant->id] = $zombies;
                
                // Mostra primi 10 zombie
                $firstZombies = array_slice($zombies, 0, 10);
                $this->line("   Zombie doc_ids: " . implode(', ', $firstZombies));
                if ($zombieCount > 10) {
                    $this->line("   ... e altri " . ($zombieCount - 10) . " documenti");
                }

                // Fix se richiesto
                if ($fix && !$dryRun) {
                    $this->info("   🔧 Rimozione zombie...");
                    $removed = $this->removeZombieDocuments($tenant->id, $zombies);
                    $this->info("   ✅ Rimossi {$removed} chunk zombie da Milvus");
                } elseif ($dryRun) {
                    $this->comment("   [DRY-RUN] Verrebbero rimossi {$zombieCount} documenti zombie");
                }
            } else {
                $this->info("   ✅ Nessun documento zombie");
            }

            $totalZombies += $zombieCount;
            $totalSynced += ($pgCount - $zombieCount);
            $this->newLine();
        }

        // Summary
        $this->info('═══════════════════════════════════════');
        $this->info('📊 SUMMARY');
        $this->info('═══════════════════════════════════════');
        $this->line("✅ Documenti sincronizzati: {$totalSynced}");
        
        if ($totalZombies > 0) {
            $this->warn("❌ Documenti zombie totali: {$totalZombies}");
            
            if (!$fix && !$dryRun) {
                $this->newLine();
                $this->comment('💡 Suggerimento: Usa --fix per rimuovere automaticamente i zombie');
                $this->comment('   oppure --dry-run per vedere cosa verrebbe fatto');
            }
        } else {
            $this->info("✅ Nessun documento zombie trovato");
        }

        return $totalZombies > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Esegue count via Python per un tenant
     */
    private function executePythonCount(int $tenantId): array
    {
        $pythonScript = base_path('milvus_search.py');
        $params = json_encode([
            'operation' => 'count_by_tenant',
            'tenant_id' => $tenantId,
        ]);

        $output = shell_exec("python \"{$pythonScript}\" '{$params}'");
        return json_decode($output, true) ?? ['count' => 0];
    }

    /**
     * Estrae tutti i doc_id univoci da Milvus per un tenant
     * Fa una ricerca e conta le occorrenze uniche
     */
    private function extractDocIdsFromMilvus(int $tenantId): array
    {
        // Ottieni sample di risultati da Milvus usando un embedding random
        $milvus = app(MilvusClient::class);
        
        // Query tutti i documenti del tenant da PostgreSQL e controlla se esistono in Milvus
        $allDocs = Document::where('tenant_id', $tenantId)->get();
        $milvusDocIds = [];

        foreach ($allDocs as $doc) {
            // Controlla se almeno un chunk esiste in Milvus
            $result = $this->executePythonCountByDocument($tenantId, $doc->id);
            if (($result['count'] ?? 0) > 0) {
                $milvusDocIds[] = $doc->id;
            }
        }

        return $milvusDocIds;
    }

    /**
     * Count chunk per documento specifico
     */
    private function executePythonCountByDocument(int $tenantId, int $documentId): array
    {
        $pythonScript = base_path('milvus_search.py');
        $params = json_encode([
            'operation' => 'count_by_document',
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
        ]);

        $output = shell_exec("python \"{$pythonScript}\" '{$params}'");
        return json_decode($output, true) ?? ['count' => 0];
    }

    /**
     * Rimuove documenti zombie da Milvus
     */
    private function removeZombieDocuments(int $tenantId, array $zombieDocIds): int
    {
        $milvus = app(MilvusClient::class);
        $totalRemoved = 0;

        foreach ($zombieDocIds as $docId) {
            // Calcola primary IDs (max 10 chunk per documento come stima)
            $primaryIds = [];
            for ($i = 0; $i < 50; $i++) {  // Max 50 chunk per doc
                $primaryIds[] = ($docId * 100000) + $i;
            }

            $result = $milvus->deleteByPrimaryIds($primaryIds);
            if ($result['success'] ?? false) {
                $totalRemoved += ($result['deleted_count'] ?? 0);
            }
        }

        return $totalRemoved;
    }
}
