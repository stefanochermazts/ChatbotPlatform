@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">🕷️ Web Scraper – {{ $tenant->name }}</h1>

<div class="bg-blue-50 border border-blue-200 rounded p-4 mb-6">
  <h2 class="font-semibold text-blue-800 mb-2">ℹ️ Come Configurare lo Scraper</h2>
  <p class="text-sm text-blue-700">
    Lo scraper estrae automaticamente contenuti da siti web e li aggiunge alla knowledge base del tenant. 
    Configura i parametri sotto e clicca "Esegui" per avviare l'estrazione.
  </p>
</div>

<form method="post" action="{{ route('admin.scraper.update', $tenant) }}" class="bg-white border rounded p-4 grid gap-6">
  @csrf
  
  <!-- URLs di Base -->
  <div class="grid md:grid-cols-2 gap-6">
    <div class="space-y-2">
      <label class="block">
        <span class="text-sm font-medium text-gray-700">🚀 Seed URLs (Obbligatorio)</span>
        <textarea name="seed_urls" rows="4" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="Inserisci gli URL di partenza...">{{ old('seed_urls', implode("\n", $config->seed_urls ?? [])) }}</textarea>
      </label>
      <div class="bg-gray-50 p-3 rounded text-xs">
        <strong>📝 Cosa inserire:</strong> Gli URL da cui iniziare il crawling (uno per riga)<br>
        <strong>📋 Esempi:</strong><br>
        <code class="bg-white px-1 rounded">https://www.comune.esempio.it/</code><br>
        <code class="bg-white px-1 rounded">https://www.comune.esempio.it/servizi/</code><br>
        <code class="bg-white px-1 rounded">https://www.comune.esempio.it/uffici/</code>
      </div>
    </div>

    <div class="space-y-2">
      <label class="block">
        <span class="text-sm font-medium text-gray-700">🌐 Allowed Domains (Raccomandato)</span>
        <textarea name="allowed_domains" rows="4" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="Inserisci i domini permessi...">{{ old('allowed_domains', implode("\n", $config->allowed_domains ?? [])) }}</textarea>
      </label>
      <div class="bg-gray-50 p-3 rounded text-xs">
        <strong>📝 Cosa inserire:</strong> Domini da cui è permesso scaricare contenuti<br>
        <strong>📋 Esempi:</strong><br>
        <code class="bg-white px-1 rounded">comune.esempio.it</code><br>
        <code class="bg-white px-1 rounded">www.comune.esempio.it</code><br>
        <code class="bg-white px-1 rounded">portale.comune.esempio.it</code><br>
        <small class="text-gray-600">⚠️ Se vuoto, accetta tutti i domini (pericoloso!)</small>
      </div>
    </div>
  </div>

  <!-- Configurazioni Avanzate -->
  <div class="grid md:grid-cols-2 gap-6">
    <div class="space-y-2">
      <label class="block">
        <span class="text-sm font-medium text-gray-700">🗺️ Sitemap URLs (Opzionale)</span>
        <textarea name="sitemap_urls" rows="3" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="URL delle sitemap XML...">{{ old('sitemap_urls', implode("\n", $config->sitemap_urls ?? [])) }}</textarea>
      </label>
      <div class="bg-gray-50 p-3 rounded text-xs">
        <strong>📝 Cosa inserire:</strong> URL delle sitemap XML per crawling efficiente<br>
        <strong>📋 Esempi:</strong><br>
        <code class="bg-white px-1 rounded">https://www.comune.esempio.it/sitemap.xml</code><br>
        <code class="bg-white px-1 rounded">https://www.comune.esempio.it/sitemap_pages.xml</code><br>
        <small class="text-gray-600">💡 Le sitemap accelerano notevolmente il processo</small>
      </div>
    </div>

    <div class="space-y-2">
      <label class="block">
        <span class="text-sm font-medium text-gray-700">✅ Include Patterns (Opzionale)</span>
        <textarea name="include_patterns" rows="3" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="Pattern regex per includere URL...">{{ old('include_patterns', implode("\n", $config->include_patterns ?? [])) }}</textarea>
      </label>
      <div class="bg-gray-50 p-3 rounded text-xs">
        <strong>📝 Cosa inserire:</strong> Pattern regex per includere solo URL specifici<br>
        <strong>📋 Esempi:</strong><br>
        <code class="bg-white px-1 rounded">/servizi/.*</code> <small>(tutte le pagine in /servizi/)</small><br>
        <code class="bg-white px-1 rounded">/uffici/.*</code> <small>(tutte le pagine in /uffici/)</small><br>
        <code class="bg-white px-1 rounded">/news/\d{4}/.*</code> <small>(news con anno)</small><br>
        <small class="text-gray-600">⚡ Utile per limitare a sezioni specifiche</small>
      </div>
    </div>
  </div>

  <div class="space-y-2">
    <label class="block">
      <span class="text-sm font-medium text-gray-700">🚫 Exclude Patterns (Raccomandato)</span>
      <textarea name="exclude_patterns" rows="3" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="Pattern regex per escludere URL...">{{ old('exclude_patterns', implode("\n", $config->exclude_patterns ?? [])) }}</textarea>
    </label>
    <div class="bg-gray-50 p-3 rounded text-xs grid md:grid-cols-2 gap-4">
      <div>
        <strong>📝 Cosa inserire:</strong> Pattern regex per escludere URL indesiderati<br>
        <strong>📋 Esempi Comuni:</strong><br>
        <code class="bg-white px-1 rounded">/admin/.*</code> <small>(area admin)</small><br>
        <code class="bg-white px-1 rounded">/login.*</code> <small>(pagine login)</small><br>
        <code class="bg-white px-1 rounded">/search.*</code> <small>(pagine ricerca)</small><br>
        <code class="bg-white px-1 rounded">\.pdf$</code> <small>(file PDF)</small>
      </div>
      <div>
        <strong>🎯 Pattern Utili PA:</strong><br>
        <code class="bg-white px-1 rounded">/bandi/.*</code> <small>(bandi di gara)</small><br>
        <code class="bg-white px-1 rounded">/albo.*</code> <small>(albo pretorio)</small><br>
        <code class="bg-white px-1 rounded">/download/.*</code> <small>(file download)</small><br>
        <code class="bg-white px-1 rounded">/calendario.*</code> <small>(eventi)</small>
      </div>
    </div>
  </div>
  <!-- Parametri Tecnici -->
  <div class="border-t pt-6">
    <h3 class="font-semibold text-gray-800 mb-4">⚙️ Parametri Tecnici</h3>
    
    <div class="grid md:grid-cols-2 gap-6">
      <div class="space-y-4">
        <div class="space-y-2">
          <label class="block">
            <span class="text-sm font-medium text-gray-700">🔢 Max Depth</span>
            <input type="number" name="max_depth" value="{{ old('max_depth', $config->max_depth ?? 2) }}" min="0" max="10" class="w-full border rounded px-3 py-2" />
          </label>
          <div class="bg-gray-50 p-3 rounded text-xs">
            <strong>📝 Cosa significa:</strong> Quanti livelli di link seguire dal seed URL<br>
            <strong>📋 Esempi:</strong><br>
            <code class="bg-white px-1 rounded">1</code> <small>= Solo pagine linkate direttamente</small><br>
            <code class="bg-white px-1 rounded">2</code> <small>= Link + link dai link (raccomandato)</small><br>
            <code class="bg-white px-1 rounded">3</code> <small>= Più profondo (può essere lento)</small><br>
            <small class="text-gray-600">⚠️ Valori alti = molte pagine</small>
          </div>
        </div>

        <div class="space-y-2">
          <label class="block">
            <span class="text-sm font-medium text-gray-700">⚡ Rate Limit (RPS)</span>
            <input type="number" name="rate_limit_rps" value="{{ old('rate_limit_rps', $config->rate_limit_rps ?? 1) }}" min="0" max="10" step="0.5" class="w-full border rounded px-3 py-2" />
          </label>
          <div class="bg-gray-50 p-3 rounded text-xs">
            <strong>📝 Cosa significa:</strong> Richieste per secondo al server target<br>
            <strong>📋 Valori consigliati:</strong><br>
            <code class="bg-white px-1 rounded">0.5</code> <small>= Molto lento, siti piccoli/sensibili</small><br>
            <code class="bg-white px-1 rounded">1</code> <small>= Standard, sicuro per la maggior parte</small><br>
            <code class="bg-white px-1 rounded">2-3</code> <small>= Veloce, solo siti robusti</small><br>
            <small class="text-gray-600">⚠️ Troppo alto = rischio ban IP</small>
          </div>
        </div>
      </div>

      <div class="space-y-4">
        <div class="space-y-3">
          <span class="text-sm font-medium text-gray-700 block">🔧 Opzioni Avanzate</span>
          
          <label class="inline-flex items-start gap-3 p-3 border rounded hover:bg-gray-50">
            <input type="checkbox" name="render_js" value="1" {{ old('render_js', $config->render_js ?? false) ? 'checked' : '' }} class="mt-1" />
            <div class="text-sm">
              <div class="font-medium">🚀 Render JavaScript</div>
              <div class="text-gray-600 text-xs mt-1">
                Esegue JavaScript per SPA (React, Vue, Angular).<br>
                <strong>Usa se:</strong> Il sito carica contenuto via JS<br>
                <strong>⚠️ Attenzione:</strong> Molto più lento
              </div>
            </div>
          </label>

          <label class="inline-flex items-start gap-3 p-3 border rounded hover:bg-gray-50">
            <input type="checkbox" name="respect_robots" value="1" {{ old('respect_robots', $config->respect_robots ?? true) ? 'checked' : '' }} class="mt-1" />
            <div class="text-sm">
              <div class="font-medium">🤖 Rispetta robots.txt</div>
              <div class="text-gray-600 text-xs mt-1">
                Segue le regole del file robots.txt del sito.<br>
                <strong>Raccomandato:</strong> Sempre attivo per etica<br>
                <strong>⚠️ Disattiva solo:</strong> Se necessario e autorizzato
              </div>
            </div>
          </label>
        </div>
      </div>
    </div>
  </div>

  <!-- Auth Headers -->
  <div class="border-t pt-6">
    <div class="space-y-2">
      <label class="block">
        <span class="text-sm font-medium text-gray-700">🔐 Auth Headers (Opzionale)</span>
        <textarea name="auth_headers" rows="4" class="w-full border rounded px-3 py-2 font-mono text-sm" placeholder="Intestazioni di autenticazione...">{{ old('auth_headers', collect($config->auth_headers ?? [])->map(fn($v,$k) => $k.': '.$v)->implode("\n")) }}</textarea>
      </label>
      <div class="bg-gray-50 p-3 rounded text-xs">
        <strong>📝 Quando usare:</strong> Per siti che richiedono autenticazione/autorizzazione<br>
        <strong>📋 Esempi:</strong><br>
        <code class="bg-white px-1 rounded">Authorization: Bearer your-token-here</code><br>
        <code class="bg-white px-1 rounded">X-API-Key: your-api-key</code><br>
        <code class="bg-white px-1 rounded">Cookie: sessionid=abc123; csrftoken=xyz789</code><br>
        <code class="bg-white px-1 rounded">User-Agent: YourBot/1.0</code><br>
        <small class="text-gray-600">⚠️ Non condividere mai credenziali sensibili</small>
      </div>
    </div>
  </div>
  <!-- Pulsante Salva -->
  <div class="border-t pt-6">
    <div class="flex gap-3">
      <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 font-medium">
        💾 Salva Configurazione
      </button>
    </div>
  </div>
