@extends('admin.layout')

@section('content')
<h1 class="text-xl font-semibold mb-4">Scraper – {{ $tenant->name }}</h1>
<form method="post" action="{{ route('admin.scraper.update', $tenant) }}" class="bg-white border rounded p-4 grid gap-4">
  @csrf
  <div class="grid md:grid-cols-2 gap-4">
    <label class="block">
      <span class="text-sm">Seed URLs (uno per riga)</span>
      <textarea name="seed_urls" rows="4" class="w-full border rounded px-3 py-2">{{ old('seed_urls', implode("\n", $config->seed_urls ?? [])) }}</textarea>
    </label>
    <label class="block">
      <span class="text-sm">Allowed domains (uno per riga)</span>
      <textarea name="allowed_domains" rows="4" class="w-full border rounded px-3 py-2">{{ old('allowed_domains', implode("\n", $config->allowed_domains ?? [])) }}</textarea>
    </label>
    <label class="block">
      <span class="text-sm">Sitemap URLs</span>
      <textarea name="sitemap_urls" rows="3" class="w-full border rounded px-3 py-2">{{ old('sitemap_urls', implode("\n", $config->sitemap_urls ?? [])) }}</textarea>
    </label>
    <label class="block">
      <span class="text-sm">Include patterns (regex, uno per riga)</span>
      <textarea name="include_patterns" rows="3" class="w-full border rounded px-3 py-2">{{ old('include_patterns', implode("\n", $config->include_patterns ?? [])) }}</textarea>
    </label>
    <label class="block">
      <span class="text-sm">Exclude patterns (regex, uno per riga)</span>
      <textarea name="exclude_patterns" rows="3" class="w-full border rounded px-3 py-2">{{ old('exclude_patterns', implode("\n", $config->exclude_patterns ?? [])) }}</textarea>
    </label>
  </div>
  <div class="grid md:grid-cols-3 gap-4 items-end">
    <label class="block">
      <span class="text-sm">Max depth</span>
      <input type="number" name="max_depth" value="{{ old('max_depth', $config->max_depth ?? 2) }}" class="w-full border rounded px-3 py-2" />
    </label>
    <label class="block">
      <span class="text-sm">Rate limit (RPS)</span>
      <input type="number" name="rate_limit_rps" value="{{ old('rate_limit_rps', $config->rate_limit_rps ?? 1) }}" class="w-full border rounded px-3 py-2" />
    </label>
    <div class="flex gap-4">
      <label class="inline-flex items-center gap-2"><input type="checkbox" name="render_js" value="1" {{ old('render_js', $config->render_js ?? false) ? 'checked' : '' }} /> Render JS</label>
      <label class="inline-flex items-center gap-2"><input type="checkbox" name="respect_robots" value="1" {{ old('respect_robots', $config->respect_robots ?? true) ? 'checked' : '' }} /> Robots</label>
    </div>
  </div>
  <label class="block">
    <span class="text-sm">Auth headers (uno per riga: Chiave: Valore)</span>
    <textarea name="auth_headers" rows="3" class="w-full border rounded px-3 py-2">{{ old('auth_headers', collect($config->auth_headers ?? [])->map(fn($v,$k) => $k.': '.$v)->implode("\n")) }}</textarea>
  </label>
  <div>
    <button class="px-3 py-2 bg-blue-600 text-white rounded">Salva</button>
  </div>
</form>
@endsection

