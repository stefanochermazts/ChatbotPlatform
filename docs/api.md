### API RAG – ChatbotPlatform (v1)

Base URL: `http://localhost:8000`

Prefisso versioning: `/api/v1`

### Autenticazione
- Admin (provisioning): header `X-Admin-Token: <ADMIN_TOKEN>`
- Tenant (runtime): header `Authorization: Bearer <API_KEY>`

Contenuti: `application/json` salvo upload documenti (multipart/form-data).

Errori: JSON `{ "message": string }` con HTTP 4xx/5xx.

---

### Admin – Provisioning

1) Crea tenant
- Metodo: POST `/api/v1/tenants`
- Header: `X-Admin-Token`
- Body JSON:
  - `name` (string, required)
  - `domain` (string, optional)
  - `plan` (string, optional; default `free`)
  - `metadata` (object, optional)
- Risposta: 201 JSON con `{ id, name, slug, domain, plan, metadata, created_at, updated_at }`
- Esempio:
```bash
curl -X POST http://127.0.0.1:8000/api/v1/tenants \
  -H "X-Admin-Token: <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"name":"Acme Corp","plan":"pro"}'
```

2) Associa utente a tenant (ruolo)
- Metodo: POST `/api/v1/tenants/{tenant}/users`
- Header: `X-Admin-Token`
- Body JSON:
  - `user_id` (int, required)
  - `role` (string, required)
- Risposta: 200 `{ "status": "ok" }`

3) Emetti API key per tenant
- Metodo: POST `/api/v1/tenants/{tenant}/api-keys`
- Header: `X-Admin-Token`
- Body JSON:
  - `name` (string, required)
  - `scopes` (array<string>, optional)
- Risposta: 201 `{ id, name, key, created_at }`  (la `key` è mostrata una sola volta)
- Esempio:
```bash
curl -X POST http://127.0.0.1:8000/api/v1/tenants/1/api-keys \
  -H "X-Admin-Token: <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"name":"Default key","scopes":["chat","kb:write"]}'
```

4) Revoca API key
- Metodo: DELETE `/api/v1/tenants/{tenant}/api-keys/{keyId}`
- Header: `X-Admin-Token`
- Risposta: 200 `{ "status": "revoked" }`

---

### Tenant – Documenti

5) Lista documenti
- Metodo: GET `/api/v1/documents`
- Header: `Authorization: Bearer <API_KEY>`
- Risposta: 200 paginator Laravel (`data`, `links`, `meta`, `total`, ...)
- Esempio:
```bash
curl http://127.0.0.1:8000/api/v1/documents \
  -H "Authorization: Bearer <API_KEY>"
```

6) Upload documento
- Metodo: POST `/api/v1/documents`
- Header: `Authorization: Bearer <API_KEY>`
- Content-Type: `multipart/form-data`
- Campi:
  - `title` (string, required)
  - `file` (file, required – es. pdf/docx/txt/md)
  - `metadata` (JSON serializzato opzionale)
- Risposta: 201 `{ id, tenant_id, title, source, path, metadata, ingestion_status, created_at, ... }`
- Note: viene messo in coda `IngestUploadedDocumentJob` per parsing/chunking/embeddings.
- Esempio:
```bash
curl -X POST http://127.0.0.1:8000/api/v1/documents \
  -H "Authorization: Bearer <API_KEY>" \
  -F "title=Manuale" \
  -F "file=@/percorso/locale/file.pdf"
```

6b) Upload multiplo (drag&drop)
- Metodo: POST `/api/v1/documents/batch`
- Header: `Authorization: Bearer <API_KEY>`
- Content-Type: `multipart/form-data`
- Campi:
  - `files[]` (file[], required) – massimo 200 file a chiamata
- Risposta: 207 Multi-Status
  - `created_count` (int)
  - `errors_count` (int)
  - `created` (array di documenti creati)
  - `errors` (array di `{ index, name, error }`)
- Esempio:
```bash
curl -X POST http://127.0.0.1:8000/api/v1/documents/batch \
  -H "Authorization: Bearer <API_KEY>" \
  -F "files[]=@/percorso/uno.pdf" \
  -F "files[]=@/percorso/due.docx" \
  -F "files[]=@/percorso/tre.txt"
```

7) Elimina documento
- Metodo: DELETE `/api/v1/documents/{document}`
- Header: `Authorization: Bearer <API_KEY>`
- Risposta: 200 `{ "status": "deleted" }`

---

### Tenant – Configurazione Scraper

8) Salva/Aggiorna configurazione scraper
- Metodo: POST `/api/v1/scraper/config`
- Header: `Authorization: Bearer <API_KEY>`
- Body JSON (campi principali):
  - `seed_urls` array<string>
  - `allowed_domains` array<string>
  - `max_depth` int
  - `render_js` bool (per siti SPA via rendering headless)
  - `auth_headers` object (per siti protetti)
  - `rate_limit_rps` int
  - `sitemap_urls` array<string>
  - `include_patterns` array<string>
  - `exclude_patterns` array<string>
  - `respect_robots` bool
- Risposta: 200 JSON con la configurazione salvata
- Esempio:
```bash
curl -X POST http://127.0.0.1:8000/api/v1/scraper/config \
  -H "Authorization: Bearer <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "seed_urls":["https://example.com"],
    "allowed_domains":["example.com"],
    "max_depth":2,
    "render_js":true,
    "rate_limit_rps":2,
    "include_patterns":["/docs"],
    "exclude_patterns":["/private"],
    "respect_robots":true
  }'
```

9) Leggi configurazione scraper
- Metodo: GET `/api/v1/scraper/config`
- Header: `Authorization: Bearer <API_KEY>`
- Risposta: 200 JSON (o `null` se non impostata)

---

### Tenant – Chat Completions (OpenAI‑like)

10) Crea completamento chat
- Metodo: POST `/api/v1/chat/completions`
- Header: `Authorization: Bearer <API_KEY>`
- Body JSON (subset OpenAI):
  - `model` (string, required)
  - `messages` (array, required) – es.: `[ {"role":"system","content":"..."}, {"role":"user","content":"..."} ]`
  - `temperature` (number, optional)
  - `tools`, `tool_choice`, `response_format`, `stream` (optional; `stream` attualmente non-SSE)
- Risposta: 200 OpenAI‑like `{ id, object, created, model, choices[], usage }` con estensione non‑breaking:
  - `citations`: array di `{ id, title, url, snippet? }` derivato dal retrieval RAG
- Esempio:
```bash
curl -X POST http://127.0.0.1:8000/api/v1/chat/completions \
  -H "Authorization: Bearer <API_KEY>" \
  -H "Content-Type: application/json" \
  -d '{
    "model":"gpt-4o-mini",
    "messages":[
      {"role":"system","content":"Sei un assistente."},
      {"role":"user","content":"Ciao! Fammi un riassunto del manuale."}
    ]
  }'
```

---

### Note operative
- Isolamento per tenant: tutte le operazioni lato runtime sono scoperte sull’API key del tenant.
- Upload: serve `php artisan storage:link` per esporre i file caricati da `storage/app/public`.
- Code: avviare un worker per ingestion `php artisan queue:work --queue=ingestion`.
- OpenAI: impostare `OPENAI_API_KEY` nello `.env` per risposte reali.


