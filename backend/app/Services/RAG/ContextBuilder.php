<?php

namespace App\Services\RAG;

use App\Models\Tenant;
use App\Services\LLM\OpenAIChatService;

class ContextBuilder
{
    public function __construct(private readonly OpenAIChatService $chat) {}

    /**
     * Prepara passaggi compressi e deduplicati rispettando un budget di caratteri.
     *
     * UNIFIED LOGIC: Matches RagTestController behavior with structured fields.
     *
     * @param  array<int, array{title?:string,url?:string,snippet?:string,chunk_text?:string,phone?:string,email?:string,address?:string,schedule?:string,document_source_url?:string}>  $citations
     * @param  int  $tenantId  Tenant ID for custom context template
     * @param  array{compression_enabled?:bool,max_chars?:int}  $options  Optional configuration overrides
     * @return array{context:string, sources: array<int, array{title:string,url:string}>}
     */
    public function build(array $citations, int $tenantId, array $options = []): array
    {
        $cfg = (array) config('rag.context');
        $enabled = (bool) ($options['compression_enabled'] ?? ($cfg['enabled'] ?? true));
        $maxChars = (int) ($options['max_chars'] ?? ($cfg['max_chars'] ?? 4000));
        $compressOver = (int) ($cfg['compress_if_over_chars'] ?? 600);
        $compressTarget = (int) ($cfg['compress_target_chars'] ?? 300);

        // Dedup su testo e URL
        $seenHash = [];
        $unique = [];
        foreach ($citations as $c) {
            $key = sha1(mb_strtolower(trim(($c['snippet'] ?? '').'|'.($c['url'] ?? ''))));
            if (isset($seenHash[$key])) {
                continue;
            }
            $seenHash[$key] = true;
            $unique[] = $c;
        }

        $parts = [];
        foreach ($unique as $c) {
            $snippet = (string) ($c['snippet'] ?? $c['chunk_text'] ?? '');

            // Compress if enabled and snippet is too long
            if ($enabled && mb_strlen($snippet) > $compressOver) {
                $snippet = $this->compressSnippet($snippet, $compressTarget);
            }

            $title = (string) ($c['title'] ?? ('Doc '.($c['id'] ?? $c['document_id'] ?? 'Unknown')));

            // ✅ ADD: Structured fields (matches RagTestController logic)
            $extra = '';
            if (! empty($c['phone'])) {
                $extra .= "\nTelefono: ".$c['phone'];
            }
            if (! empty($c['email'])) {
                $extra .= "\nEmail: ".$c['email'];
            }
            if (! empty($c['address'])) {
                $extra .= "\nIndirizzo: ".$c['address'];
            }
            if (! empty($c['schedule'])) {
                $extra .= "\nOrario: ".$c['schedule'];
            }

            // ✅ ADD: Source URL (matches RagTestController logic)
            $sourceInfo = '';
            if (! empty($c['document_source_url'])) {
                $sourceInfo = "\n[Fonte: ".$c['document_source_url'].']';
            }

            // Combine all parts
            if ($snippet !== '') {
                $parts[] = "[{$title}]\n{$snippet}{$extra}{$sourceInfo}";
            } elseif ($extra !== '') {
                // If no snippet but has structured fields, still include it
                $parts[] = "[{$title}]{$extra}{$sourceInfo}";
            }
        }

        // Budget semplice per caratteri
        $rawContext = '';
        foreach ($parts as $p) {
            if (mb_strlen($rawContext) + mb_strlen($p) + 5 > $maxChars) {
                break;
            } // +5 for separator
            $rawContext .= ($rawContext === '' ? '' : "\n\n---\n\n").$p;
        }

        // ✅ ADD: Apply tenant custom_context_template (matches RagTestController logic)
        $tenant = Tenant::find($tenantId);
        if ($tenant && ! empty($tenant->custom_context_template)) {
            $context = "\n\n".str_replace('{context}', $rawContext, $tenant->custom_context_template);
        } else {
            $context = "\n\nContesto (estratti rilevanti):\n".$rawContext;
        }

        $sources = array_map(fn ($c) => ['title' => (string) ($c['title'] ?? ''), 'url' => (string) ($c['url'] ?? $c['document_source_url'] ?? '')], $unique);

        return [
            'context' => $context,
            'sources' => $sources,
        ];
    }

    private function compressSnippet(string $text, int $targetChars): string
    {
        $model = (string) config('rag.context.model', 'gpt-4o-mini');
        $temperature = (float) config('rag.context.temperature', 0.1);
        $prompt = 'Riduci il seguente testo mantenendo informazioni fattuali e citabili; non inventare, non generalizzare. Limite circa '.$targetChars.' caratteri. Testo:\n'.$text;
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Sei un compressore di passaggi per RAG. Devi mantenere fedeltà al testo.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
        ];
        $res = $this->chat->chatCompletions($payload);
        $out = (string) ($res['choices'][0]['message']['content'] ?? '');

        return $out !== '' ? $out : mb_substr($text, 0, $targetChars);
    }
}
