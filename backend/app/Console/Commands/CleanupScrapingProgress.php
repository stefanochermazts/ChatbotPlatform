<?php

namespace App\Console\Commands;

use App\Models\ScraperProgress;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CleanupScrapingProgress extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:cleanup-progress
                          {--all : Rimuovi tutte le sessioni, anche quelle attive}
                          {--ghost : Rimuovi solo sessioni fantasma (0/0 pagine)}
                          {--old= : Rimuovi sessioni più vecchie di X ore (default 24)}
                          {--status=* : Rimuovi sessioni con status specifico (running, completed, failed, cancelled)}
                          {--tenant= : Filtra per tenant ID specifico}
                          {--dry-run : Mostra cosa verrebbe cancellato senza cancellare}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pulisce sessioni di scraping progress dal database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 CLEANUP SCRAPING PROGRESS');
        $this->newLine();

        $query = ScraperProgress::query();
        $conditions = [];

        // Filtro per tenant
        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', $tenantId);
            $conditions[] = "tenant_id = {$tenantId}";
        }

        // Filtro per status
        if ($statuses = $this->option('status')) {
            $query->whereIn('status', $statuses);
            $conditions[] = 'status in (' . implode(', ', $statuses) . ')';
        }

        // Filtro per età (ore)
        if ($hours = $this->option('old')) {
            $cutoff = Carbon::now()->subHours((int)$hours);
            $query->where('created_at', '<', $cutoff);
            $conditions[] = "più vecchie di {$hours} ore";
        } else {
            // Default: 24 ore se non specificato e non è --all
            if (!$this->option('all') && !$this->option('ghost')) {
                $cutoff = Carbon::now()->subHours(24);
                $query->where('created_at', '<', $cutoff);
                $conditions[] = "più vecchie di 24 ore (default)";
            }
        }

        // Filtro per sessioni fantasma (0/0 pagine)
        if ($this->option('ghost')) {
            $query->where('pages_found', 0)
                  ->where('pages_scraped', 0)
                  ->where('documents_created', 0);
            $conditions[] = "sessioni fantasma (0/0 pagine, 0 documenti)";
        }

        // Se --all, rimuovi tutte le sessioni (ignora altri filtri tranne tenant)
        if ($this->option('all')) {
            $query = ScraperProgress::query();
            if ($tenantId = $this->option('tenant')) {
                $query->where('tenant_id', $tenantId);
            }
            $conditions = ['TUTTE le sessioni' . ($tenantId ? " per tenant {$tenantId}" : '')];
        }

        // Conta le sessioni da rimuovere
        $count = $query->count();

        if ($count === 0) {
            $this->info('✅ Nessuna sessione trovata con i criteri specificati.');
            return;
        }

        // Mostra riepilogo
        $this->table(['Criterio', 'Valore'], array_map(fn($c) => ['Filtro', $c], $conditions));
        
        // Mostra dettagli sessioni
        $sessions = $query->with('tenant')->get();
        $this->table(
            ['ID', 'Tenant', 'Status', 'Pagine', 'Documenti', 'Creata', 'Completata'],
            $sessions->map(function ($session) {
                return [
                    $session->id,
                    $session->tenant->name ?? "ID:{$session->tenant_id}",
                    $session->status,
                    "{$session->pages_scraped}/{$session->pages_found}",
                    "C:{$session->documents_created} U:{$session->documents_updated}",
                    $session->created_at->format('d/m H:i'),
                    $session->completed_at?->format('d/m H:i') ?? '-'
                ];
            })->toArray()
        );

        if ($this->option('dry-run')) {
            $this->warn("🔍 DRY-RUN: {$count} sessioni sarebbero cancellate (nessuna azione eseguita)");
            return;
        }

        // Conferma cancellazione
        if (!$this->option('all')) {
            if (!$this->confirm("Cancellare {$count} sessioni?")) {
                $this->info('❌ Operazione annullata.');
                return;
            }
        } else {
            // Per --all richiedi conferma doppia
            if (!$this->confirm("⚠️  ATTENZIONE: Stai per cancellare TUTTE le {$count} sessioni. Sei sicuro?")) {
                $this->info('❌ Operazione annullata.');
                return;
            }
            if (!$this->confirm("🚨 ULTIMA CONFERMA: Cancellare definitivamente {$count} sessioni?")) {
                $this->info('❌ Operazione annullata.');
                return;
            }
        }

        // Cancellazione
        $this->info("🗑️  Cancellazione {$count} sessioni...");
        
        $deleted = $query->delete();
        
        $this->info("✅ {$deleted} sessioni cancellate con successo!");
        
        // Statistiche finali
        $remaining = ScraperProgress::count();
        $this->info("📊 Sessioni rimanenti nel database: {$remaining}");
    }
}