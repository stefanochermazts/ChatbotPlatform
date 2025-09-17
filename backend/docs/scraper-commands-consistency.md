# 📋 Coerenza Comandi Scraper con Configurazione Tenant

## **✅ RIEPILOGO COMPLETO**

Tutti i comandi di scraping ora utilizzano correttamente la **configurazione dello scraper del tenant**, inclusi i **pattern di estrazione personalizzati**.

---

## **🎯 Comandi Scraper e Configurazione Tenant**

### **1. 🚀 `scraper:progressive {tenant_id}`**
**Status**: ✅ **CONFORME**  
**Configurazione**: Usa `ScraperConfig::where('tenant_id', $tenantId)->first()`  
**Pattern personalizzati**: ✅ **SÌ** - Via `WebScraperService->scrapeForTenant()`

```bash
php artisan scraper:progressive 9 --max-urls=5
```

### **2. 🧪 `scraper:test-single-url {tenant_id} {url}`**
**Status**: ✅ **CONFORME**  
**Configurazione**: Usa `scrapeSingleUrl($tenantId, $url)` → config tenant  
**Pattern personalizzati**: ✅ **SÌ** - Via `scrapeSingleUrlInternal()`

```bash
php artisan scraper:test-single-url 9 "https://www.comune.palmanova.ud.it/page"
```

### **3. 🔍 `scraper:preview-plan {tenant_id}`**
**Status**: ✅ **CONFORME**  
**Configurazione**: Usa `ScraperConfig::where('tenant_id', $tenantId)->first()`  
**Pattern personalizzati**: N/A (solo preview, non estrae contenuto)

```bash
php artisan scraper:preview-plan 9
```

### **4. 📊 `scraper:monitor {tenant_id}`**
**Status**: ✅ **CONFORME**  
**Configurazione**: Filtra documenti per `tenant_id`  
**Pattern personalizzati**: N/A (solo monitoring)

```bash
php artisan scraper:monitor 9
```

### **5. 🔧 `scraper:debug-markdown {tenant_id} {url}`**
**Status**: ✅ **AGGIORNATO** ← **FIXED**  
**Configurazione**: Ora usa config tenant + pattern personalizzati  
**Pattern personalizzati**: ✅ **SÌ** - Via reflection con `currentConfig`

```bash
php artisan scraper:debug-markdown 9 "https://www.comune.palmanova.ud.it/page"
```

### **6. 🖥️ `scraper:debug-palmanova {url} {--tenant=}`**
**Status**: ✅ **AGGIORNATO** ← **ENHANCED**  
**Configurazione**: Solo rendering JS (opzionale tenant per contesto)  
**Pattern personalizzati**: N/A (solo JavaScript rendering)

```bash
php artisan scraper:debug-palmanova "https://www.comune.palmanova.ud.it/page" --tenant=9
```

---

## **🎯 FLUSSO PATTERN PERSONALIZZATI**

### **Come Funziona l'Estrazione**

1. **🔄 Load Config**: `WebScraperService` carica `ScraperConfig` del tenant
2. **🎯 Pattern Priority**:
   - **Tenant patterns** (da `extraction_patterns` JSON)
   - **Global patterns** (da `config/scraper-patterns.php`)  
   - **Fallback** (Readability.php)
3. **⚡ Smart Extraction**: `trySmartContentExtraction()` testa pattern in ordine
4. **✅ Success**: Primo pattern che trova contenuto > `min_length`

### **Metodi Chiave**

```php
// 1. WebScraperService carica config tenant
$this->currentConfig = ScraperConfig::where('tenant_id', $tenantId)->first();

// 2. getTenantExtractionPatterns() legge extraction_patterns
if (!$this->currentConfig || empty($this->currentConfig->extraction_patterns)) {
    return [];
}
return $this->currentConfig->extraction_patterns;

// 3. trySmartContentExtraction() usa pattern tenant + globali
$tenantPatterns = $this->getTenantExtractionPatterns();
$contentPatterns = array_merge($tenantPatterns, $globalPatterns);
```

---

## **🧪 Test di Verifica**

### **Test Pattern Personalizzati**

```bash
# 1. Configura pattern nella UI Admin
# Admin → Tenants → Scraper → Pattern di Estrazione Personalizzati

# 2. Testa con debug command
php artisan scraper:debug-markdown 9 "https://www.comune.palmanova.ud.it/servizi/pedibus"

# Output atteso:
# ✅ Using tenant scraper config: Palmanova Scraper
# 🎯 Custom extraction patterns found: 2
```

### **Test Scraping Completo**

```bash
# Test con pattern personalizzati
php artisan scraper:progressive 9 --max-urls=3 --test-mode

# Output atteso:
# 🎯 Using tenant-specific patterns
# 🎯 Smart extraction successful
```

---

## **⚠️ COSA VERIFICARE**

### **Se Pattern Non Funzionano**

1. **Check Config Tenant**:
   ```bash
   php artisan tinker --execute="
   \$config = App\Models\ScraperConfig::where('tenant_id', 9)->first();
   echo 'Patterns: ' . json_encode(\$config->extraction_patterns, JSON_PRETTY_PRINT);
   "
   ```

2. **Check Log Pattern**:
   ```bash
   tail -f storage/logs/laravel.log | grep -E "(tenant-specific|Smart extraction|pattern)"
   ```

3. **Validation JSON**:
   - Verifica sintassi JSON nell'admin UI
   - Controlla che regex siano valide
   - Assicurati che `min_length` sia ragionevole

### **Se Comando Fallisce**

1. **Verifica Parametri**:
   ```bash
   # ❌ WRONG (manca tenant_id)
   php artisan scraper:debug-markdown "https://example.com"
   
   # ✅ CORRECT
   php artisan scraper:debug-markdown 9 "https://example.com"
   ```

2. **Check Tenant Exists**:
   ```bash
   php artisan tinker --execute="
   echo App\Models\Tenant::find(9) ? 'Tenant exists' : 'Tenant not found';
   "
   ```

---

## **📋 CHECKLIST FINALE**

- ✅ **Tutti i comandi** usano configurazione tenant
- ✅ **Pattern personalizzati** funzionano in tutti i flussi
- ✅ **Fallback automatico** ai pattern globali
- ✅ **Logging dettagliato** per debugging
- ✅ **Backward compatibility** mantenuta
- ✅ **Error handling** robusto per pattern non validi

---

## **🎉 CONFERMA**

**SÌ, tutti i comandi usano la configurazione dello scraper inserita a livello di cliente!**

I pattern di estrazione personalizzati del tenant hanno **priorità assoluta** su quelli globali, garantendo:

- 🎯 **Personalizzazione Completa** per ogni cliente
- ⚡ **Performance Ottimizzate** (pattern tenant testati per primi)
- 🛡️ **Fallback Sicuri** ai pattern globali
- 📊 **Monitoring Dettagliato** di quale pattern viene utilizzato

**Non c'è rischio che "qualcosa funzioni e qualcosa no" - il sistema è completamente coerente!** ✅
