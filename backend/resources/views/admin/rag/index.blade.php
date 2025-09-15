@extends('admin.layout')

@section('content')
<style>
/* 🎯 CSS per migliorare la leggibilità del RAG Tester */
.rag-tester-pre {
  white-space: pre-wrap !important;      /* Permette word wrap mantenendo formattazione */
  word-wrap: break-word !important;      /* Spezza parole lunghe */
  overflow-wrap: break-word !important;  /* Fallback per browser più vecchi */
  word-break: break-word !important;     /* Spezza anche URL lunghi */
  max-width: 100% !important;           /* Non eccede mai il container */
}

.rag-tester-textarea {
  white-space: pre-wrap !important;
  word-wrap: break-word !important;
  overflow-wrap: break-word !important;
  resize: vertical !important;           /* Solo resize verticale */
}

.rag-json-output {
  font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace !important;
  line-height: 1.4 !important;
  tab-size: 2 !important;
}
</style>
<h1 class="text-xl font-semibold mb-4">RAG Tester</h1>
<form method="post" action="{{ route('admin.rag.run') }}" class="bg-white border rounded p-4 grid gap-3">
  @csrf
  <div class="grid md:grid-cols-3 gap-3">
    <label class="block">
      <span class="text-sm">Cliente</span>
      <select name="tenant_id" class="w-full border rounded px-3 py-2" required>
        <option value="">Seleziona...</option>
        @foreach($tenants as $t)
          <option value="{{ $t->id }}" @selected(($tenant_id ?? null)===$t->id)>{{ $t->name }} ({{ $t->id }})</option>
        @endforeach
      </select>
    </label>
    <label class="block">
      <span class="text-sm">Max output tokens</span>
      <input type="number" name="max_output_tokens" min="32" max="8192" value="{{ old('max_output_tokens', config('openai.max_output_tokens', 700)) }}" class="w-full border rounded px-3 py-2" />
    </label>
  </div>
  <label class="block">
    <span class="text-sm">Query</span>
    <textarea name="query" rows="3" class="w-full border rounded px-3 py-2 rag-tester-textarea" required>{{ $query ?? '' }}</textarea>
  </label>
  <div class="grid md:grid-cols-2 gap-3">
    <label class="inline-flex items-center gap-2">
      <input type="checkbox" name="with_answer" value="1" /> Genera risposta con LLM
    </label>
    <label class="inline-flex items-center gap-2">
      <input type="checkbox" name="enable_hyde" value="1" @checked(old('enable_hyde', request('enable_hyde'))) /> 🔬 Abilita HyDE (Hypothetical Document Embeddings)
    </label>
  </div>
  
  <div class="grid md:grid-cols-2 gap-3">
    <label class="inline-flex items-center gap-2">
      <input type="checkbox" name="enable_conversation" value="1" @checked(old('enable_conversation', request('enable_conversation'))) onchange="toggleConversationMessages(this.checked)" /> 💬 Abilita Contesto Conversazionale
    </label>
    <div class="text-xs text-gray-600 self-end pb-2">
      🧠 Usa la chat history per query context-aware (richiede messaggi precedenti)
    </div>
  </div>
  
  <div id="conversation-messages" class="hidden">
    <label class="block">
      <span class="text-sm">💬 Messaggi Conversazione (JSON)</span>
      <textarea name="conversation_messages" rows="4" class="w-full border rounded px-3 py-2 font-mono text-xs rag-tester-textarea rag-json-output" placeholder='[{"role": "user", "content": "Che orari ha la biblioteca?"}, {"role": "assistant", "content": "La biblioteca è aperta..."}, {"role": "user", "content": "E quanto costa il prestito?"}]'>{{ old('conversation_messages', request('conversation_messages')) }}</textarea>
    </label>
    <div class="text-xs text-gray-600 mt-1">
      📝 Inserisci una conversazione in formato JSON per testare il context enhancement
    </div>
  </div>
  <div class="grid md:grid-cols-2 gap-3">
    <label class="block">
      <span class="text-sm">🎯 Reranker Strategy</span>
      <select name="reranker_driver" class="w-full border rounded px-3 py-2">
        <option value="embedding" @selected(old('reranker_driver', request('reranker_driver', 'embedding')) === 'embedding')>Embedding Similarity (Default)</option>
        <option value="llm" @selected(old('reranker_driver', request('reranker_driver')) === 'llm')>🤖 LLM Reranker usa AI per valutare rilevanza (più accurato ma +costo)</option>
        <option value="cohere" @selected(old('reranker_driver', request('reranker_driver')) === 'cohere')>Cohere Rerank API</option>
      </select>
    </label>
    <div class="text-xs text-gray-600 self-end pb-2">
      📊 LLM Reranker usa AI per valutare rilevanza (più accurato ma +costo)
    </div>
  </div>
  <div>
    <button class="px-3 py-2 bg-indigo-600 text-white rounded">Esegui</button>
  </div>
