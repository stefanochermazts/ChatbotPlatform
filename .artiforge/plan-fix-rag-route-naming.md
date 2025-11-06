# Piano di Sviluppo: Fix RAG Route Naming Issue

**Task**: Il RAG tester non funziona a causa dell'errore "Route [admin.tenants.rag-config.show] not defined"

**Problema Identificato**: Inconsistenza tra i nomi delle route definiti in `routes/web.php` (es. `rag-config.show`) e quelli usati nelle view (es. `admin.tenants.rag-config.show`)

---

## Step 1: Backup dei File Coinvolti

### Azione
Creare un backup temporaneo dei file coinvolti (routes/web.php e tutte le view sotto resources/views/admin) per garantire reversibilità.

### Reasoning
Prima di modificare il codice di produzione, è buona pratica avere una copia di sicurezza in caso di errori di merge o refusi.

### Implementation Details
```bash
cp routes/web.php routes/web.php.bak
cp -r resources/views/admin resources/views/admin.bak
```

Commit temporaneo opzionale:
```bash
git add routes/web.php resources/views/admin
git commit -m "Backup before fixing RAG route naming"
```

### Error Handling
- Verificare che il comando `cp` non fallisca per permessi insufficienti
- In caso di errore, controllare i permessi di scrittura sulla directory del progetto

### Testing
- Controllare che i file .bak esistano e contengano lo stesso contenuto dei file originali (diff)

---

## Step 2: Allineare il Nome della Route in routes/web.php

### Azione
Allineare il nome della route RAG a `admin.tenants.rag-config.show` nel file `routes/web.php`.

### Reasoning
Il bug nasce da una discrepanza tra il nome definito nella route (`rag-config.show`) e il nome usato nelle view (`admin.tenants.rag-config.show`). Uniformare il nome risolve l'eccezione "Route … not defined".

### Implementation Details

1. Aprire `routes/web.php`
2. Individuare le definizioni relative al RAG (linee 206-210)
3. Modificare i nomi delle route da:
   - `rag-config.show` → `tenants.rag-config.show`
   - `rag-config.update` → `tenants.rag-config.update`
   - `rag-config.reset` → `tenants.rag-config.reset`
   - `rag-config.test` → `tenants.rag-config.test`
   
4. Eseguire `php artisan route:clear` per rimuovere la cache delle rotte

### Error Handling
- Se la sintassi causa conflitti con altre rotte, verificare che i prefissi non duplicano già esistenti
- Dopo la modifica, eseguire `php artisan route:list | grep rag-config` per assicurarsi che il nuovo nome sia presente

### Testing
- Test manuale: aprire il browser e navigare all'URL della configurazione RAG
- L'errore di route non deve più comparire

---

## Step 3: Aggiornare Riferimenti nelle View Blade

### Azione
Aggiornare tutti i riferimenti al nome della route nelle view Blade (`resources/views/admin/**`).

### Reasoning
Le view continuano a chiamare `route('rag-config.show')` o varianti; devono usare il nuovo nome coerente per non generare errori nella generazione dei link.

### Implementation Details

1. Eseguire una ricerca globale:
   ```bash
   grep -R "route(['\"]rag-config.show['\"]" -n resources/views/admin
   ```

2. Nei file trovati sostituire:
   - `backend/resources/views/admin/rag/index.blade.php:458`
   - `backend/resources/views/admin/tenants/index.blade.php:30`
   - `backend/resources/views/admin/tenants/edit.blade.php:15`

3. Verificare che i parametri passati corrispondano alla definizione della route

### Error Handling
- Assicurarsi che il parametro `$tenant` sia definito nella view
- Dopo le sostituzioni, lanciare `php artisan view:clear` per rimuovere cache Blade

### Testing
- Aprire le pagine interessate nel browser e verificare che i link generati puntino agli URL corretti

---

## Step 4: Aggiornare Riferimenti nei Controller/Service

### Azione
Aggiornare eventuali riferimenti al nome della route all'interno dei controller, service o middleware.

### Reasoning
Oltre alle view, il codice PHP potrebbe utilizzare `route('rag-config.show')` per redirect o generazione di URL.

### Implementation Details

