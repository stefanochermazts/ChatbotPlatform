<?php

namespace App\Services\Scraper;

use App\Models\Document;
use App\Models\ScraperConfig;
use App\Models\Tenant;
use App\Jobs\IngestUploadedDocumentJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebScraperService
{
    private array $visitedUrls = [];
    private array $results = [];
    private array $stats = ['new' => 0, 'updated' => 0, 'skipped' => 0];

    public function scrapeForTenant(int $tenantId, ?int $scraperConfigId = null): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $config = $scraperConfigId
            ? ScraperConfig::where('tenant_id', $tenantId)->where('id', $scraperConfigId)->first()
            : ScraperConfig::where('tenant_id', $tenantId)->first();
        
        if (!$config || empty($config->seed_urls)) {
            return ['error' => 'Nessuna configurazione scraper trovata o seed URLs vuoti'];
        }

        $this->visitedUrls = [];
        $this->results = [];
        $this->stats = ['new' => 0, 'updated' => 0, 'skipped' => 0];

        // Scraping da sitemap se presente
        if (!empty($config->sitemap_urls)) {
            foreach ($config->sitemap_urls as $sitemapUrl) {
                $this->scrapeSitemap($sitemapUrl, $config, $tenant);
            }
        }

        // Scraping ricorsivo dai seed URLs
        foreach ($config->seed_urls as $seedUrl) {
            $this->scrapeRecursive($seedUrl, $config, $tenant, 0);
        }

        // Salva i risultati come documenti
        $savedCount = $this->saveResults($tenant);

        return [
            'success' => true,
            'urls_visited' => count($this->visitedUrls),
            'documents_saved' => $savedCount,
            'stats' => $this->stats,
            'results' => $this->results
        ];
    }

    private function scrapeRecursive(string $url, ScraperConfig $config, Tenant $tenant, int $depth): void
    {
        // Controlli di base
        if ($depth > $config->max_depth) return;
        if (in_array($url, $this->visitedUrls)) return;
        if (!$this->isUrlAllowed($url, $config)) return;

        $this->visitedUrls[] = $url;

        // Rate limiting
        if ($config->rate_limit_rps > 0) {
            usleep(1000000 / $config->rate_limit_rps); // Converti RPS in microsecondi
        }

        try {
            $content = $this->fetchUrl($url, $config);
            if (!$content) return;

            // Determina se questa pagina è "link-only" in base alla configurazione
            $isLinkOnly = $this->isLinkOnlyUrl($url, $config);

            if (!$isLinkOnly) {
                // Politica: se skip_known_urls attivo, e abbiamo già un documento per questo URL (e non è da recrawllare), salta
                if ($this->shouldSkipKnownUrl($url, $tenant, $config)) {
                    $this->stats['skipped']++;
                    \Log::info('Skip URL già noto', ['url' => $url]);
                } else {
                // Estrai contenuto principale
                $extractedContent = $this->extractContent($content, $url);
                if ($extractedContent) {
                    // Salva risultato
                    $this->results[] = [
                        'url' => $url,
                        'title' => $extractedContent['title'],
                        'content' => $extractedContent['content'],
                        'depth' => $depth
                    ];
                }
                }
            }

            // Estrai link per ricorsione (solo se depth < max_depth)
            if ($depth < $config->max_depth) {
                $links = $this->extractLinks($content, $url);
                foreach ($links as $link) {
                    $this->scrapeRecursive($link, $config, $tenant, $depth + 1);
                }
            }

        } catch (\Exception $e) {
            \Log::warning("Errore scraping URL: {$url}", ['error' => $e->getMessage()]);
        }
    }

    private function scrapeSitemap(string $sitemapUrl, ScraperConfig $config, Tenant $tenant): void
    {
        try {
            $response = Http::timeout(30)->get($sitemapUrl);
            if (!$response->successful()) return;

            $xml = simplexml_load_string($response->body());
            if (!$xml) return;

            foreach ($xml->url as $urlElement) {
                $pageUrl = (string) $urlElement->loc;
                if ($this->isUrlAllowed($pageUrl, $config)) {
                    $this->scrapeRecursive($pageUrl, $config, $tenant, 0);
                }
            }
        } catch (\Exception $e) {
            \Log::warning("Errore parsing sitemap: {$sitemapUrl}", ['error' => $e->getMessage()]);
        }
    }

    private function fetchUrl(string $url, ScraperConfig $config): ?string
    {
        $httpBuilder = Http::timeout(30)
            ->withUserAgent('ChatbotPlatform/1.0 (+https://example.com/bot)')
            ->withHeaders($config->auth_headers ?? []);

        $response = $httpBuilder->get($url);
        
        if (!$response->successful()) {
            return null;
        }

        return $response->body();
    }

    private function extractContent(string $html, string $url): ?array
    {
        // Parse HTML
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        
        // Estrai title
        $titleNodes = $dom->getElementsByTagName('title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : parse_url($url, PHP_URL_HOST);

        // Remove script, style, nav, footer, header
        $tagsToRemove = ['script', 'style', 'nav', 'footer', 'header', 'aside'];
        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $elements->item($i)->parentNode->removeChild($elements->item($i));
            }
        }

        // Prova a estrarre main content
        $mainContent = '';
        
        // Cerca elementi con content principale
        $contentSelectors = ['main', 'article', '[role="main"]', '.content', '#content', '.post', '.entry'];
        foreach ($contentSelectors as $selector) {
            if ($selector === 'main' || $selector === 'article') {
                $elements = $dom->getElementsByTagName($selector);
                if ($elements->length > 0) {
                    $mainContent = $this->extractTextFromNode($elements->item(0));
                    break;
                }
            }
        }

        // Fallback: usa body
        if (!$mainContent) {
            $bodyElements = $dom->getElementsByTagName('body');
            if ($bodyElements->length > 0) {
                $mainContent = $this->extractTextFromNode($bodyElements->item(0));
            }
        }

        // Cleanup content
        $mainContent = $this->cleanupContent($mainContent);
        
        if (strlen($mainContent) < 100) {
            return null; // Contenuto troppo corto
        }

        return [
            'title' => $title ?: 'Untitled',
            'content' => $mainContent
        ];
    }

    private function extractTextFromNode(\DOMNode $node): string
    {
        $text = '';
        
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->textContent . ' ';
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                // Aggiungi newline per elementi block
                $blockElements = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'br'];
                if (in_array($child->nodeName, $blockElements)) {
                    $text .= $this->extractTextFromNode($child) . "\n";
                } else {
                    $text .= $this->extractTextFromNode($child) . ' ';
                }
            }
        }
        
        return $text;
    }

    private function cleanupContent(string $content): string
    {
        // Normalizza whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = preg_replace('/\n\s*\n/', "\n\n", $content);
        
        // Rimuovi linee troppo corte o che sembrano menu/footer
        $lines = explode("\n", $content);
        $filteredLines = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) < 10) continue; // Salta linee troppo corte
            if (preg_match('/^(home|contact|about|privacy|terms|menu|search|login|register|©|\d{4})$/i', $line)) continue;
            $filteredLines[] = $line;
        }
        
        return trim(implode("\n", $filteredLines));
    }

    private function extractLinks(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR);
        
        $links = [];
        $aElements = $dom->getElementsByTagName('a');
        
        foreach ($aElements as $a) {
            $href = $a->getAttribute('href');
            if (!$href) continue;
            
            // Converti link relativi in assoluti
            $absoluteUrl = $this->resolveUrl($href, $baseUrl);
            if ($absoluteUrl && filter_var($absoluteUrl, FILTER_VALIDATE_URL)) {
                $links[] = $absoluteUrl;
            }
        }
        
        return array_unique($links);
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url; // Already absolute
        }
        
        $baseParts = parse_url($baseUrl);
        if (!$baseParts) return null;
        
        if ($url[0] === '/') {
            // Absolute path
            return $baseParts['scheme'] . '://' . $baseParts['host'] . $url;
        } else {
            // Relative path
            $basePath = dirname($baseParts['path'] ?? '/');
            return $baseParts['scheme'] . '://' . $baseParts['host'] . $basePath . '/' . $url;
        }
    }

    private function isUrlAllowed(string $url, ScraperConfig $config): bool
    {
        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['host'])) return false;
        
        // Check allowed domains
        if (!empty($config->allowed_domains)) {
            $allowed = false;
            foreach ($config->allowed_domains as $domain) {
                if (str_contains($parsedUrl['host'], $domain)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) return false;
        }

        // Check include patterns
        if (!empty($config->include_patterns)) {
            $included = false;
            foreach ($config->include_patterns as $pattern) {
                $regex = $this->compileUserRegex($pattern);
                if ($regex !== null && @preg_match($regex, $url)) {
                    $included = true;
                    break;
                }
            }
            if (!$included) return false;
        }

        // Check exclude patterns
        if (!empty($config->exclude_patterns)) {
            foreach ($config->exclude_patterns as $pattern) {
                $regex = $this->compileUserRegex($pattern);
                if ($regex !== null && @preg_match($regex, $url)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function isLinkOnlyUrl(string $url, ScraperConfig $config): bool
    {
        if (empty($config->link_only_patterns)) {
            return false;
        }
        foreach ($config->link_only_patterns as $pattern) {
            $regex = $this->compileUserRegex($pattern);
            if ($regex !== null && @preg_match($regex, $url)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compila un pattern inserito dall'utente in una regex sicura per preg_match.
     * - Se il pattern ha già delimitatori validi (/, #, ~) lo usa così com'è
     * - Altrimenti lo racchiude tra delimitatori ~ con flag i (case-insensitive)
     * - Ritorna null se il pattern è vuoto o non valido
     */
    private function compileUserRegex(?string $pattern): ?string
    {
        $p = trim((string) $pattern);
        if ($p === '') {
            return null;
        }

        // Se sembra già una regex con delimitatori e (eventuali) flag, usala
        // Esempi validi: /foo/i, #bar#, ~baz~ims
        if (preg_match('/^([#~\/]).+\1[imsxuADSUXJ]*$/', $p)) {
            return $p;
        }

        // Altrimenti incapsula con ~ per non confliggere con gli slash
        return '~' . $p . '~i';
    }

    private function shouldSkipKnownUrl(string $url, Tenant $tenant, ScraperConfig $config): bool
    {
        if (!($config->skip_known_urls ?? false)) {
            return false;
        }
        $q = Document::query()->where('tenant_id', $tenant->id)->where('source_url', $url);
        if ($config->recrawl_days && $config->recrawl_days > 0) {
            $threshold = now()->subDays((int) $config->recrawl_days);
            // se ultimo scraping è più vecchio del threshold, non skippare
            $q->where('last_scraped_at', '>=', $threshold);
        }
        return $q->exists();
    }

    private function saveResults(Tenant $tenant): int
    {
        $savedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        
        foreach ($this->results as $result) {
            try {
                // Crea contenuto Markdown
                $markdownContent = "# {$result['title']}\n\n";
                $markdownContent .= "**URL:** {$result['url']}\n\n";
                $markdownContent .= "**Scraped on:** " . now()->format('Y-m-d H:i:s') . "\n\n";
                $markdownContent .= "---\n\n";
                $markdownContent .= $result['content'];

                // Calcola hash del contenuto (solo del contenuto estratto, non delle metadati)
                $contentHash = hash('sha256', $result['content']);
                
                // Cerca documento esistente per lo stesso URL
                $existingDocument = Document::where('tenant_id', $tenant->id)
                    ->where('source_url', $result['url'])
                    ->first();

                if ($existingDocument) {
                    // Controlla se il contenuto è cambiato
                    if ($existingDocument->content_hash === $contentHash) {
                        // Contenuto identico - aggiorna solo timestamp e, se configurata, la KB target
                        $targetKbId = null;
                        try {
                            $cfg = ScraperConfig::where('tenant_id', $tenant->id)->first();
                            $targetKbId = $cfg?->target_knowledge_base_id;
                        } catch (\Throwable) {
                            $targetKbId = null;
                        }
                        $updateData = [ 'last_scraped_at' => now() ];
                        if ($targetKbId && $existingDocument->knowledge_base_id !== (int) $targetKbId) {
                            $updateData['knowledge_base_id'] = (int) $targetKbId;
                        }
                        $existingDocument->update($updateData);
                        $skippedCount++;
                        $this->stats['skipped']++;
                        \Log::info("Documento invariato, skip", ['url' => $result['url']]);
                        continue;
                    } else {
                        // Contenuto cambiato - aggiorna documento esistente
                        $this->updateExistingDocument($existingDocument, $result, $markdownContent, $contentHash);
                        $updatedCount++;
                        $this->stats['updated']++;
                        continue;
                    }
                }

                // Nuovo documento - crea ex-novo
                $document = $this->createNewDocument($tenant, $result, $markdownContent, $contentHash);
                $savedCount++;
                $this->stats['new']++;
                
            } catch (\Exception $e) {
                \Log::error("Errore salvataggio documento scraped", [
                    'url' => $result['url'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        \Log::info("Scraping completato", [
            'tenant_id' => $tenant->id,
            'new_documents' => $savedCount,
            'updated_documents' => $updatedCount,
            'skipped_documents' => $skippedCount
        ]);
        
        return $savedCount + $updatedCount;
    }

    private function createNewDocument(Tenant $tenant, array $result, string $markdownContent, string $contentHash): Document
    {
        // Genera nome file unico
        $baseFilename = Str::slug($result['title']);
        $filename = $baseFilename . '-v1.md';
        $path = "scraped/{$tenant->id}/" . $filename;

        // Assicurati che il nome file sia unico
        $counter = 1;
        while (Storage::disk('public')->exists($path)) {
            $counter++;
            $filename = $baseFilename . "-v{$counter}.md";
            $path = "scraped/{$tenant->id}/" . $filename;
        }

        // Salva file
        Storage::disk('public')->put($path, $markdownContent);

        // Crea record documento
        // Associa alla KB target da config se presente, altrimenti KB di default
        $config = ScraperConfig::where('tenant_id', $tenant->id)->first();
        $targetKbId = $config->target_knowledge_base_id ?? null;
        if (!$targetKbId) {
            $targetKbId = \App\Models\KnowledgeBase::where('tenant_id', $tenant->id)->where('is_default', true)->value('id');
        }

        $document = Document::create([
            'tenant_id' => $tenant->id,
            'knowledge_base_id' => $targetKbId,
            'title' => $result['title'] . ' (Scraped)',
            'path' => $path,
            'source_url' => $result['url'],
            'content_hash' => $contentHash,
            'last_scraped_at' => now(),
            'scrape_version' => 1,
            'size' => strlen($markdownContent),
            'ingestion_status' => 'pending',
            'source' => 'web_scraper'
        ]);

        // Avvia job di ingestion
        IngestUploadedDocumentJob::dispatch($document->id);
        
        \Log::info("Nuovo documento creato", [
            'url' => $result['url'],
            'document_id' => $document->id,
            'hash' => substr($contentHash, 0, 8)
        ]);

        return $document;
    }

    private function updateExistingDocument(Document $existingDocument, array $result, string $markdownContent, string $contentHash): void
    {
        // Incrementa versione
        $newVersion = $existingDocument->scrape_version + 1;
        
        // Genera nuovo nome file con versione
        $baseFilename = Str::slug($result['title']);
        $filename = $baseFilename . "-v{$newVersion}.md";
        $newPath = "scraped/{$existingDocument->tenant_id}/" . $filename;

        // Salva nuova versione del file
        Storage::disk('public')->put($newPath, $markdownContent);

        // Opzionale: Rimuovi file vecchio per risparmiare spazio
        if ($existingDocument->path && Storage::disk('public')->exists($existingDocument->path)) {
            Storage::disk('public')->delete($existingDocument->path);
        }

        // Se configurata una KB target nello scraper, aggiorna anche la KB del documento
        $config = ScraperConfig::where('tenant_id', $existingDocument->tenant_id)->first();
        $targetKbId = $config->target_knowledge_base_id ?? null;

        // Aggiorna record documento
        $existingDocument->update([
            'title' => $result['title'] . ' (Scraped)',
            'path' => $newPath,
            'content_hash' => $contentHash,
            'last_scraped_at' => now(),
            'scrape_version' => $newVersion,
            'size' => strlen($markdownContent),
            'ingestion_status' => 'pending',
            'knowledge_base_id' => $targetKbId ?: $existingDocument->knowledge_base_id,
        ]);

        // Avvia re-ingestion
        IngestUploadedDocumentJob::dispatch($existingDocument->id);
        
        \Log::info("Documento aggiornato", [
            'url' => $result['url'],
            'document_id' => $existingDocument->id,
            'old_hash' => substr($existingDocument->getOriginal('content_hash') ?? '', 0, 8),
            'new_hash' => substr($contentHash, 0, 8),
            'version' => $newVersion
        ]);
    }
}
