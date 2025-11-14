<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RAG\KbSearchService;
use Illuminate\Console\Command;

class AnalyzeLinkConsistency extends Command
{
    protected $signature = 'rag:analyze-links 
                          {tenant_id : ID del tenant da analizzare}
                          {query : Query per testare la coerenza}
                          {--show-chunks : Mostra il contenuto completo dei chunk}
                          {--max-results=5 : Numero massimo di risultati da analizzare}';

    protected $description = 'Analizza la coerenza tra link estratti dai chunk e URL fonte dei documenti';

    public function handle(KbSearchService $kb): int
    {
        $tenantId = (int) $this->argument('tenant_id');
        $query = $this->argument('query');
        $showChunks = $this->option('show-chunks');
        $maxResults = (int) $this->option('max-results');

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("❌ Tenant {$tenantId} non trovato");

            return 1;
        }

        $this->info('🔍 ANALISI COERENZA LINK');
        $this->info("Tenant: {$tenant->name} (ID: {$tenantId})");
        $this->info("Query: {$query}");
        $this->newLine();

        // Esegui ricerca RAG
        $result = $kb->retrieve($tenantId, $query, false);
        $citations = $result['citations'] ?? [];

        if (empty($citations)) {
            $this->warn('⚠️  Nessuna citazione trovata per questa query');

            return 0;
        }

        $this->info('📊 Trovate '.count($citations)." citazioni. Analizzando prime {$maxResults}...");
        $this->newLine();

        $citations = array_slice($citations, 0, $maxResults);
        $inconsistencies = [];

        foreach ($citations as $i => $citation) {
            $this->line('┌'.str_repeat('─', 78).'┐');
            $this->line('│ '.str_pad('CITAZIONE '.($i + 1), 76).' │');
            $this->line('└'.str_repeat('─', 78).'┘');

            $title = $citation['title'] ?? 'N/A';
            $docSourceUrl = $citation['document_source_url'] ?? null;
            $snippet = $citation['snippet'] ?? $citation['chunk_text'] ?? '';
            $score = round($citation['score'] ?? 0, 3);

            $this->info("📄 Documento: {$title}");
            $this->info('🔗 URL Fonte: '.($docSourceUrl ?: 'N/A'));
            $this->info("⭐ Score: {$score}");
            $this->newLine();

            // Estrai tutti i link dal chunk
            $linksInChunk = $this->extractLinksFromContent($snippet);

            if (! empty($linksInChunk)) {
                $this->info('🔗 Link trovati nel chunk ('.count($linksInChunk).'):');
                foreach ($linksInChunk as $j => $link) {
                    $this->line('  '.($j + 1).". {$link['text']} → {$link['url']}");
                }
            } else {
                $this->line('  (Nessun link trovato)');
            }
            $this->newLine();

            // Analizza coerenza
            $analysis = $this->analyzeConsistency($docSourceUrl, $linksInChunk, $title, $snippet);

            if ($analysis['has_issues']) {
                $inconsistencies[] = [
                    'citation_index' => $i + 1,
                    'title' => $title,
                    'doc_source_url' => $docSourceUrl,
                    'issues' => $analysis['issues'],
                    'links_in_chunk' => $linksInChunk,
                ];
            }

            $this->displayAnalysis($analysis);

            if ($showChunks) {
                $this->info('📝 Contenuto chunk:');
                $this->line('┌'.str_repeat('─', 78).'┐');
                $lines = explode("\n", $snippet);
                foreach (array_slice($lines, 0, 10) as $line) {
                    $this->line('│ '.str_pad(substr($line, 0, 76), 76).' │');
                }
                if (count($lines) > 10) {
                    $this->line('│ '.str_pad('[... '.(count($lines) - 10).' righe rimanenti]', 76).' │');
                }
                $this->line('└'.str_repeat('─', 78).'┘');
            }

            $this->newLine();
        }

        // Summary finale
        $this->displaySummary($inconsistencies);