1. Ricerca nel codice PHP:
   ```bash
   grep -R "route(['\"]rag-config" -n app/Http
   ```

2. Nei file trovati, sostituire con il nuovo nome:
   ```php
   return redirect()->route('admin.tenants.rag-config.show', $tenant);
   ```

### Error Handling
- Verificare che le variabili passate al route() corrispondano ai parametri richiesti
- Dopo le modifiche, eseguire `php artisan route:list` per confermare che la route esista

### Testing
- Eseguire tutti i test (`php artisan test`) e correggere eventuali fallimenti

---

## Step 5: Flush Cache Route e View

### Azione
Eseguire il flush della cache delle route e delle view, quindi verificare il corretto caricamento delle rotte.

### Reasoning
Le modifiche ai nomi delle route possono rimanere in cache; il flush garantisce che Laravel utilizzi le nuove definizioni.

### Implementation Details
```bash
php artisan route:clear
php artisan view:clear
php artisan config:cache   # opzionale
```

Dopo il flush, controllare:
```bash
php artisan route:list | grep rag-config
```

### Error Handling
- Se la route non appare, ricontrollare il file `routes/web.php` per eventuali errori di sintassi

### Testing
- Aprire nuovamente le pagine del tester RAG; l'errore "Route not defined" non deve più comparire

---

## Step 6: Aggiungere Test Pest

### Azione
Aggiungere e/o aggiornare i test Pest per garantire la presenza della route e il corretto rendering delle view.

### Reasoning
Un test automatizzato previene regressioni future sul naming delle route.

### Implementation Details

Creare file `tests/Feature/RagRouteTest.php`:
```php
<?php

use App\Models\Tenant;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('has the correct rag-config route name', function () {
    expect(route('admin.tenants.rag-config.show', $this->tenant))
        ->toBeString()
        ->and(route('admin.tenants.rag-config.show', $this->tenant))
        ->toContain("/tenants/{$this->tenant->id}/rag-config");
});

it('displays the rag-config page without errors', function () {
    $response = get(route('admin.tenants.rag-config.show', $this->tenant));
    $response->assertOk();
});
```

### Testing
- Tutti i test devono passare (`0 failures`)

---

## Step 7: Laravel Pint per PSR-12

### Azione
Eseguire Laravel Pint per garantire la conformità a PSR‑12.

### Implementation Details
```bash
./vendor/bin/pint --test
```

Se vengono segnalati problemi:
```bash
./vendor/bin/pint
```

### Testing
- Dopo Pint, rieseguire l'intera suite di test

---

## Step 8: Aggiornare Documentazione

### Azione
Aggiornare la documentazione per riflettere il nuovo naming della route RAG.

### Implementation Details

Aggiornare la sezione Routes nella documentazione:

| Nome Route                       | URI                                   | Metodo |
|----------------------------------|---------------------------------------|--------|
| admin.tenants.rag-config.show   | /admin/tenants/{tenant}/rag-config    | GET    |
| admin.tenants.rag-config.update | /admin/tenants/{tenant}/rag-config    | POST   |
| admin.tenants.rag-config.reset  | /admin/tenants/{tenant}/rag-config    | DELETE |
| admin.tenants.rag-config.test   | /admin/tenants/{tenant}/rag-config/test | POST |

---

## Step 9: Rimuovere File di Backup

### Azione
Rimuovere i file di backup creati in step 1 (opzionale, una volta verificata la correttezza).

### Implementation Details
```bash
rm routes/web.php.bak
rm -rf resources/views/admin.bak
```

---

## Summary

**File da Modificare**:
1. `backend/routes/web.php` - Aggiornare nomi route (linee 206-210)
2. `backend/resources/views/admin/rag/index.blade.php` - Linea 458
3. `backend/resources/views/admin/tenants/index.blade.php` - Linea 30
4. `backend/resources/views/admin/tenants/edit.blade.php` - Linea 15

**Pattern Corretto**:
- Da: `name('rag-config.show')`
- A: `name('tenants.rag-config.show')`

**Risultato Atteso**:
- Route name finale: `admin.tenants.rag-config.show` (il prefisso `admin.` viene aggiunto dal gruppo route)
- Nessun errore "Route not defined" nel RAG tester

