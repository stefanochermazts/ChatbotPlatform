@extends('admin.layout')

@section('title', 'Configurazione RAG - ' . $tenant->name)

@section('content')
<div class="w-full py-6">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 bg-white border-b border-gray-200">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Configurazione RAG</h1>
                    <p class="text-gray-600 mt-1">Personalizza i parametri di ricerca e generazione per {{ $tenant->name }}</p>
                </div>
                <div class="flex space-x-3">
                    <button id="test-config-btn" type="button" 
                            class="inline-flex items-center px-4 py-2 border border-green-300 shadow-sm text-sm font-medium rounded-md text-green-700 bg-green-50 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        🧪 Test Configurazione
                    </button>
                    <a href="{{ route('admin.tenants.edit', $tenant) }}" 
                       class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        ← Torna al Tenant
                    </a>
                </div>
            </div>

            <!-- Profile Selector -->
            <form id="rag-config-form" method="POST" action="{{ route('admin.rag-config.update', $tenant) }}">
                @csrf
                <div class="mb-6 p-4 bg-blue-50 rounded-lg">
                    <label for="rag_profile" class="block text-sm font-medium text-gray-700 mb-2">
                        Profilo RAG Predefinito
                    </label>
                    <select name="rag_profile" id="rag_profile" onchange="loadProfileTemplate()"
                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="custom" {{ ($tenant->rag_profile ?? 'custom') === 'custom' ? 'selected' : '' }}>
                            🛠️ Personalizzato
                        </option>
                        <option value="public_administration" {{ $tenant->rag_profile === 'public_administration' ? 'selected' : '' }}>
                            🏛️ Pubblica Amministrazione
                        </option>
                        <option value="ecommerce" {{ $tenant->rag_profile === 'ecommerce' ? 'selected' : '' }}>
                            🛍️ E-commerce
                        </option>
                        <option value="customer_service" {{ $tenant->rag_profile === 'customer_service' ? 'selected' : '' }}>
                            📞 Customer Service
                        </option>
                    </select>
                    <p class="text-xs text-gray-600 mt-1">
                        Seleziona un profilo predefinito o personalizza manualmente i parametri
                    </p>
                </div>

                <!-- Tabs Navigation -->
                <div class="border-b border-gray-200 mb-6">
                    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                        <button type="button" onclick="showTab('hybrid')" id="tab-hybrid"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-blue-500 text-blue-600">
                            🔍 Ricerca Ibrida
                        </button>
                        <button type="button" onclick="showTab('answer')" id="tab-answer"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            💬 Soglie Risposta
                        </button>
                        <button type="button" onclick="showTab('reranker')" id="tab-reranker"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            🎯 Reranking
                        </button>
                        <button type="button" onclick="showTab('advanced')" id="tab-advanced"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            🚀 Avanzate
                        </button>
                        <button type="button" onclick="showTab('intents')" id="tab-intents"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            🎭 Intent
                        </button>
                        <button type="button" onclick="showTab('chunking')" id="tab-chunking"
                                class="tab-button whitespace-nowrap py-2 px-1 border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                            📄 Chunking
                        </button>
                    </nav>
                </div>

                <!-- Tab: Hybrid Search -->
                <div id="content-hybrid" class="tab-content">
                    <!-- Help Section for Hybrid Search -->
                    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <div class="flex items-center justify-between cursor-pointer" onclick="toggleHelp('hybrid-help')">
                            <h3 class="text-lg font-medium text-blue-900">📚 Guida: Ricerca Ibrida</h3>
                            <svg id="hybrid-help-icon" class="w-5 h-5 text-blue-600 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                        <div id="hybrid-help" class="hidden mt-4 text-sm text-blue-800 space-y-3">
                            <p><strong>La ricerca ibrida</strong> combina ricerca vettoriale (semantica) e BM25 (parole chiave) per trovare i documenti più rilevanti.</p>
                            <div class="bg-blue-100 p-3 rounded">
                                <p><strong>📊 Esempio pratico:</strong></p>
                                <ul class="mt-2 space-y-1 text-xs">
                                    <li><strong>Query:</strong> "orari ufficio" → Vector: documenti su "orario", "apertura" | BM25: documenti con "orari", "ufficio"</li>
                                    <li><strong>Vector Top K = 20:</strong> 20 risultati semantici | <strong>BM25 Top K = 40:</strong> 40 risultati testuali</li>
                                    <li><strong>RRF K = 60:</strong> Combina i risultati con formula RRF per ranking finale</li>
                                    <li><strong>MMR:</strong> Filtra duplicati e garantisce diversità nei risultati finali</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="vector_top_k" class="block text-sm font-medium text-gray-700">Vector Top K</label>
                            <input type="number" name="vector_top_k" id="vector_top_k" min="1" max="200"
                                   value="{{ $currentConfig['hybrid']['vector_top_k'] ?? 40 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Più risultati = maggiore completezza, ma maggiore rumore</p>
                                <p><strong>Basso (10-20):</strong> Risposte precise ma potrebbe perdere informazioni rilevanti</p>
                                <p><strong>Alto (60-100):</strong> Risposte più complete ma potrebbe includere informazioni marginalmente rilevanti</p>
                                <p><strong>🎯 Suggerito:</strong> 30-50 per bilanciare precisione e completezza</p>
                            </div>
                        </div>

                        <div>
                            <label for="bm25_top_k" class="block text-sm font-medium text-gray-700">BM25 Top K</label>
                            <input type="number" name="bm25_top_k" id="bm25_top_k" min="1" max="300"
                                   value="{{ $currentConfig['hybrid']['bm25_top_k'] ?? 80 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Cattura termini esatti, acronimi, codici</p>
                                <p><strong>Basso (20-40):</strong> Solo matching molto precisi, perde sinonimi</p>
                                <p><strong>Alto (100-200):</strong> Cattura variazioni linguistiche ma aggiunge rumore</p>
                                <p><strong>🎯 Suggerito:</strong> 60-100 per testi tecnici, 40-80 per conversazioni</p>
                            </div>
                        </div>

                        <div>
                            <label for="rrf_k" class="block text-sm font-medium text-gray-700">RRF K</label>
                            <input type="number" name="rrf_k" id="rrf_k" min="10" max="100"
                                   value="{{ $currentConfig['hybrid']['rrf_k'] ?? 60 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Controlla la fusione dei ranking Vector + BM25</p>
                                <p><strong>Basso (20-40):</strong> Privilegia i primi risultati, meno democratico</p>
                                <p><strong>Alto (70-90):</strong> Risultati più equamente distribuiti tra le due modalità</p>
                                <p><strong>🎯 Suggerito:</strong> 60 per bilanciamento ottimale</p>
                            </div>
                        </div>

                        <div>
                            <label for="mmr_lambda" class="block text-sm font-medium text-gray-700">MMR Lambda</label>
                            <input type="number" name="mmr_lambda" id="mmr_lambda" min="0" max="1" step="0.05"
                                   value="{{ $currentConfig['hybrid']['mmr_lambda'] ?? 0.25 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Bilancia rilevanza vs diversità dei risultati</p>
                                <p><strong>0.0-0.3:</strong> Massimizza diversità, evita ripetizioni</p>
                                <p><strong>0.7-1.0:</strong> Massimizza rilevanza, consente duplicati simili</p>
                                <p><strong>🎯 Suggerito:</strong> 0.25 per evitare ridondanza mantenendo rilevanza</p>
                            </div>
                        </div>

                        <div>
                            <label for="mmr_take" class="block text-sm font-medium text-gray-700">MMR Take</label>
                            <input type="number" name="mmr_take" id="mmr_take" min="1" max="100"
                                   value="{{ $currentConfig['hybrid']['mmr_take'] ?? 10 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Numero finale di documenti nel contesto LLM</p>
                                <p><strong>Basso (3-8):</strong> Risposte concise, meno copertura tematica</p>
                                <p><strong>Alto (15-30):</strong> Più informazioni ma possibile confusione LLM</p>
                                <p><strong>🎯 Suggerito:</strong> 8-12 per bilanciare completezza e chiarezza</p>
                            </div>
                        </div>

                        <div>
                            <label for="neighbor_radius" class="block text-sm font-medium text-gray-700">Neighbor Radius</label>
                            <input type="number" name="neighbor_radius" id="neighbor_radius" min="0" max="10"
                                   value="{{ $currentConfig['hybrid']['neighbor_radius'] ?? 2 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Include chunk precedenti/successivi per contesto</p>
                                <p><strong>0:</strong> Solo chunk esatto trovato</p>
                                <p><strong>2-3:</strong> Include paragrafi adiacenti per contesto completo</p>
                                <p><strong>🎯 Suggerito:</strong> 2 per mantenere il flusso narrativo</p>
                            </div>
                        </div>
                    </div>

                    <!-- Multi-Query Section -->
                    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Multi-Query Expansion</h3>
                        <div class="mb-3 text-xs text-gray-700 bg-gray-100 p-2 rounded">
                            <strong>💡 Funzionalità:</strong> Genera variazioni della query originale per catturare più sfumature semantiche e migliorare il recall.
                            <br><strong>Esempio:</strong> "orari ufficio" → "quando apre l'ufficio", "apertura sportelli", "orario di ricevimento"
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="multiquery_enabled" id="multiquery_enabled"
                                           {{ ($currentConfig['multiquery']['enabled'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">Abilita Multi-Query</span>
                                </label>
                            </div>
                            <div>
                                <label for="multiquery_num" class="block text-sm font-medium text-gray-700">Numero Query</label>
                                <input type="number" name="multiquery_num" id="multiquery_num" min="1" max="10"
                                       value="{{ $currentConfig['multiquery']['num'] ?? 3 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>2-3:</strong> Leggero miglioramento recall</p>
                                    <p><strong>4-6:</strong> Maggiore copertura ma più costi</p>
                                </div>
                            </div>
                            <div>
                                <label for="multiquery_temperature" class="block text-sm font-medium text-gray-700">Temperature</label>
                                <input type="number" name="multiquery_temperature" id="multiquery_temperature" min="0" max="1" step="0.1"
                                       value="{{ $currentConfig['multiquery']['temperature'] ?? 0.3 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>0.1-0.3:</strong> Variazioni conservative</p>
                                    <p><strong>0.5-0.7:</strong> Variazioni creative ma rischiose</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Answer Thresholds -->
                <div id="content-answer" class="tab-content hidden">
                    <!-- Help Section for Answer Thresholds -->
                    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <div class="flex items-center justify-between cursor-pointer" onclick="toggleHelp('answer-help')">
                            <h3 class="text-lg font-medium text-yellow-900">🎯 Guida: Soglie di Risposta</h3>
                            <svg id="answer-help-icon" class="w-5 h-5 text-yellow-600 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                        <div id="answer-help" class="hidden mt-4 text-sm text-yellow-800 space-y-3">
                            <p><strong>Controllo qualità</strong> delle risposte per evitare allucinazioni e risposte poco attendibili.</p>
                            <div class="bg-yellow-100 p-3 rounded">
                                <p><strong>📊 Esempio pratico:</strong></p>
                                <ul class="mt-2 space-y-1 text-xs">
                                    <li><strong>Min Citations = 2:</strong> Serve almeno 2 documenti rilevanti per rispondere</li>
                                    <li><strong>Min Confidence = 0.15:</strong> Lo score più alto deve essere ≥ 0.15</li>
                                    <li><strong>Force if Citations = ✓:</strong> Se trova citazioni, risponde sempre (anche con confidence bassa)</li>
                                    <li><strong>Fallback:</strong> Messaggio mostrato quando le soglie non sono soddisfatte</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="min_citations" class="block text-sm font-medium text-gray-700">Citazioni Minime</label>
                            <input type="number" name="min_citations" id="min_citations" min="0" max="10"
                                   value="{{ $currentConfig['answer']['min_citations'] ?? 1 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Richiede prove multiple per aumentare affidabilità</p>
                                <p><strong>1:</strong> Basta un documento rilevante</p>
                                <p><strong>2-3:</strong> Richiede conferma da fonti multiple (raccomandato)</p>
                                <p><strong>🎯 Suggerito:</strong> 2 per bilanciare copertura e affidabilità</p>
                            </div>
                        </div>

                        <div>
                            <label for="min_confidence" class="block text-sm font-medium text-gray-700">Confidence Minima</label>
                            <input type="number" name="min_confidence" id="min_confidence" min="0" max="1" step="0.01"
                                   value="{{ $currentConfig['answer']['min_confidence'] ?? 0.08 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Soglia di rilevanza semantica minima</p>
                                <p><strong>0.05-0.10:</strong> Permissivo, più risposte ma meno precise</p>
                                <p><strong>0.15-0.25:</strong> Rigoroso, meno risposte ma più accurate</p>
                                <p><strong>🎯 Suggerito:</strong> 0.12 per buon compromesso qualità/copertura</p>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label class="flex items-center">
                                <input type="checkbox" name="force_if_has_citations" id="force_if_has_citations"
                                       {{ ($currentConfig['answer']['force_if_has_citations'] ?? true) ? 'checked' : '' }}
                                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <span class="ml-2 text-sm text-gray-700">Forza risposta se ci sono citazioni</span>
                            </label>
                            <div class="text-xs text-gray-600 mt-2 ml-6">
                                <p><strong>✅ Abilitato:</strong> Se trova documenti rilevanti, risponde sempre (anche con confidence bassa)</p>
                                <p><strong>❌ Disabilitato:</strong> Rispetta sempre le soglie di confidence</p>
                                <p><strong>🎯 Raccomandato:</strong> Abilitato per massimizzare l'utilità del chatbot</p>
                            </div>
                        </div>

                        <div class="md:col-span-2">
                            <label for="fallback_message" class="block text-sm font-medium text-gray-700">Messaggio di Fallback</label>
                            <textarea name="fallback_message" id="fallback_message" rows="3"
                                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                      placeholder="Messaggio quando non si trova risposta adeguata">{{ $currentConfig['answer']['fallback_message'] ?? 'Non lo so con certezza: non trovo riferimenti sufficienti nella base di conoscenza.' }}</textarea>
                            <div class="text-xs text-gray-600 mt-1">
                                <p><strong>Quando viene mostrato:</strong> Quando confidence < soglia OR citazioni < minimo</p>
                                <p><strong>💡 Suggerimento:</strong> Personalizza per il tuo dominio (es. "Contatta l'ufficio per informazioni specifiche")</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Reranker -->
                <div id="content-reranker" class="tab-content hidden">
                    <!-- Help Section for Reranker -->
                    <div class="mb-6 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                        <div class="flex items-center justify-between cursor-pointer" onclick="toggleHelp('reranker-help')">
                            <h3 class="text-lg font-medium text-purple-900">🎯 Guida: Reranking</h3>
                            <svg id="reranker-help-icon" class="w-5 h-5 text-purple-600 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                        <div id="reranker-help" class="hidden mt-4 text-sm text-purple-800 space-y-3">
                            <p><strong>Il reranking</strong> riordina i documenti trovati dalla ricerca ibrida per migliorare la precisione finale.</p>
                            <div class="bg-purple-100 p-3 rounded">
                                <p><strong>📊 Confronto driver:</strong></p>
                                <ul class="mt-2 space-y-1 text-xs">
                                    <li><strong>🧮 Embedding:</strong> Veloce, usa similarità vettoriale per riordinare</li>
                                    <li><strong>🎯 Cohere:</strong> Servizio specializzato, molto accurato ma costa di più</li>
                                    <li><strong>🤖 LLM:</strong> GPT giudica relevanza, massima accuratezza ma lento e costoso</li>
                                    <li><strong>❌ Disabilitato:</strong> Mantiene ordine dalla ricerca ibrida, più veloce</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="reranker_driver" class="block text-sm font-medium text-gray-700">Driver Reranker</label>
                            <select name="reranker_driver" id="reranker_driver"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="embedding" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'embedding' ? 'selected' : '' }}>
                                    🧮 Embedding (Veloce)
                                </option>
                                <option value="cohere" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'cohere' ? 'selected' : '' }}>
                                    🎯 Cohere (Accurato)
                                </option>
                                <option value="llm" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'llm' ? 'selected' : '' }}>
                                    🤖 LLM (Molto Accurato)
                                </option>
                                <option value="none" {{ ($currentConfig['reranker']['driver'] ?? 'embedding') === 'none' ? 'selected' : '' }}>
                                    ❌ Disabilitato
                                </option>
                            </select>
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto qualità/performance:</strong></p>
                                <p><strong>Embedding:</strong> +10% accuratezza, +50ms latenza</p>
                                <p><strong>Cohere:</strong> +25% accuratezza, +200ms latenza, +$$$</p>
                                <p><strong>LLM:</strong> +40% accuratezza, +2s latenza, +$$$$</p>
                            </div>
                        </div>

                        <div>
                            <label for="reranker_top_n" class="block text-sm font-medium text-gray-700">Top N Candidati</label>
                            <input type="number" name="reranker_top_n" id="reranker_top_n" min="1" max="100"
                                   value="{{ $currentConfig['reranker']['top_n'] ?? 40 }}"
                                   class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <div class="text-xs text-gray-600 mt-1 space-y-1">
                                <p><strong>Impatto:</strong> Quanti documenti sottoporre al reranking</p>
                                <p><strong>Basso (10-20):</strong> Veloce ma potrebbe perdere documenti rilevanti</p>
                                <p><strong>Alto (50-80):</strong> Più accurato ma più lento e costoso</p>
                                <p><strong>🎯 Suggerito:</strong> 30-50 per buon bilanciamento</p>
                            </div>
                        </div>
                    </div>

                    <!-- Context Settings -->
                    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Context Building</h3>
                        <div class="mb-3 text-xs text-gray-700 bg-gray-100 p-2 rounded">
                            <strong>💡 Funzionalità:</strong> Gestisce la quantità di testo inviato al LLM per bilanciare completezza e performance.
                            <br><strong>Compressione:</strong> Se il contesto supera la soglia, viene compresso automaticamente rimuovendo dettagli meno rilevanti.
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="context_max_chars" class="block text-sm font-medium text-gray-700">Max Caratteri</label>
                                <input type="number" name="context_max_chars" id="context_max_chars" min="1000" max="100000"
                                       value="{{ $currentConfig['context']['max_chars'] ?? 6000 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>Limite assoluto</strong> del contesto inviato al LLM</p>
                                    <p><strong>4000-6000:</strong> Standard per risposte bilanciate</p>
                                    <p><strong>8000-15000:</strong> Per domande complesse che richiedono molto contesto</p>
                                    <p><strong>20000-50000:</strong> Testing avanzato e estrazione massima (costi elevati)</p>
                                </div>
                            </div>
                            <div>
                                <label for="compress_if_over_chars" class="block text-sm font-medium text-gray-700">Comprimi se > Chars</label>
                                <input type="number" name="compress_if_over_chars" id="compress_if_over_chars" min="1000" max="25000"
                                       value="{{ $currentConfig['context']['compress_if_over_chars'] ?? 7000 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>Soglia compressione:</strong> Quando iniziare a comprimere</p>
                                    <p><strong>Deve essere > Max Caratteri</strong> per avere margine</p>
                                    <p><strong>🎯 Suggerito:</strong> 1.2x del Max Caratteri</p>
                                </div>
                            </div>
                            <div>
                                <label for="compress_target_chars" class="block text-sm font-medium text-gray-700">Target Compressione</label>
                                <input type="number" name="compress_target_chars" id="compress_target_chars" min="500" max="15000"
                                       value="{{ $currentConfig['context']['compress_target_chars'] ?? 3500 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>Dimensione obiettivo</strong> dopo compressione</p>
                                    <p><strong>Dovrebbe essere < Max Caratteri</strong></p>
                                    <p><strong>🎯 Suggerito:</strong> 60% del Max Caratteri</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Advanced -->
                <div id="content-advanced" class="tab-content hidden">
                    <!-- HyDE Settings -->
                    <div class="p-4 bg-purple-50 rounded-lg mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">🔮 HyDE (Hypothetical Document Embeddings)</h3>
                        <div class="mb-3 text-xs text-purple-700 bg-purple-100 p-2 rounded">
                            <strong>💡 Tecnica avanzata:</strong> Il LLM genera una risposta ipotetica alla query, poi cerca documenti simili a quella risposta.
                            <br><strong>Esempio:</strong> Query "orari ufficio" → LLM genera "L'ufficio è aperto dalle 9:00 alle 17:00" → Cerca documenti simili
                            <br><strong>⚠️ Sperimentale:</strong> Può migliorare recall ma aumenta costi e latenza
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="hyde_enabled" id="hyde_enabled"
                                           {{ ($currentConfig['advanced']['hyde']['enabled'] ?? false) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-purple-600 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                    <span class="ml-2 text-sm text-gray-700">Abilita HyDE</span>
                                </label>
                            </div>
                            <div>
                                <label for="hyde_weight_original" class="block text-sm font-medium text-gray-700">Peso Originale</label>
                                <input type="number" name="hyde_weight_original" id="hyde_weight_original" min="0" max="1" step="0.1"
                                       value="{{ $currentConfig['advanced']['hyde']['weight_original'] ?? 0.6 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                <div class="text-xs text-purple-600 mt-1">
                                    <p><strong>Peso query originale</strong> vs ipotetica</p>
                                    <p><strong>🎯 Suggerito:</strong> 0.6 per privilegiare la query reale</p>
                                </div>
                            </div>
                            <div>
                                <label for="hyde_weight_hypothetical" class="block text-sm font-medium text-gray-700">Peso Ipotetico</label>
                                <input type="number" name="hyde_weight_hypothetical" id="hyde_weight_hypothetical" min="0" max="1" step="0.1"
                                       value="{{ $currentConfig['advanced']['hyde']['weight_hypothetical'] ?? 0.4 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                <div class="text-xs text-purple-600 mt-1">
                                    <p><strong>Peso risposta ipotetica</strong> generata dal LLM</p>
                                    <p><strong>⚠️ Nota:</strong> Originale + Ipotetico dovrebbero sommare a 1.0</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- LLM Reranker Settings -->
                    <div class="p-4 bg-orange-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">🤖 LLM Reranker</h3>
                        <div class="mb-3 text-xs text-orange-700 bg-orange-100 p-2 rounded">
                            <strong>💡 Reranking intelligente:</strong> Il LLM legge la query e tutti i documenti candidati per giudicare la rilevanza.
                            <br><strong>⚠️ Costoso:</strong> Usa molti token ma fornisce la massima precisione nel ranking
                            <br><strong>🎯 Quando usare:</strong> Per domini critici dove la precisione è fondamentale
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="flex items-center">
                                    <input type="checkbox" name="llm_reranker_enabled" id="llm_reranker_enabled"
                                           {{ ($currentConfig['advanced']['llm_reranker']['enabled'] ?? false) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-orange-600 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                    <span class="ml-2 text-sm text-gray-700">Abilita LLM Reranking</span>
                                </label>
                            </div>
                            <div>
                                <label for="llm_reranker_batch_size" class="block text-sm font-medium text-gray-700">Batch Size</label>
                                <input type="number" name="llm_reranker_batch_size" id="llm_reranker_batch_size" min="1" max="20"
                                       value="{{ $currentConfig['advanced']['llm_reranker']['batch_size'] ?? 5 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                <div class="text-xs text-orange-600 mt-1">
                                    <p><strong>Documenti per chiamata LLM</strong></p>
                                    <p><strong>3-5:</strong> Bilancia accuratezza e costi</p>
                                    <p><strong>8-10:</strong> Più context per LLM ma più costoso</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Intents -->
                <div id="content-intents" class="tab-content hidden">
                    <!-- Help Section for Intents -->
                    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                        <div class="flex items-center justify-between cursor-pointer" onclick="toggleHelp('intents-help')">
                            <h3 class="text-lg font-medium text-green-900">🎭 Guida: Intent Detection</h3>
                            <svg id="intents-help-icon" class="w-5 h-5 text-green-600 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                        <div id="intents-help" class="hidden mt-4 text-sm text-green-800 space-y-3">
                            <p><strong>Intent specializzati</strong> per estrarre informazioni specifiche con pattern ottimizzati.</p>
                            <div class="bg-green-100 p-3 rounded">
                                <p><strong>📊 Esempi pratici:</strong></p>
                                <ul class="mt-2 space-y-1 text-xs">
                                    <li><strong>📞 Phone:</strong> "numero telefono" → Estrae telefoni da documenti</li>
                                    <li><strong>📧 Email:</strong> "contatti email" → Trova indirizzi email</li>
                                    <li><strong>📍 Address:</strong> "dove siete" → Estrae indirizzi fisici</li>
                                    <li><strong>🕒 Schedule:</strong> "orari apertura" → Trova orari con pattern avanzati</li>
                                    <li><strong>🙏 Thanks:</strong> "grazie" → Risposta cortese predefinita</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Intent Enablement -->
                        <div class="p-4 bg-blue-50 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Intent Attivi</h3>
                            <div class="space-y-2">
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_thanks" id="intent_thanks"
                                           {{ ($currentConfig['intents']['enabled']['thanks'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">🙏 Thanks</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_phone" id="intent_phone"
                                           {{ ($currentConfig['intents']['enabled']['phone'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">📞 Phone</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_email" id="intent_email"
                                           {{ ($currentConfig['intents']['enabled']['email'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">📧 Email</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_address" id="intent_address"
                                           {{ ($currentConfig['intents']['enabled']['address'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">📍 Address</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="intent_schedule" id="intent_schedule"
                                           {{ ($currentConfig['intents']['enabled']['schedule'] ?? true) ? 'checked' : '' }}
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="ml-2 text-sm text-gray-700">🕒 Schedule</span>
                                </label>
                            </div>
                        </div>

                        <!-- Intent Settings -->
                        <div>
                            <div class="mb-4">
                                <label for="intent_min_score" class="block text-sm font-medium text-gray-700">Score Minimo Intent</label>
                                <input type="number" name="intent_min_score" id="intent_min_score" min="0" max="1" step="0.05"
                                       value="{{ $currentConfig['intents']['min_score'] ?? 0.5 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <div class="text-xs text-gray-600 mt-1 space-y-1">
                                    <p><strong>Soglia per attivare intent</strong> specifici</p>
                                    <p><strong>0.3-0.5:</strong> Permissivo, rileva intent anche con matching parziale</p>
                                    <p><strong>0.6-0.8:</strong> Rigoroso, solo matching molto chiari</p>
                                    <p><strong>🎯 Suggerito:</strong> 0.5 per bilanciare precisione e recall</p>
                                </div>
                            </div>

                            <div>
                                <label for="intent_execution_strategy" class="block text-sm font-medium text-gray-700">Strategia Esecuzione</label>
                                <select name="intent_execution_strategy" id="intent_execution_strategy"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="priority_based" {{ ($currentConfig['intents']['execution_strategy'] ?? 'priority_based') === 'priority_based' ? 'selected' : '' }}>
                                        🎯 Basata su Score
                                    </option>
                                    <option value="first_match" {{ ($currentConfig['intents']['execution_strategy'] ?? 'priority_based') === 'first_match' ? 'selected' : '' }}>
                                        ⚡ Primo Match
                                    </option>
                                </select>
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>🎯 Score:</strong> Esegue l'intent con score più alto</p>
                                    <p><strong>⚡ First Match:</strong> Esegue il primo intent che supera la soglia</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KB Selection -->
                    <div class="mt-6 p-4 bg-green-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Selezione Knowledge Base</h3>
                        <div class="mb-3 text-xs text-green-700 bg-green-100 p-2 rounded">
                            <strong>💡 Strategia multi-KB:</strong> Come il sistema decide quale Knowledge Base usare per la ricerca.
                            <br><strong>🤖 Auto:</strong> BM25 su nomi KB per selezionare automaticamente | <strong>🔒 Strict:</strong> Solo KB selezionata dall'utente | <strong>🌐 Multi:</strong> Cerca in tutte le KB
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="kb_selection_mode" class="block text-sm font-medium text-gray-700">Modalità Selezione</label>
                                <select name="kb_selection_mode" id="kb_selection_mode"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                    <option value="auto" {{ ($currentConfig['kb_selection']['mode'] ?? 'auto') === 'auto' ? 'selected' : '' }}>🤖 Automatica</option>
                                    <option value="strict" {{ ($currentConfig['kb_selection']['mode'] ?? 'auto') === 'strict' ? 'selected' : '' }}>🔒 Rigorosa</option>
                                    <option value="multi" {{ ($currentConfig['kb_selection']['mode'] ?? 'auto') === 'multi' ? 'selected' : '' }}>🌐 Multi-KB</option>
                                </select>
                            </div>
                            <div>
                                <label for="bm25_boost_factor" class="block text-sm font-medium text-gray-700">Boost BM25</label>
                                <input type="number" name="bm25_boost_factor" id="bm25_boost_factor" min="0.1" max="5" step="0.1"
                                       value="{{ $currentConfig['kb_selection']['bm25_boost_factor'] ?? 1.0 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>Amplifica punteggio BM25</strong> nella selezione KB</p>
                                    <p><strong>1.5-2.0:</strong> Privilegia matching esatti di parole chiave</p>
                                </div>
                            </div>
                            <div>
                                <label for="vector_boost_factor" class="block text-sm font-medium text-gray-700">Boost Vector</label>
                                <input type="number" name="vector_boost_factor" id="vector_boost_factor" min="0.1" max="5" step="0.1"
                                       value="{{ $currentConfig['kb_selection']['vector_boost_factor'] ?? 1.0 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                <div class="text-xs text-gray-600 mt-1">
                                    <p><strong>Amplifica punteggio semantico</strong> nella selezione KB</p>
                                    <p><strong>1.2-1.8:</strong> Privilegia matching semantici e sinonimi</p>
                                </div>
                            </div>
                        </div>
                        <!-- Nuovi campi configurazione boost -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <div>
                                <label for="kb_upload_boost" class="block text-sm font-medium text-gray-700">Boost documenti Upload</label>
                                <input type="number" name="kb_upload_boost" id="kb_upload_boost" min="0.5" max="3" step="0.05"
                                       value="{{ $currentConfig['kb_selection']['upload_boost'] ?? 1.0 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500">
                                <p class="text-xs text-gray-600 mt-1">1.0 = neutro. Esempio 1.25 per privilegiare documenti caricati manualmente.</p>
                            </div>
                            <div>
                                <label for="kb_title_keyword_boosts" class="block text-sm font-medium text-gray-700">Boost per keyword nel titolo (JSON)</label>
                                <textarea name="kb_title_keyword_boosts" id="kb_title_keyword_boosts" rows="5"
                                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                                          placeholder='{"attività commerciali":1.2, "negozi":1.15}'>{{ isset($currentConfig['kb_selection']['title_keyword_boosts']) ? json_encode($currentConfig['kb_selection']['title_keyword_boosts']) : '' }}</textarea>
                                <p class="text-xs text-gray-600 mt-1">Mappa "keyword": fattore. Case-insensitive, match su titolo documento.</p>
                            </div>
                            <div>
                                <label for="kb_location_boosts" class="block text-sm font-medium text-gray-700">Boost per location nella query (JSON)</label>
                                <textarea name="kb_location_boosts" id="kb_location_boosts" rows="5"
                                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500"
                                          placeholder='{"san cesareo":1.15}'>{{ isset($currentConfig['kb_selection']['location_boosts']) ? json_encode($currentConfig['kb_selection']['location_boosts']) : '' }}</textarea>
                                <p class="text-xs text-gray-600 mt-1">Mappa "location": fattore. Case-insensitive, match sulla query normalizzata.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Widget/API Performance -->
                    <div class="mt-6 p-4 bg-purple-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">⚡ Widget/API Performance</h3>
                        <div class="mb-3 text-xs text-purple-700 bg-purple-100 p-2 rounded">
                            <strong>🚀 Ottimizzazioni performance:</strong> Parametri specifici per il widget e le API chat completions.
                            <br><strong>📊 Limiti contesto:</strong> Controllo dimensioni per garantire risposte rapide | <strong>🎛️ Modello LLM:</strong> Configurazione modello e parametri
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Performance Parameters -->
                            <div class="space-y-3">
                                <h4 class="font-medium text-gray-900">Limiti Performance</h4>
                                
                                <div>
                                    <label for="widget_max_tokens" class="block text-sm font-medium text-gray-700">Max Tokens LLM</label>
                                    <input type="number" name="widget_max_tokens" id="widget_max_tokens" min="100" max="4000" step="50"
                                           value="{{ $currentConfig['widget']['max_tokens'] ?? 800 }}"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                    <p class="text-xs text-gray-600 mt-1">Limite tokens per risposta LLM (default: 800)</p>
                                </div>
                                
                                <div>
                                    <label for="widget_max_context_chars" class="block text-sm font-medium text-gray-700">Max Caratteri Contesto</label>
                                    <input type="number" name="widget_max_context_chars" id="widget_max_context_chars" min="5000" max="50000" step="1000"
                                           value="{{ $currentConfig['widget']['max_context_chars'] ?? 15000 }}"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                    <p class="text-xs text-gray-600 mt-1">Limite totale contesto inviato al LLM (default: 15000)</p>
                                </div>
                                
                                <div>
                                    <label for="widget_max_citation_chars" class="block text-sm font-medium text-gray-700">Max Caratteri per Citazione</label>
                                    <input type="number" name="widget_max_citation_chars" id="widget_max_citation_chars" min="500" max="10000" step="250"
                                           value="{{ $currentConfig['widget']['max_citation_chars'] ?? 2000 }}"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                    <p class="text-xs text-gray-600 mt-1">Limite caratteri per singola citazione (default: 2000)</p>
                                </div>
                                
                                <div class="flex items-center">
                                    <input type="checkbox" name="widget_enable_context_truncation" id="widget_enable_context_truncation" value="1"
                                           {{ ($currentConfig['widget']['enable_context_truncation'] ?? true) ? 'checked' : '' }}
                                           class="h-4 w-4 text-purple-600 focus:ring-purple-500 border-gray-300 rounded">
                                    <label for="widget_enable_context_truncation" class="ml-2 block text-sm text-gray-900">
                                        Abilita troncamento contesto per performance
                                    </label>
                                </div>
                            </div>
                            
                            <!-- LLM Configuration -->
                            <div class="space-y-3">
                                <h4 class="font-medium text-gray-900">Configurazione LLM</h4>
                                
                                <div>
                                    <label for="widget_model" class="block text-sm font-medium text-gray-700">Modello LLM</label>
                                    <select name="widget_model" id="widget_model"
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                        <option value="gpt-4o" {{ ($currentConfig['widget']['model'] ?? 'gpt-4o-mini') === 'gpt-4o' ? 'selected' : '' }}>gpt-4o</option>
                                        <option value="gpt-4o-mini" {{ ($currentConfig['widget']['model'] ?? 'gpt-4o-mini') === 'gpt-4o-mini' ? 'selected' : '' }}>gpt-4o-mini</option>
                                        <option value="gpt-4.1" {{ ($currentConfig['widget']['model'] ?? '') === 'gpt-4.1' ? 'selected' : '' }}>gpt-4.1</option>
                                        <option value="gpt-4.1-mini" {{ ($currentConfig['widget']['model'] ?? '') === 'gpt-4.1-mini' ? 'selected' : '' }}>gpt-4.1-mini</option>
                                        <option value="gpt-4.1-nano" {{ ($currentConfig['widget']['model'] ?? '') === 'gpt-4.1-nano' ? 'selected' : '' }}>gpt-4.1-nano</option>
                                        <option value="gpt-5" {{ ($currentConfig['widget']['model'] ?? '') === 'gpt-5' ? 'selected' : '' }}>gpt-5</option>
                                        <option value="gpt-5-mini" {{ ($currentConfig['widget']['model'] ?? '') === 'gpt-5-mini' ? 'selected' : '' }}>gpt-5-mini</option>
                                        <option value="gpt-5-nano" {{ ($currentConfig['widget']['model'] ?? '') === 'gpt-5-nano' ? 'selected' : '' }}>gpt-5-nano</option>
                                    </select>
                                    <p class="text-xs text-gray-600 mt-1">Modello OpenAI per risposte widget</p>
                                </div>
                                
                                <div>
                                    <label for="widget_temperature" class="block text-sm font-medium text-gray-700">Temperature</label>
                                    <input type="number" name="widget_temperature" id="widget_temperature" min="0" max="1" step="0.1"
                                           value="{{ $currentConfig['widget']['temperature'] ?? 0.2 }}"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                    <p class="text-xs text-gray-600 mt-1">Creatività risposte (0=preciso, 1=creativo, default: 0.2)</p>
                                </div>
                                
                                <div>
                                    <label for="widget_timeout_seconds" class="block text-sm font-medium text-gray-700">Timeout Secondi</label>
                                    <input type="number" name="widget_timeout_seconds" id="widget_timeout_seconds" min="10" max="120" step="5"
                                           value="{{ $currentConfig['widget']['timeout_seconds'] ?? 30 }}"
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                    <p class="text-xs text-gray-600 mt-1">Timeout chiamate OpenAI (default: 30 secondi)</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Chunking -->
                <div id="content-chunking" class="tab-content hidden">
                    <!-- Help Section for Chunking -->
                    <div class="mb-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
                        <div class="flex items-center justify-between cursor-pointer" onclick="toggleHelp('chunking-help')">
                            <h3 class="text-lg font-medium text-orange-900">📄 Guida: Parametri di Chunking</h3>
                            <span class="text-orange-600">▼</span>
                        </div>
                        <div id="chunking-help" class="mt-4 text-sm text-orange-800 hidden">
                            <div class="space-y-2">
                                <p><strong>🔧 Chunking:</strong> Suddivisione automatica dei documenti in porzioni più piccole per il processamento RAG.</p>
                                <p><strong>📏 Max Characters:</strong> Dimensione massima di ogni chunk in caratteri. Valori più alti preservano contesto ma possono superare limiti LLM.</p>
                                <p><strong>🔄 Overlap Characters:</strong> Sovrapposizione tra chunk consecutivi per mantenere continuità del contenuto.</p>
                                <p><strong>📊 Table-Aware:</strong> Il sistema preserva automaticamente le tabelle markdown complete ignorando i limiti di dimensione.</p>
                                <p><strong>⚠️ Importante:</strong> Modificando questi parametri è necessario ri-ingerire i documenti esistenti per applicare le nuove dimensioni.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Chunking Configuration -->
                    <div class="p-4 bg-orange-50 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">📄 Configurazione Chunking</h3>
                        <div class="mb-3 text-xs text-orange-700 bg-orange-100 p-2 rounded">
                            <strong>🎯 Strategia chunking:</strong> Controllo dimensioni e sovrapposizione per ottimizzare qualità retrieval.
                            <br><strong>📈 Raccomandazioni:</strong> 2200-3500 caratteri per tabelle complesse | 1500-2200 per testo normale | Overlap 10-20% della dimensione chunk
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Max Characters -->
                            <div>
                                <label for="chunking_max_chars" class="block text-sm font-medium text-gray-700">Caratteri Massimi per Chunk</label>
                                <input type="number" name="chunking_max_chars" id="chunking_max_chars" min="500" max="8000" step="100"
                                       value="{{ $currentConfig['chunking']['max_chars'] ?? 2200 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                <div class="text-xs text-gray-600 mt-1 space-y-1">
                                    <p><strong>Dimensione target per ogni chunk di testo</strong></p>
                                    <p><strong>1500-2200:</strong> Testo normale, paragrafi, articoli</p>
                                    <p><strong>2500-3500:</strong> Tabelle complesse, contenuto strutturato</p>
                                    <p><strong>3500+:</strong> Documenti molto tecnici con tabelle estese</p>
                                    <p><strong>🎯 Default:</strong> 2200 (bilanciato per la maggior parte dei contenuti)</p>
                                </div>
                            </div>

                            <!-- Overlap Characters -->
                            <div>
                                <label for="chunking_overlap_chars" class="block text-sm font-medium text-gray-700">Caratteri di Sovrapposizione</label>
                                <input type="number" name="chunking_overlap_chars" id="chunking_overlap_chars" min="50" max="1000" step="25"
                                       value="{{ $currentConfig['chunking']['overlap_chars'] ?? 250 }}"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-orange-500 focus:ring-orange-500">
                                <div class="text-xs text-gray-600 mt-1 space-y-1">
                                    <p><strong>Sovrapposizione tra chunk consecutivi</strong></p>
                                    <p><strong>100-200:</strong> Overlap minimale per testi lineari</p>
                                    <p><strong>200-400:</strong> Overlap standard per preservare contesto</p>
                                    <p><strong>400+:</strong> Overlap alto per contenuti molto connessi</p>
                                    <p><strong>🎯 Default:</strong> 250 (circa 10-15% del chunk)</p>
                                </div>
                            </div>
                        </div>

                        <!-- Preview Calculation -->
                        <div class="mt-6 p-3 bg-gray-50 rounded border">
                            <h4 class="font-medium text-gray-900 mb-2">📊 Calcolo Automatico</h4>
                            <div class="text-sm text-gray-700 space-y-1" id="chunking-preview">
                                <p>• <strong>Dimensione chunk:</strong> <span id="preview-max-chars">2200</span> caratteri</p>
                                <p>• <strong>Sovrapposizione:</strong> <span id="preview-overlap-chars">250</span> caratteri (<span id="preview-overlap-percent">11%</span>)</p>
                                <p>• <strong>Chunk netto:</strong> <span id="preview-net-chars">1950</span> caratteri per chunk</p>
                                <p>• <strong>Stima per 10KB:</strong> ~<span id="preview-chunks-10k">5</span> chunk</p>
                            </div>
                        </div>

                        <!-- Action Required Notice -->
                        <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <span class="text-yellow-600">⚠️</span>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-yellow-800">Azione Richiesta</h4>
                                    <p class="text-sm text-yellow-700 mt-1">
                                        Dopo aver modificato questi parametri, sarà necessario <strong>ri-ingerire i documenti esistenti</strong> 
                                        per applicare le nuove dimensioni di chunking. I documenti con ingestion_status = "completed" 
                                        dovranno essere ri-processati.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center pt-6 border-t border-gray-200">
                    <button type="button" onclick="resetToDefaults()" 
                            class="inline-flex items-center px-4 py-2 border border-red-300 shadow-sm text-sm font-medium rounded-md text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        🔄 Ripristina Default
                    </button>
                    
                    <button type="submit" 
                            class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        💾 Salva Configurazione
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Test Results Modal -->
    <div id="test-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Risultati Test Configurazione</h3>
                    <button onclick="closeTestModal()" class="text-gray-400 hover:text-gray-600">
                        <span class="sr-only">Chiudi</span>
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div id="test-results-content">
                    <!-- Results will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// CSRF Token setup
window.csrfToken = '{{ csrf_token() }}';

// Tab Management
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Show selected tab content
    document.getElementById('content-' + tabName).classList.remove('hidden');
    
    // Update tab buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('border-blue-500', 'text-blue-600');
        button.classList.add('border-transparent', 'text-gray-500');
    });
    
    document.getElementById('tab-' + tabName).classList.remove('border-transparent', 'text-gray-500');
    document.getElementById('tab-' + tabName).classList.add('border-blue-500', 'text-blue-600');
}

// Load Profile Template
async function loadProfileTemplate() {
    const profileSelect = document.getElementById('rag_profile');
    const profile = profileSelect.value;
    
    if (profile === 'custom') return;
    
    try {
        const response = await fetch(`{{ route('admin.rag-config.profile-template') }}?profile=${profile}`);
        const data = await response.json();
        
        if (data.success && data.template) {
            populateFormWithTemplate(data.template);
        }
    } catch (error) {
        console.error('Error loading profile template:', error);
    }
}

// Populate Form with Template
function populateFormWithTemplate(template) {
    // Hybrid settings
    if (template.hybrid) {
        Object.keys(template.hybrid).forEach(key => {
            const field = document.getElementById(key);
            if (field) field.value = template.hybrid[key];
        });
    }
    
    // Answer settings
    if (template.answer) {
        Object.keys(template.answer).forEach(key => {
            const field = document.getElementById(key);
            if (field) {
                if (field.type === 'checkbox') {
                    field.checked = template.answer[key];
                } else {
                    field.value = template.answer[key];
                }
            }
        });
    }
    
    // Reranker settings
    if (template.reranker) {
        Object.keys(template.reranker).forEach(key => {
            const field = document.getElementById('reranker_' + key);
            if (field) field.value = template.reranker[key];
        });
    }
    
    // Add more template population as needed...
}

// Test Configuration
async function testConfig() {
    const button = document.getElementById('test-config-btn');
    const originalText = button.innerHTML;
    button.innerHTML = '⏳ Testing...';
    button.disabled = true;
    
    console.log('Starting test config...');
    console.log('CSRF Token:', window.csrfToken);
    
    try {
        const response = await fetch(`{{ route('admin.rag-config.test', $tenant) }}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.csrfToken
            },
            body: JSON.stringify({
                query: 'orario vigili urbani'
            })
        });
        
        console.log('Response status:', response.status);
        console.log('Response ok:', response.ok);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.log('Response error text:', errorText);
            throw new Error(`HTTP ${response.status}: ${errorText}`);
        }
        
        const data = await response.json();
        console.log('Response data:', data);
        showTestResults(data);
        
    } catch (error) {
        console.error('Test error:', error);
        showTestResults({
            success: false,
            error: 'Errore durante il test: ' + error.message
        });
    } finally {
        button.innerHTML = originalText;
        button.disabled = false;
    }
}

// Show Test Results
function showTestResults(data) {
    const modal = document.getElementById('test-modal');
    const content = document.getElementById('test-results-content');
    
    if (data.success) {
        content.innerHTML = `
            <div class="bg-green-50 border border-green-200 rounded-md p-4 mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-green-800">Test Completato</h3>
                        <div class="mt-2 text-sm text-green-700">
                            <p><strong>Citazioni trovate:</strong> ${data.citations}</p>
                            <p><strong>Confidence:</strong> ${(data.confidence * 100).toFixed(1)}%</p>
                        </div>
                    </div>
                </div>
            </div>
            
            ${data.debug ? `<details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-gray-700">Debug Info</summary>
                <pre class="mt-2 text-xs bg-gray-100 p-3 rounded overflow-auto">${JSON.stringify(data.debug, null, 2)}</pre>
            </details>` : ''}
        `;
    } else {
        content.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-red-800">Test Fallito</h3>
                        <div class="mt-2 text-sm text-red-700">
                            <p>${data.error}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    modal.classList.remove('hidden');
}

// Close Test Modal
function closeTestModal() {
    document.getElementById('test-modal').classList.add('hidden');
}

// Reset to Defaults
async function resetToDefaults() {
    if (!confirm('Sei sicuro di voler ripristinare tutte le impostazioni ai valori di default?')) {
        return;
    }
    
    try {
        const response = await fetch(`{{ route('admin.rag-config.reset', $tenant) }}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': window.csrfToken
            }
        });
        
        if (response.ok) {
            location.reload();
        }
    } catch (error) {
        alert('Errore durante il ripristino: ' + error.message);
    }
}

// Toggle Help Sections
function toggleHelp(helpId) {
    const helpSection = document.getElementById(helpId);
    const icon = document.getElementById(helpId + '-icon');
    
    if (helpSection.classList.contains('hidden')) {
        helpSection.classList.remove('hidden');
        icon.style.transform = 'rotate(180deg)';
    } else {
        helpSection.classList.add('hidden');
        icon.style.transform = 'rotate(0deg)';
    }
}

// Event Listeners
document.getElementById('test-config-btn').addEventListener('click', testConfig);

// Close modal when clicking outside
document.getElementById('test-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeTestModal();
    }
});

// Chunking Preview Calculation
function updateChunkingPreview() {
    const maxChars = parseInt(document.getElementById('chunking_max_chars').value) || 2200;
    const overlapChars = parseInt(document.getElementById('chunking_overlap_chars').value) || 250;
    
    // Calculate values
    const netChars = Math.max(0, maxChars - overlapChars);
    const overlapPercent = Math.round((overlapChars / maxChars) * 100);
    const chunks10k = Math.ceil(10000 / netChars);
    
    // Update preview
    document.getElementById('preview-max-chars').textContent = maxChars;
    document.getElementById('preview-overlap-chars').textContent = overlapChars;
    document.getElementById('preview-overlap-percent').textContent = overlapPercent + '%';
    document.getElementById('preview-net-chars').textContent = netChars;
    document.getElementById('preview-chunks-10k').textContent = chunks10k;
    
    // Warning if overlap is too high
    const warningThreshold = 50; // 50% overlap is too much
    if (overlapPercent > warningThreshold) {
        document.getElementById('preview-overlap-percent').style.color = '#dc2626'; // red
        document.getElementById('preview-overlap-percent').textContent = overlapPercent + '% ⚠️';
    } else {
        document.getElementById('preview-overlap-percent').style.color = '#374151'; // gray
    }
}

// Add event listeners for chunking inputs
document.addEventListener('DOMContentLoaded', function() {
    const maxCharsInput = document.getElementById('chunking_max_chars');
    const overlapCharsInput = document.getElementById('chunking_overlap_chars');
    
    if (maxCharsInput && overlapCharsInput) {
        maxCharsInput.addEventListener('input', updateChunkingPreview);
        overlapCharsInput.addEventListener('input', updateChunkingPreview);
        
        // Initial calculation
        updateChunkingPreview();
    }
});
</script>
@endsection