</form>

<!-- Esempi Pratici -->
<div class="mt-8 grid md:grid-cols-2 gap-6">
  <div class="bg-green-50 border border-green-200 rounded p-4">
    <h3 class="font-semibold text-green-800 mb-3">🏛️ Esempio: Sito Comunale</h3>
    <div class="text-xs space-y-2">
      <div><strong>Seed URLs:</strong><br><code class="bg-white px-1 rounded">https://www.comune.example.it/</code></div>
      <div><strong>Allowed Domains:</strong><br><code class="bg-white px-1 rounded">comune.example.it</code></div>
      <div><strong>Include Patterns:</strong><br><code class="bg-white px-1 rounded">/servizi/.*</code><br><code class="bg-white px-1 rounded">/uffici/.*</code></div>
      <div><strong>Exclude Patterns:</strong><br><code class="bg-white px-1 rounded">/admin/.*</code><br><code class="bg-white px-1 rounded">\.pdf$</code></div>
      <div><strong>Parametri:</strong> Max Depth: 2, Rate: 1 RPS</div>
    </div>
  </div>

  <div class="bg-purple-50 border border-purple-200 rounded p-4">
    <h3 class="font-semibold text-purple-800 mb-3">📰 Esempio: Sito News/Blog</h3>
    <div class="text-xs space-y-2">
      <div><strong>Seed URLs:</strong><br><code class="bg-white px-1 rounded">https://blog.example.it/</code></div>
      <div><strong>Sitemap:</strong><br><code class="bg-white px-1 rounded">https://blog.example.it/sitemap.xml</code></div>
      <div><strong>Include Patterns:</strong><br><code class="bg-white px-1 rounded">/articoli/.*</code><br><code class="bg-white px-1 rounded">/\d{4}/.*</code></div>
      <div><strong>Exclude Patterns:</strong><br><code class="bg-white px-1 rounded">/tag/.*</code><br><code class="bg-white px-1 rounded">/author/.*</code></div>
      <div><strong>Parametri:</strong> Max Depth: 1, Rate: 2 RPS</div>
    </div>
  </div>

  <div class="bg-orange-50 border border-orange-200 rounded p-4">
    <h3 class="font-semibold text-orange-800 mb-3">🏢 Esempio: Sito Aziendale</h3>
    <div class="text-xs space-y-2">
      <div><strong>Seed URLs:</strong><br><code class="bg-white px-1 rounded">https://azienda.it/servizi/</code><br><code class="bg-white px-1 rounded">https://azienda.it/prodotti/</code></div>
      <div><strong>Include Patterns:</strong><br><code class="bg-white px-1 rounded">/servizi/.*</code><br><code class="bg-white px-1 rounded">/prodotti/.*</code></div>
      <div><strong>Exclude Patterns:</strong><br><code class="bg-white px-1 rounded">/carrello.*</code><br><code class="bg-white px-1 rounded">/account.*</code></div>
      <div><strong>Parametri:</strong> Max Depth: 3, Rate: 1.5 RPS</div>
    </div>
  </div>

  <div class="bg-yellow-50 border border-yellow-200 rounded p-4">
    <h3 class="font-semibold text-yellow-800 mb-3">⚠️ Suggerimenti Importanti</h3>
    <div class="text-xs space-y-1">
      <div>🎯 <strong>Inizia semplice:</strong> Solo Seed URLs + Allowed Domains</div>
      <div>🧪 <strong>Testa prima:</strong> Usa modalità sincrona per debug</div>
      <div>📊 <strong>Monitora:</strong> Controlla i log per errori</div>
      <div>⚡ <strong>Rate limit:</strong> Inizia con 1 RPS, aumenta gradualmente</div>
      <div>🔍 <strong>Pattern:</strong> Aggiungi exclude per evitare contenuto indesiderato</div>
      <div>🤖 <strong>Etica:</strong> Rispetta sempre robots.txt e ToS</div>
    </div>
  </div>
