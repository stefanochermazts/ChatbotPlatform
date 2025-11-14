<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RAG\KbSearchService;
use App\Services\RAG\LinkConsistencyService;
use Illuminate\Console\Command;

class TestLinkFilter extends Command
{
    protected $signature = 'test:link-filter 
                          {tenant_id : ID del tenant}
                          {query : Query da testare}';

    protected $description = 'Testa l\'effetto del filtro link sui risultati RAG';

    public function handle(KbSearchService $kb, LinkConsistencyService $linkFilter): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $query = $this->argument('query');

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato");

            return 1;
        }

        $this->info('🔍 TEST FILTRO LINK');
        $this->info("Tenant: {$tenant->name}");
        $this->info("Query: {$query}");
        $this->newLine();

        // Esegui RAG search
        $result = $kb->retrieve($tenantId, $query, false);
        $citations = $result['citations'] ?? [];

        if (empty($citations)) {
            $this->warn('⚠️  Nessuna citazione trovata');

            return 0;
        }

        // Analizza PRIMA del filtro
        $qualityBefore = $linkFilter->analyzeLinkQuality($citations);

        // Applica filtro
        $filteredCitations = $linkFilter->filterLinksInContext($citations, $query);

        // Analizza DOPO il filtro
        $qualityAfter = $linkFilter->analyzeLinkQuality($filteredCitations);

        // Mostra risultati
        $this->displayComparison($qualityBefore, $qualityAfter);

        // Mostra esempio di contenuto filtrato
        $this->showContentExample($citations[0] ?? [], $filteredCitations[0] ?? []);

        return 0;
    }

    private function displayComparison(array $before, array $after): void
    {
        $this->info('📊 CONFRONTO QUALITÀ LINK');

        $this->table(
            ['Metrica', 'Prima', 'Dopo', 'Miglioramento'],
            [
                ['Link totali', $before['total_links'], $after['total_links'], $this->delta($before['total_links'], $after['total_links'])],
                ['Cross-domain', $before['cross_domain_links'], $after['cross_domain_links'], $this->delta($before['cross_domain_links'], $after['cross_domain_links'])],
                ['Navigazione', $before['navigation_links'], $after['navigation_links'], $this->delta($before['navigation_links'], $after['navigation_links'])],
                ['Malformati', $before['malformed_links'], $after['malformed_links'], $this->delta($before['malformed_links'], $after['malformed_links'])],
                ['Rilevanti', $before['relevant_links'], $after['relevant_links'], $this->delta($before['relevant_links'], $after['relevant_links'])],
                ['Quality Score', $before['quality_score'].'%', $after['quality_score'].'%', $this->deltaPercent($before['quality_score'], $after['quality_score'])],
            ]
        );
    }

    private function delta(int $before, int $after): string
    {
        $diff = $after - $before;
        if ($diff > 0) {
            return "+{$diff}";
        }
        if ($diff < 0) {
            return "{$diff}";
        }

        return '0';
    }

    private function deltaPercent(float $before, float $after): string
    {
        $diff = round($after - $before, 1);
        if ($diff > 0) {
            return "+{$diff}%";
        }
        if ($diff < 0) {
            return "{$diff}%";
        }

        return '0%';
    }

    private function showContentExample(array $original, array $filtered): void
    {
        $this->newLine();
        $this->info('📝 ESEMPIO CONTENUTO (prima citazione)');

        $originalContent = $original['snippet'] ?? '';
        $filteredContent = $filtered['snippet'] ?? '';

        if (! $originalContent) {
            $this->warn('Nessun contenuto da mostrare');

            return;
        }

        $this->line('┌'.str_repeat('─', 78).'┐');
        $this->line('│ '.str_pad('ORIGINALE', 76).' │');
        $this->line('└'.str_repeat('─', 78).'┘');

        $originalLines = explode("\n", wordwrap($originalContent, 76));
        foreach (array_slice($originalLines, 0, 5) as $line) {
            $this->line($line);
        }
        if (count($originalLines) > 5) {
            $this->line('[... '.(count($originalLines) - 5).' righe rimanenti]');
        }

        $this->newLine();
        $this->line('┌'.str_repeat('─', 78).'┐');
        $this->line('│ '.str_pad('FILTRATO', 76).' │');
        $this->line('└'.str_repeat('─', 78).'┘');

        $filteredLines = explode("\n", wordwrap($filteredContent, 76));
        foreach (array_slice($filteredLines, 0, 5) as $line) {
            $this->line($line);
        }
        if (count($filteredLines) > 5) {
            $this->line('[... '.(count($filteredLines) - 5).' righe rimanenti]');
        }

        // Calcola statistiche cambiamento
        $charReduction = strlen($originalContent) - strlen($filteredContent);
        $charReductionPercent = strlen($originalContent) > 0
            ? round(($charReduction / strlen($originalContent)) * 100, 1)
            : 0;

        $this->newLine();
        $this->info('📈 STATISTICHE RIDUZIONE:');
        $this->line("• Caratteri ridotti: {$charReduction} (-{$charReductionPercent}%)");
        $this->line('• Lunghezza originale: '.strlen($originalContent).' chars');
        $this->line('• Lunghezza filtrata: '.strlen($filteredContent).' chars');
    }
}