        return 0;
    }

    private function extractLinksFromContent(string $content): array
    {
        $links = [];

        // Pattern per link markdown [text](url)
        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $content, $markdownMatches, PREG_SET_ORDER);
        foreach ($markdownMatches as $match) {
            $links[] = [
                'type' => 'markdown',
                'text' => $match[1],
                'url' => $match[2],
                'full_match' => $match[0],
            ];
        }

        // Pattern per URL diretti
        preg_match_all('/(https?:\/\/[^\s\)]+)/', $content, $urlMatches, PREG_SET_ORDER);
        foreach ($urlMatches as $match) {
            // Evita duplicati (URL già catturati nei markdown)
            $isDuplicate = false;
            foreach ($links as $existingLink) {
                if ($existingLink['url'] === $match[1]) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (! $isDuplicate) {
                $links[] = [
                    'type' => 'direct_url',
                    'text' => $match[1],
                    'url' => $match[1],
                    'full_match' => $match[0],
                ];
            }
        }

        return $links;
    }

    private function analyzeConsistency(?string $docSourceUrl, array $linksInChunk, string $title, string $content): array
    {
        $issues = [];
        $hasIssues = false;

        // 1. Verifica se il documento ha un URL fonte
        if (! $docSourceUrl) {
            $issues[] = '⚠️  Documento senza URL fonte';
            $hasIssues = true;
        }

        // 2. Verifica coerenza dominio tra URL fonte e link nel chunk
        if ($docSourceUrl) {
            $docDomain = parse_url($docSourceUrl, PHP_URL_HOST);

            foreach ($linksInChunk as $link) {
                $linkDomain = parse_url($link['url'], PHP_URL_HOST);

                if ($linkDomain && $docDomain && $linkDomain !== $docDomain) {
                    $issues[] = "🌐 Link cross-domain: {$link['text']} → {$linkDomain} (doc: {$docDomain})";
                    $hasIssues = true;
                }
            }
        }

        // 3. Verifica link che potrebbero essere irrilevanti per il contenuto
        $contentLower = strtolower($content);
        foreach ($linksInChunk as $link) {
            $linkTextLower = strtolower($link['text']);

            // Check se il testo del link ha relazione con il contenuto
            $relevantKeywords = ['comune', 'cesareo', 'riconoscimento', 'figlio', 'stato civile', 'anagrafe'];
            $hasRelevantKeyword = false;

            foreach ($relevantKeywords as $keyword) {
                if (str_contains($linkTextLower, $keyword) || str_contains($contentLower, $keyword)) {
                    $hasRelevantKeyword = true;
                    break;
                }
            }

            // Segnala link potenzialmente irrilevanti
            if (! $hasRelevantKeyword && strlen($link['text']) > 5) {
                $issues[] = "❓ Link potenzialmente irrilevante: {$link['text']}";
            }
        }

        // 4. Verifica link malformati o troncati
        foreach ($linksInChunk as $link) {
            if (! filter_var($link['url'], FILTER_VALIDATE_URL)) {
                $issues[] = "💥 URL malformato: {$link['url']}";
                $hasIssues = true;
            }

            if (str_contains($link['url'], '...') || str_ends_with($link['url'], '.')) {
                $issues[] = "✂️  URL potenzialmente troncato: {$link['url']}";
                $hasIssues = true;
            }
        }

        return [
            'has_issues' => $hasIssues,
            'issues' => $issues,
            'total_links' => count($linksInChunk),
            'cross_domain_count' => $this->countCrossDomainLinks($docSourceUrl, $linksInChunk),
        ];
    }

    private function countCrossDomainLinks(?string $docSourceUrl, array $linksInChunk): int
    {
        if (! $docSourceUrl) {
            return 0;
        }

        $docDomain = parse_url($docSourceUrl, PHP_URL_HOST);
        $count = 0;

        foreach ($linksInChunk as $link) {
            $linkDomain = parse_url($link['url'], PHP_URL_HOST);
            if ($linkDomain && $docDomain && $linkDomain !== $docDomain) {
                $count++;
            }
        }

        return $count;
    }

    private function displayAnalysis(array $analysis): void
    {
        if (! $analysis['has_issues']) {
            $this->info('✅ Nessun problema di coerenza rilevato');

            return;
        }

        $this->error('❌ Problemi rilevati:');
        foreach ($analysis['issues'] as $issue) {
            $this->line("  • {$issue}");
        }
    }

    private function displaySummary(array $inconsistencies): void
    {
        $this->line('┌'.str_repeat('─', 78).'┐');
        $this->line('│ '.str_pad('RIASSUNTO ANALISI', 76).' │');
        $this->line('└'.str_repeat('─', 78).'┘');

        if (empty($inconsistencies)) {
            $this->info('✅ Nessuna incoerenza critica rilevata!');

            return;
        }

        $this->error('❌ Trovate '.count($inconsistencies).' citazioni con problemi:');
        $this->newLine();

        foreach ($inconsistencies as $inconsistency) {
            $this->line("🔍 Citazione {$inconsistency['citation_index']}: {$inconsistency['title']}");
            foreach ($inconsistency['issues'] as $issue) {
                $this->line("  • {$issue}");
            }
            $this->newLine();
        }

        // Raccomandazioni
        $this->info('💡 RACCOMANDAZIONI:');
        $this->line('1. Verifica che gli URL fonte dei documenti siano corretti');
        $this->line('2. Considera di filtrare link cross-domain durante lo scraping');
        $this->line('3. Implementa validazione URL durante il chunking');
        $this->line('4. Aggiungi metadata per identificare link rilevanti vs navigazione');
    }
}
