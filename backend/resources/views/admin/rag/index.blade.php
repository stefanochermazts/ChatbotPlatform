@extends('admin.layout')

@section('content')
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
      <span class="text-sm">Top K (chunks)</span>
      <input type="number" name="top_k" min="1" max="50" value="{{ old('top_k', 20) }}" class="w-full border rounded px-3 py-2" />
    </label>
    <label class="block">
      <span class="text-sm">MMR λ (0-1)</span>
      <input type="number" name="mmr_lambda" step="0.05" min="0" max="1" value="{{ old('mmr_lambda', 0.3) }}" class="w-full border rounded px-3 py-2" />
    </label>
    <label class="block">
      <span class="text-sm">Max output tokens</span>
      <input type="number" name="max_output_tokens" min="32" max="8192" value="{{ old('max_output_tokens', config('openai.max_output_tokens', 700)) }}" class="w-full border rounded px-3 py-2" />
    </label>
  </div>
  <label class="block">
    <span class="text-sm">Query</span>
    <textarea name="query" rows="3" class="w-full border rounded px-3 py-2" required>{{ $query ?? '' }}</textarea>
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
      <textarea name="conversation_messages" rows="4" class="w-full border rounded px-3 py-2 font-mono text-xs" placeholder='[{"role": "user", "content": "Che orari ha la biblioteca?"}, {"role": "assistant", "content": "La biblioteca è aperta..."}, {"role": "user", "content": "E quanto costa il prestito?"}]'>{{ old('conversation_messages', request('conversation_messages')) }}</textarea>
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
                    <div class="text-xs font-medium text-gray-600 mb-1">📝 Snippet (estratto breve):</div>
                    <blockquote class="p-2 bg-gray-50 border rounded text-sm">{{ $c['snippet'] }}</blockquote>
                  </div>
                  @if(!empty($c['chunk_text']) && $c['chunk_text'] !== $c['snippet'])
                    <div>
                      <div class="text-xs font-medium text-blue-600 mb-1">📄 Chunk completo:</div>
                      <blockquote class="p-3 bg-blue-50 border border-blue-200 rounded text-sm max-h-60 overflow-y-auto">{{ $c['chunk_text'] }}</blockquote>
                    </div>
                  @endif
                </div>
              @endif
              @if(!empty($c['phone']))
                <div class="mt-1 text-sm"><span class="inline-block px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded">📞 Telefono: {{ $c['phone'] }}</span></div>
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
                <h4 class="font-medium text-sm">Citation Debug</h4>
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

          <div>
            <h3 class="font-medium">Queries</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['queries'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          
          @if(!empty($result['trace']['hyde']))
          <div>
            <h3 class="font-medium text-purple-600">🔬 HyDE (Hypothetical Document Embeddings)</h3>
            <div class="bg-purple-50 border border-purple-200 rounded p-3 space-y-2">
              <div class="grid md:grid-cols-2 gap-3">
                <div>
                  <h4 class="font-medium text-sm text-purple-700">Status</h4>
                  <div class="text-sm">
                    <span class="inline-flex items-center px-2 py-1 rounded text-xs {{ $result['trace']['hyde']['success'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                      {{ $result['trace']['hyde']['success'] ? '✅ Success' : '❌ Failed' }}
                    </span>
                    <span class="ml-2 text-gray-600">{{ $result['trace']['hyde']['processing_time_ms'] ?? 0 }}ms</span>
                  </div>
                </div>
                
                @if($result['trace']['hyde']['success'] && !empty($result['trace']['hyde']['weights']))
                <div>
                  <h4 class="font-medium text-sm text-purple-700">Embedding Weights</h4>
                  <div class="text-sm text-gray-600">
                    Original: {{ $result['trace']['hyde']['weights']['original'] ?? 0 }}% • 
                    Hypothetical: {{ $result['trace']['hyde']['weights']['hypothetical'] ?? 0 }}%
                  </div>
                </div>
                @endif
              </div>
              
              @if($result['trace']['hyde']['success'])
              <div>
                <h4 class="font-medium text-sm text-purple-700">Original Query</h4>
                <div class="text-sm bg-white border rounded p-2">{{ $result['trace']['hyde']['original_query'] ?? '' }}</div>
              </div>
              
              <div>
                <h4 class="font-medium text-sm text-purple-700">Generated Hypothetical Document</h4>
                <div class="text-sm bg-white border rounded p-2 max-h-32 overflow-auto">{{ $result['trace']['hyde']['hypothetical_document'] ?? '' }}</div>
              </div>
              @endif
              
              @if(!$result['trace']['hyde']['success'] && !empty($result['trace']['hyde']['error']))
              <div>
                <h4 class="font-medium text-sm text-red-700">Error</h4>
                <div class="text-sm text-red-600 bg-white border rounded p-2">{{ $result['trace']['hyde']['error'] }}</div>
              </div>
              @endif
            </div>
          </div>
          @endif
          
          @if(!empty($result['trace']['conversation']))
          <div>
            <h3 class="font-medium text-teal-600">💬 Conversation Context Enhancement</h3>
            <div class="bg-teal-50 border border-teal-200 rounded p-3 space-y-2">
              <div class="grid md:grid-cols-2 gap-3">
                <div>
                  <h4 class="font-medium text-sm text-teal-700">Context Status</h4>
                  <div class="text-sm">
                    <span class="inline-flex items-center px-2 py-1 rounded text-xs {{ $result['trace']['conversation']['context_used'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                      {{ $result['trace']['conversation']['context_used'] ? '✅ Context Applied' : '🔄 No Context Used' }}
                    </span>
                    <span class="ml-2 text-gray-600">{{ $result['trace']['conversation']['processing_time_ms'] ?? 0 }}ms</span>
                  </div>
                </div>
                
                @if($result['trace']['conversation']['context_used'])
                <div>
                  <h4 class="font-medium text-sm text-teal-700">Enhancement Info</h4>
                  <div class="text-sm text-gray-600">
                    <div>Original: {{ mb_strlen($result['trace']['conversation']['original_query'] ?? '') }} chars</div>
                    <div>Enhanced: {{ mb_strlen($result['trace']['conversation']['enhanced_query'] ?? '') }} chars</div>
                  </div>
                </div>
                @endif
              </div>
              
              @if($result['trace']['conversation']['context_used'])
              <div>
                <h4 class="font-medium text-sm text-teal-700">Original Query</h4>
                <div class="text-sm bg-white border rounded p-2">{{ $result['trace']['conversation']['original_query'] ?? '' }}</div>
              </div>
              
              <div>
                <h4 class="font-medium text-sm text-teal-700">Enhanced Query (with conversation context)</h4>
                <div class="text-sm bg-white border rounded p-2 max-h-32 overflow-auto">{{ $result['trace']['conversation']['enhanced_query'] ?? '' }}</div>
              </div>
              
              @if(!empty($result['trace']['conversation']['conversation_summary']))
              <div>
                <h4 class="font-medium text-sm text-teal-700">Conversation Summary</h4>
                <div class="text-sm bg-teal-25 border rounded p-2 max-h-24 overflow-auto">{{ $result['trace']['conversation']['conversation_summary'] }}</div>
              </div>
              @endif
              @endif
            </div>
          </div>
          @endif
          
          @if(!empty($result['trace']['reranking']) && $result['trace']['reranking']['driver'] === 'llm')
          <div>
            <h3 class="font-medium text-blue-600">🤖 LLM-as-a-Judge Reranking</h3>
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
                  <h4 class="font-medium text-sm text-blue-700">LLM Scores</h4>
                  <div class="text-sm max-h-32 overflow-auto">
                    @foreach(array_slice($result['trace']['reranking']['top_candidates'] ?? [], 0, 5) as $i => $candidate)
                    <div class="text-xs border-b pb-1 mb-1">
                      {{ $i + 1 }}. Doc {{ $candidate['document_id'] ?? 'N/A' }}.{{ $candidate['chunk_index'] ?? 'N/A' }} 
                      <span class="inline-flex items-center px-1 py-0.5 rounded text-xs {{ ($candidate['llm_score'] ?? 0) >= 70 ? 'bg-green-100 text-green-800' : (($candidate['llm_score'] ?? 0) >= 50 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                        🤖 {{ $candidate['llm_score'] ?? 0 }}/100
                      </span>
                      @if(isset($candidate['original_score']))
                      <span class="text-gray-500 ml-1">(was {{ number_format($candidate['original_score'], 3) }})</span>
                      @endif
                    </div>
                    @endforeach
                  </div>
                </div>
                
                <div>
                  <h4 class="font-medium text-sm text-blue-700">Score Distribution</h4>
                  <div class="text-xs space-y-1">
                    @php
                    $scores = array_column($result['trace']['reranking']['top_candidates'] ?? [], 'llm_score');
                    $excellent = count(array_filter($scores, fn($s) => $s >= 80));
                    $good = count(array_filter($scores, fn($s) => $s >= 60 && $s < 80));
                    $average = count(array_filter($scores, fn($s) => $s >= 40 && $s < 60));
                    $poor = count(array_filter($scores, fn($s) => $s < 40));
                    @endphp
                    <div class="flex justify-between">
                      <span>🚀 Excellent (80-100):</span> <span class="font-bold text-green-600">{{ $excellent }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span>😊 Good (60-79):</span> <span class="font-bold text-blue-600">{{ $good }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span>😐 Average (40-59):</span> <span class="font-bold text-yellow-600">{{ $average }}</span>
                    </div>
                    <div class="flex justify-between">
                      <span>😟 Poor (0-39):</span> <span class="font-bold text-red-600">{{ $poor }}</span>
                    </div>
                  </div>
                </div>
              </div>
              
              @if(!empty($result['trace']['reranking']['top_candidates']))
              <div>
                <h4 class="font-medium text-sm text-blue-700">Top Reranked Results Preview</h4>
                <div class="bg-white border rounded p-2 max-h-40 overflow-auto">
                  @foreach(array_slice($result['trace']['reranking']['top_candidates'] ?? [], 0, 3) as $i => $candidate)
                  <div class="text-xs border-b pb-2 mb-2">
                    <div class="flex justify-between items-center">
                      <span class="font-medium">{{ $i + 1 }}. Doc {{ $candidate['document_id'] ?? 'N/A' }}.{{ $candidate['chunk_index'] ?? 'N/A' }}</span>
                      <span class="inline-flex items-center px-2 py-1 rounded text-xs font-bold {{ ($candidate['llm_score'] ?? 0) >= 70 ? 'bg-green-100 text-green-800' : (($candidate['llm_score'] ?? 0) >= 50 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                        🤖 {{ $candidate['llm_score'] ?? 0 }}/100
                      </span>
                    </div>
                    <div class="text-gray-600 mt-1">{{ mb_substr($candidate['text'] ?? '', 0, 150) }}{{ mb_strlen($candidate['text'] ?? '') > 150 ? '...' : '' }}</div>
                  </div>
                  @endforeach
                </div>
              </div>
              @endif
            </div>
          </div>
          @endif
          
          <div>
            <h3 class="font-medium">Milvus health</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['milvus'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div class="md:col-span-2">
            <h3 class="font-medium">Per query (top hits)</h3>
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto">{{ json_encode($result['trace']['per_query'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
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
            <pre class="bg-gray-50 border rounded p-2 max-h-80 overflow-auto">{{ json_encode($result['trace']['llm_messages'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          @endif
          <div>
            <h3 class="font-medium">Fused top</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['fused_top'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div>
            <h3 class="font-medium">Reranked top</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['reranked_top'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
          </div>
          <div class="md:col-span-2">
            <h3 class="font-medium">MMR selected idx</h3>
            <pre class="bg-gray-50 border rounded p-2">{{ json_encode($result['trace']['mmr_selected_idx'] ?? [], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
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