</div>

@if($config->exists && !empty($config->seed_urls))
<div class="bg-white border border-green-200 rounded p-6 mt-8">
  <h2 class="font-semibold text-green-800 mb-4">🚀 Esegui Scraping</h2>
  
  <div class="grid md:grid-cols-2 gap-4">
    <div class="space-y-3">
      <form method="post" action="{{ route('admin.scraper.run', $tenant) }}" class="block">
        @csrf
        <button type="submit" class="w-full px-4 py-3 bg-green-600 text-white rounded hover:bg-green-700 font-medium text-left">
          🚀 <strong>Avvia Scraping (Background)</strong>
        </button>
      </form>
      <div class="bg-green-50 p-3 rounded text-xs">
        <strong>✅ Raccomandato per:</strong><br>
        • Siti grandi (>50 pagine)<br>
        • Scraping periodici/automatici<br>
        • Quando non vuoi attendere<br><br>
        <strong>📊 Come controllare:</strong><br>
        • Risultati nei log: <code class="bg-white px-1 rounded">storage/logs/laravel.log</code><br>
        • Documenti in: Admin → Documenti
      </div>
    </div>

    <div class="space-y-3">
      <form method="post" action="{{ route('admin.scraper.run-sync', $tenant) }}" class="block">
        @csrf
        <button type="submit" class="w-full px-4 py-3 bg-orange-600 text-white rounded hover:bg-orange-700 font-medium text-left">
          ⚡ <strong>Esegui Ora (Sincrono)</strong>
        </button>
      </form>
      <div class="bg-orange-50 p-3 rounded text-xs">
        <strong>🧪 Raccomandato per:</strong><br>
        • Test configurazione<br>
        • Siti piccoli (<20 pagine)<br>
        • Debug problemi<br><br>
        <strong>⚠️ Attenzione:</strong><br>
        • Blocca browser fino al completamento<br>
        • Timeout dopo ~5 minuti
      </div>
    </div>
  </div>

  <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded">
    <h4 class="font-medium text-blue-800 mb-2">💡 Sistema di Deduplicazione</h4>
    <div class="text-xs text-blue-700 space-y-1">
      <div>🆕 <strong>Pagine nuove:</strong> Crea nuovo documento in formato Markdown</div>
      <div>🔄 <strong>Contenuto modificato:</strong> Aggiorna documento esistente con nuova versione</div>
      <div>⏭️ <strong>Contenuto identico:</strong> Skip automatico, nessun documento duplicato</div>
      <div class="mt-2 text-blue-600">
        <strong>📈 Vedrai statistiche tipo:</strong> 
        <code class="bg-white px-1 rounded">"15 URLs visitati, 8 documenti processati (Nuovi: 3, Aggiornati: 2, Invariati: 3)"</code>
      </div>
    </div>
  </div>
</div>
@else
<div class="bg-yellow-50 border border-yellow-200 rounded p-4 mt-8">
  <h3 class="font-semibold text-yellow-800 mb-2">⚠️ Configurazione Richiesta</h3>
  <p class="text-sm text-yellow-700">
    Per eseguire lo scraping, devi prima configurare almeno i <strong>Seed URLs</strong> e salvare la configurazione.
  </p>
</div>
@endif

@if(session('ok'))
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded mt-4">
  {{ session('ok') }}
</div>
@endif

@if(session('error'))
<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded mt-4">
  {{ session('error') }}
</div>
@endif

@endsection

