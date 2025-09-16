@extends('admin.layout')

@section('content')
<div class="flex items-center justify-between mb-4">
  <h1 class="text-xl font-semibold">Clienti</h1>
  <a href="{{ route('admin.tenants.create') }}" class="px-3 py-2 bg-blue-600 text-white rounded">Nuovo Cliente</a>
</div>
<div class="bg-white border rounded">
  <table class="w-full text-sm">
    <thead>
      <tr class="bg-gray-100 text-left">
        <th class="p-2">ID</th>
        <th class="p-2">Nome</th>
        <th class="p-2">Slug</th>
        <th class="p-2">Piano</th>
        <th class="p-2">Azioni</th>
      </tr>
    </thead>
    <tbody>
      @foreach($tenants as $t)
      <tr class="border-t">
        <td class="p-2">{{ $t->id }}</td>
        <td class="p-2">{{ $t->name }}</td>
        <td class="p-2">{{ $t->slug }}</td>
        <td class="p-2">{{ $t->plan }}</td>
        <td class="p-2 flex gap-2">
          <a href="{{ route('admin.tenants.edit', $t) }}" class="text-blue-600">Modifica</a>
          <a href="{{ route('admin.documents.index', $t) }}" class="text-indigo-600">Documenti</a>
          <a href="{{ route('admin.scraper.edit', $t) }}" class="text-emerald-600">Scraper</a>
          <a href="{{ route('admin.rag-config.show', $t) }}" class="text-purple-600">RAG Config</a>
          <a href="{{ route('admin.tenants.feedback.index', $t) }}" class="text-amber-600">📝 Feedback</a>
          <form method="post" action="{{ route('admin.tenants.destroy', $t) }}" onsubmit="return confirm('Eliminare?')">
            @csrf @method('delete')
            <button class="text-rose-600">Elimina</button>
          </form>
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
<div class="mt-4">{{ $tenants->links() }}</div>
@endsection

