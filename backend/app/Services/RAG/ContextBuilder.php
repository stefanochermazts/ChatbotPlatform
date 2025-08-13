<?php

namespace App\Services\RAG;

use App\Services\LLM\OpenAIChatService;

class ContextBuilder
{
    public function __construct(private readonly OpenAIChatService $chat) {}

    /**
     * Prepara passaggi compressi e deduplicati rispettando un budget di caratteri.
     * @param array<int, array{title:string,url:string,snippet:string}> $citations
     * @return array{context:string, sources: array<int, array{title:string,url:string}>}
     */
    public function build(array $citations): array
    {
        $cfg = (array) config('rag.context');
        $enabled = (bool) ($cfg['enabled'] ?? true);
        $maxChars = (int) ($cfg['max_chars'] ?? 4000);
        $compressOver = (int) ($cfg['compress_if_over_chars'] ?? 600);
        $compressTarget = (int) ($cfg['compress_target_chars'] ?? 300);

        // Dedup su testo e URL
        $seenHash = [];
        $unique = [];
        foreach ($citations as $c) {
            $key = sha1(mb_strtolower(trim(($c['snippet'] ?? '').'|'.($c['url'] ?? ''))));
            if (isset($seenHash[$key])) continue;
            $seenHash[$key] = true;
            $unique[] = $c;
        }

        $parts = [];
        foreach ($unique as $c) {
            $snippet = (string) ($c['snippet'] ?? '');
            if ($enabled && mb_strlen($snippet) > $compressOver) {
                $snippet = $this->compressSnippet($snippet, $compressTarget);
            }
            $title = (string) ($c['title'] ?? '');
            $parts[] = "[{$title}]\n{$snippet}";
        }

        // Budget semplice per caratteri
        $context = '';
        foreach ($parts as $p) {
            if (mb_strlen($context) + mb_strlen($p) + 2 > $maxChars) break;
            $context .= ($context === '' ? '' : "\n\n").$p;
        }

        $sources = array_map(fn ($c) => ['title' => (string) $c['title'], 'url' => (string) $c['url']], $unique);
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