</form>

@if(isset($result))
  <div class="mt-6 grid gap-4">
    @if(!empty($result['trace']))
    <div class="bg-yellow-50 border border-yellow-200 rounded p-3 text-xs">
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 font-semibold">LLM DEBUG</span>
        <div>Modello: <span class="font-mono">{{ config('openai.chat_model') }}</span></div>
        @if(!empty($result['trace']['rag_config']))
          <div class="ml-4">Reranker: <span class="font-mono">{{ $result['trace']['rag_config']['reranker_driver'] ?? 'n/a' }}</span></div>
        @endif
      </div>
      @if(!empty($result['trace']['llm_messages']))
      <div class="mt-2">
        <div class="font-medium mb-1">Payload Chat (messages):</div>
        <pre class="bg-white border rounded p-2 max-h-48 overflow-auto rag-tester-pre rag-json-output">{{ json_encode($result['trace']['llm_messages'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      @endif
      @if(!empty($result['trace']['hybrid_config']))
      <div class="mt-2">
        <div class="font-medium mb-1">Hybrid params (effettivi)</div>
        <pre class="bg-white border rounded p-2 rag-tester-pre rag-json-output">{{ json_encode($result['trace']['hybrid_config'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      @endif
      @if(!empty($result['answer']))
      <div class="mt-2">
        <div class="font-medium mb-1">Answer preview:</div>
        <pre class="bg-white border rounded p-2 max-h-40 overflow-auto rag-tester-pre">{{ $result['answer'] }}</pre>
      </div>
      @endif
      @if(!empty($result['trace']['llm_raw_response']))
      <div class="mt-2">
        <div class="font-medium mb-1">Raw response (debug):</div>
        <pre class="bg-white border rounded p-2 max-h-64 overflow-auto rag-tester-pre rag-json-output">{{ json_encode($result['trace']['llm_raw_response'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
      </div>
      @endif
    </div>
    @endif
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Citazioni & Snippet</h2>
      @if(isset($result['trace']['selected_kb']['kb_name']))
        <div class="text-xs mb-2">KB selezionata: <span class="font-semibold">{{ $result['trace']['selected_kb']['kb_name'] }}</span></div>
      @endif
      @if(isset($result['confidence']))
        <div class="text-sm mb-2">Confidence: <span class="font-semibold">{{ number_format($result['confidence']*100,1) }}%</span></div>
      @endif
      @if(isset($result['health']))
        <div class="text-xs mb-2">
          Milvus: 
          @if(($result['health']['ok'] ?? false))
            <span class="text-emerald-700">OK</span>
          @else
            <span class="text-rose-700">KO</span>
            <span class="text-rose-700">{{ $result['health']['error'] ?? '' }}</span>
          @endif
        </div>
      @endif
      @if(empty($result['citations']))
        <p class="text-sm text-gray-600">Nessuna citazione trovata.</p>
      @else
        <ol class="list-decimal list-inside text-sm grid gap-3">
          @foreach($result['citations'] as $c)
            <li>
              <div class="mb-1">
                <a class="text-blue-600 underline" href="{{ $c['url'] }}" target="_blank">{{ $c['title'] ?? ('Doc '.$c['id']) }}</a>
              </div>
              @if(!empty($c['snippet']))
                <div class="space-y-2">
                  <div>
                    <div class="text-xs font-medium text-gray-600 mb-1">📝 Snippet (con chunk vicini):</div>
                    <blockquote class="p-2 bg-gray-50 border rounded text-sm rag-tester-pre">{{ $c['snippet'] }}</blockquote>
                    @php
                      // Cerca telefoni nel snippet
                      preg_match_all('/(?:tel[\.:]*\s*)?(?:\+39\s*)?0\d{1,3}[\s\.\-]*\d{6,8}/i', $c['snippet'], $phoneMatches);
                      $phones = array_unique($phoneMatches[0]);
                    @endphp
                    @if(!empty($phones))
                      <div class="mt-1 text-xs bg-green-50 border border-green-200 rounded p-2">
                        📞 <strong>Telefoni trovati:</strong> {{ implode(', ', $phones) }}
                      </div>
                    @endif
                  </div>
                  @if(!empty($c['chunk_text']) && $c['chunk_text'] !== $c['snippet'])
                    <div>
                      <div class="text-xs font-medium text-blue-600 mb-1">📄 Chunk originale (singolo):</div>
                      <blockquote class="p-3 bg-blue-50 border border-blue-200 rounded text-sm max-h-60 overflow-y-auto rag-tester-pre">{{ $c['chunk_text'] }}</blockquote>
                    </div>
                  @endif
                </div>
              @endif
              @if(!empty($c['phone']) || (!empty($c['phones']) && is_array($c['phones'])))
                <div class="mt-1 text-xs bg-green-50 border border-green-200 rounded p-2">
                  📞 <strong>Telefoni trovati:</strong>
                  @php $allPhones = []; if(!empty($c['phone'])) { $allPhones[] = $c['phone']; } if(!empty($c['phones']) && is_array($c['phones'])) { $allPhones = array_merge($allPhones, $c['phones']); }
                  $allPhones = array_values(array_unique(array_map('trim', $allPhones))); @endphp
                  {{ implode(', ', $allPhones) }}
                </div>
              @endif
              @if(!empty($c['email']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-800 rounded">📧 Email: {{ $c['email'] }}</span></div>
              @endif
              @if(!empty($c['address']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-purple-100 text-purple-800 rounded">📍 Indirizzo: {{ $c['address'] }}</span></div>
              @endif
              @if(!empty($c['schedule']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-orange-100 text-orange-800 rounded">🕐 Orario: {{ $c['schedule'] }}</span></div>
              @endif
            </li>
          @endforeach
        </ol>
      @endif
    </div>
    @if(($result['answer'] ?? null) !== null)
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Risposta</h2>
      @if(isset($result['trace']['selected_kb']['kb_name']))
        <div class="text-xs mb-2">KB selezionata: <span class="font-semibold">{{ $result['trace']['selected_kb']['kb_name'] }}</span></div>
      @endif
      <pre class="whitespace-pre-wrap text-sm">{{ $result['answer'] }}

Fonti:
@foreach(($result['citations'] ?? []) as $c)- {{ $c['title'] ?? ('Doc '.$c['id']) }} ({{ $c['url'] }})
@endforeach</pre>
    </div>
    @endif

    @if(isset($result['trace']))
    <div class="bg-white border rounded p-4">
      <h2 class="font-semibold mb-2">Debug pipeline</h2>
      <details open>
        <summary class="cursor-pointer text-sm">Dettagli</summary>
        <div class="mt-2 grid md:grid-cols-2 gap-4 text-xs">
          @if(!empty($result['trace']['intent_detection']))
          <div class="md:col-span-2 mb-4">
            <h3 class="font-medium text-base text-indigo-700">🎯 Intent Detection</h3>
            <div class="bg-indigo-50 border border-indigo-200 rounded p-3 mt-1">
              <div class="grid md:grid-cols-2 gap-3">
                <div>
                  <h4 class="font-medium text-sm">Query Processing</h4>
                  <div class="text-xs mt-1">
                    <div><strong>Original:</strong> {{ $result['trace']['intent_detection']['original_query'] }}</div>
                    <div><strong>Lowercased:</strong> {{ $result['trace']['intent_detection']['lowercased_query'] }}</div>
                    <div><strong>Expanded:</strong> {{ $result['trace']['intent_detection']['expanded_query'] }}</div>
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Intent Results</h4>
                  <div class="text-xs mt-1">
                    <div><strong>Detected:</strong> {{ implode(' > ', $result['trace']['intent_detection']['intents_detected']) }}</div>
                    <div><strong>Executed:</strong> 
                      @if(str_contains($result['trace']['intent_detection']['executed_intent'], '_semantic'))
                        <span class="px-1 py-0.5 bg-purple-100 text-purple-700 rounded">{{ $result['trace']['intent_detection']['executed_intent'] }}</span>
                        <span class="text-xs text-purple-600 ml-1">(semantic)</span>
                      @else
                        <span class="px-1 py-0.5 bg-indigo-100 rounded">{{ $result['trace']['intent_detection']['executed_intent'] }}</span>
                      @endif
                    </div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <h4 class="font-medium text-sm">Intent Scores</h4>
                <div class="grid grid-cols-4 gap-2 mt-1 text-xs">
                  @foreach($result['trace']['intent_detection']['intent_scores'] as $intent => $score)
                  <div class="bg-white border rounded p-2">
                    <div class="font-medium">{{ ucfirst($intent) }}</div>
                    <div class="text-lg font-bold {{ $score > 0 ? 'text-green-600' : 'text-gray-400' }}">{{ number_format($score, 3) }}</div>
                  </div>
                  @endforeach
                </div>
              </div>
              <div class="mt-3">
                <h4 class="font-medium text-sm">Keywords Matched</h4>
                <div class="grid grid-cols-4 gap-2 mt-1 text-xs">
                  @foreach($result['trace']['intent_detection']['keywords_matched'] as $intent => $keywords)
                  <div class="bg-white border rounded p-2">
                    <div class="font-medium">{{ ucfirst($intent) }}</div>
                    @if(empty($keywords))
                      <div class="text-gray-400">None</div>
                    @else
                      @foreach($keywords as $kw)
                        <div class="text-xs text-gray-600">{{ $kw }}</div>
                      @endforeach
                    @endif
                  </div>
                  @endforeach
                </div>
              </div>
            </div>
          </div>
          @endif

          @if(!empty($result['trace']['semantic_fallback']))
          <div class="md:col-span-2 mb-4">
            <h3 class="font-medium text-base text-purple-700">🔍 Semantic Fallback</h3>
            <div class="bg-purple-50 border border-purple-200 rounded p-3 mt-1">
              <div class="grid md:grid-cols-2 gap-3 text-xs">
                <div>
                  <h4 class="font-medium text-sm">Original Search</h4>
                  <div class="mt-1">
                    <div><strong>Name:</strong> {{ $result['trace']['semantic_fallback']['original_name'] ?? 'N/A' }}</div>
                    <div><strong>Intent:</strong> {{ $result['trace']['semantic_fallback']['intent_type'] ?? 'N/A' }}</div>
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Semantic Query</h4>
                  <div class="bg-white border rounded p-2 mt-1 font-mono text-xs">
                    {{ $result['trace']['semantic_fallback']['semantic_query'] ?? 'N/A' }}
                  </div>
                </div>
              </div>
              
              @if(isset($result['trace']['semantic_fallback']['semantic_results_found']))
              <div class="mt-3 grid md:grid-cols-3 gap-3 text-xs">
                <div>
                  <h4 class="font-medium text-sm">Results Found</h4>
                  <div class="text-lg font-bold {{ ($result['trace']['semantic_fallback']['semantic_results_found'] ?? 0) > 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $result['trace']['semantic_fallback']['semantic_results_found'] ?? 0 }}
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Filtered Results</h4>
                  <div class="text-lg font-bold {{ ($result['trace']['semantic_fallback']['filtered_results_count'] ?? 0) > 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $result['trace']['semantic_fallback']['filtered_results_count'] ?? 0 }}
                  </div>
                </div>
                <div>
                  <h4 class="font-medium text-sm">Status</h4>
                  @if(isset($result['trace']['semantic_fallback']['failure_reason']))
                    <div class="text-red-600 font-medium">{{ $result['trace']['semantic_fallback']['failure_reason'] }}</div>
                  @else
                    <div class="text-green-600 font-medium">Success</div>
                  @endif
                </div>
              </div>
              
              @if(!empty($result['trace']['semantic_fallback']['top_semantic_hits']))
              <div class="mt-3">
                <h4 class="font-medium text-sm">Top Semantic Hits</h4>
                <div class="bg-white border rounded p-2 mt-1 max-h-32 overflow-auto">
                  @foreach($result['trace']['semantic_fallback']['top_semantic_hits'] as $hit)
                  <div class="text-xs border-b pb-1 mb-1">
                    Doc {{ $hit['document_id'] }}, Chunk {{ $hit['chunk_index'] }}, Score: {{ number_format($hit['score'], 4) }}
                  </div>
                  @endforeach
                </div>
              </div>
              @endif
              
              @if(!empty($result['trace']['semantic_fallback']['citation_debug']))
              <div class="mt-3">
                <h3 class="font-medium text-sm">Citation Debug</h3>
                <div class="bg-white border rounded p-2 mt-1 max-h-40 overflow-auto">
                  @foreach($result['trace']['semantic_fallback']['citation_debug'] as $i => $debug)
                  <div class="text-xs border-b pb-2 mb-2">
                    <div><strong>Result {{ $i }}:</strong></div>
                    <div>Document ID: {{ $debug['document_id'] }}</div>
                    <div>DB Query: <span class="{{ $debug['db_query_result'] === 'found' ? 'text-green-600' : 'text-red-600' }}">{{ $debug['db_query_result'] }}</span></div>
                    <div>Intent Field: {{ $debug['intent_field'] }}</div>
                    @if(isset($debug['skip_reason']))
                    <div class="text-red-600">Skip: {{ $debug['skip_reason'] }}</div>
                    @endif
                    @if(isset($debug['citation_created']))
                    <div class="text-green-600">✅ Citation Created</div>
                    @endif
                  </div>
                  @endforeach
                </div>
              </div>
              @endif
              @endif
              </div>
            </div>
          </div>
          @endif
          
          @if(!empty($result['trace']['reranking']))
          <div class="md:col-span-2">
            <h3 class="font-medium text-blue-600">Reranking (driver: {{ $result['trace']['reranking']['driver'] }})</h3>
            <div class="bg-blue-50 border border-blue-200 rounded p-3 space-y-2">
              <div class="grid md:grid-cols-3 gap-3">
                <div>
                  <h4 class="font-medium text-sm text-blue-700">Reranker Info</h4>
                  <div class="text-sm">
                    <div>🎯 Driver: {{ $result['trace']['reranking']['driver'] }}</div>
                    <div>🔄 Input: {{ $result['trace']['reranking']['input_candidates'] }} candidates</div>
                    <div>✨ Output: {{ $result['trace']['reranking']['output_candidates'] }} candidates</div>
                  </div>
                </div>
                
                <div>
                  <h4 class="font-medium text-sm text-blue-700">Scores</h4>
                  <div class="text-sm max-h-32 overflow-auto">
                    @foreach(array_slice($result['trace']['reranking']['top_candidates'] ?? [], 0, 5) as $i => $candidate)
                    <div class="text-xs border-b pb-1 mb-1">
                      {{ $i + 1 }}. 
                      <a class="text-blue-700 underline" target="_blank" href="{{ route('admin.documents.index', ['tenant' => $tenant_id]) }}?doc_id={{ $candidate['document_id'] ?? '' }}">Doc {{ $candidate['document_id'] ?? 'N/A' }}</a>.{{ $candidate['chunk_index'] ?? 'N/A' }} 
                      <span class="inline-flex items-center px-1 py-0.5 rounded text-xs {{ (($candidate['llm_score'] ?? 0) >= 70 || ($candidate['score'] ?? 0) >= 0.7) ? 'bg-green-100 text-green-800' : ((($candidate['llm_score'] ?? 0) >= 50 || ($candidate['score'] ?? 0) >= 0.5) ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                        {{ isset($candidate['llm_score']) ? ('🤖 '.$candidate['llm_score'].'/100') : ('sim '.number_format(($candidate['score'] ?? 0),3)) }}
                      </span>
                      @if(isset($candidate['original_score']))
                      <span class="text-gray-500 ml-1">(was {{ number_format($candidate['original_score'], 3) }})</span>
                      @endif
                    </div>
                    @endforeach
                  </div>
                </div>
                
                <div>
                  <h4 class="font-medium text-sm text-blue-700">Top Reranked Results Preview</h4>
                  <div class="bg-white border rounded p-2 max-h-40 overflow-auto">
                    @foreach(array_slice($result['trace']['reranking']['top_candidates'] ?? [], 0, 3) as $i => $candidate)
                    <div class="text-xs border-b pb-2 mb-2">
                      <div class="flex justify-between items-center">
                        <span class="font-medium">{{ $i + 1 }}. <a class="text-blue-700 underline" target="_blank" href="{{ route('admin.documents.index', ['tenant' => $tenant_id]) }}?doc_id={{ $candidate['document_id'] ?? '' }}">Doc {{ $candidate['document_id'] ?? 'N/A' }}</a>.{{ $candidate['chunk_index'] ?? 'N/A' }}</span>
                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-bold {{ (($candidate['llm_score'] ?? 0) >= 70 || ($candidate['score'] ?? 0) >= 0.7) ? 'bg-green-100 text-green-800' : ((($candidate['llm_score'] ?? 0) >= 50 || ($candidate['score'] ?? 0) >= 0.5) ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                          {{ isset($candidate['llm_score']) ? ('🤖 '.$candidate['llm_score'].'/100') : ('sim '.number_format(($candidate['score'] ?? 0),3)) }}
                        </span>
                      </div>
                      <div class="text-gray-600 mt-1">{{ mb_substr($candidate['text'] ?? '', 0, 150) }}{{ mb_strlen($candidate['text'] ?? '') > 150 ? '...' : '' }}</div>
                    </div>
                    @endforeach
                  </div>
                </div>
              </div>
            </div>
          </div>
          @endif
          
          <div class="md:col-span-2">
            <h3 class="font-medium">Milvus health</h3>
            <pre class="bg-gray-50 border rounded p-2 rag-tester-pre rag-json-output">{{ json_encode($result['trace']['milvus'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div class="md:col-span-2">
            <h3 class="font-medium">Per query (top hits)</h3>
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto rag-tester-pre rag-json-output">{{ json_encode($result['trace']['per_query'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          @if(!empty($result['trace']['tenant_prompts']))
          <div class="md:col-span-2 mb-4">
            <h3 class="font-medium text-base text-purple-700">🎨 Tenant Custom Prompts</h3>
            <div class="bg-purple-50 border border-purple-200 rounded p-3 mt-1">
              <div class="grid md:grid-cols-2 gap-3 text-xs">
                <div>
                  <h4 class="font-medium">System Prompt</h4>
                  @if($result['trace']['tenant_prompts']['using_custom_system'])
                    <div class="text-green-600 font-medium">✅ Using Custom</div>
                    <pre class="bg-white border rounded p-2 mt-1 max-h-32 overflow-auto">{{ $result['trace']['tenant_prompts']['custom_system_prompt'] }}</pre>
                  @else
                    <div class="text-gray-600">❌ Using Default</div>
                    <div class="text-xs text-gray-500 mt-1">No custom system prompt configured</div>
                  @endif
                </div>
                <div>
                  <h4 class="font-medium">Context Template</h4>
                  @if($result['trace']['tenant_prompts']['using_custom_context'])
                    <div class="text-green-600 font-medium">✅ Using Custom</div>
                    <pre class="bg-white border rounded p-2 mt-1 max-h-32 overflow-auto">{{ $result['trace']['tenant_prompts']['custom_context_template'] }}</pre>
                  @else
                    <div class="text-gray-600">❌ Using Default</div>
                    <div class="text-xs text-gray-500 mt-1">No custom context template configured</div>
                  @endif
                </div>
              </div>
            </div>
          </div>
          @endif
          @if(!empty($result['trace']['llm_context']))
          <div class="md:col-span-2">
            <h3 class="font-medium">LLM context (testo passato al modello)</h3>
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto">{{ $result['trace']['llm_context'] }}</pre>
          </div>
          @endif
          @if(!empty($result['trace']['llm_messages']))
          <div class="md:col-span-2">
            <h3 class="font-medium">LLM messages (payload)</h3>
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto rag-tester-pre rag-json-output">{{ json_encode($result['trace']['llm_messages'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          @endif
          <div>
            <h3 class="font-medium">Fused top</h3>
            <pre class="bg-gray-50 border rounded p-2 rag-tester-pre rag-json-output">{{ json_encode($result['trace']['fused_top'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div>
            <h3 class="font-medium">Reranked top</h3>
            <pre class="bg-gray-50 border rounded p-2 rag-tester-pre rag-json-output">{{ json_encode($result['trace']['reranked_top'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div class="md:col-span-2">
            <h3 class="font-medium">MMR selected idx</h3>
            <pre class="bg-gray-50 border rounded p-2 rag-tester-pre rag-json-output">{{ json_encode($result['trace']['mmr_selected_idx'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
        </div>
      </details>
    </div>
    @endif
  </div>
@endif
<script>
function toggleConversationMessages(enabled) {
  const messagesDiv = document.getElementById('conversation-messages');
  if (enabled) {
    messagesDiv.classList.remove('hidden');
  } else {
    messagesDiv.classList.add('hidden');
  }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  const checkbox = document.querySelector('input[name="enable_conversation"]');
  if (checkbox) {
    toggleConversationMessages(checkbox.checked);
  }
});
</script>

@endsection

