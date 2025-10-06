# Guida alla Gestione dei Tag Git

## 📋 Indice

1. [Introduzione](#introduzione)
2. [Creazione Tag](#creazione-tag)
3. [Visualizzazione Tag](#visualizzazione-tag)
4. [Ripristino da Tag](#ripristino-da-tag)
5. [Gestione Tag Remoti](#gestione-tag-remoti)
6. [Schema di Versioning](#schema-di-versioning)
7. [Best Practices](#best-practices)
8. [Esempi Pratici](#esempi-pratici)

---

## Introduzione

I tag Git sono checkpoint immutabili che marcano punti specifici nella storia del progetto. Sono fondamentali per:

- ✅ Creare checkpoint sicuri prima di modifiche importanti
- ✅ Marcare versioni rilasciate in produzione
- ✅ Documentare milestone di sviluppo
- ✅ Facilitare rollback rapidi in caso di problemi

**Differenza tra Tag e Branch:**
- **Tag**: Puntatore immutabile a un commit specifico (come un segnalibro)
- **Branch**: Puntatore dinamico che si sposta con nuovi commit

---

## Creazione Tag

### Tag Annotato (Consigliato)

I tag annotati contengono metadata completi (autore, data, messaggio):

```bash
# Formato base
git tag -a <nome-tag> -m "Messaggio descrittivo"

# Esempio completo
git tag -a v1.0.0-pre-rag-optimization -m "Stable checkpoint before RAG optimizations

System ready:
- Horizon configured for parallel processing
- HorizonServiceProvider gate registration fixed
- Conversation context enhancement enabled
- Contact info expansion active

Next phase: RAG performance tuning"

# Tag su un commit specifico (invece che su HEAD)
git tag -a v1.0.0 <commit-hash> -m "Messaggio"
```

### Tag Lightweight (Uso Rapido)

Tag semplici senza metadata aggiuntivi:

```bash
# Crea tag senza messaggio
git tag checkpoint-quick

# Utile per checkpoint temporanei di debug
```

---

## Visualizzazione Tag

### Lista Tag

```bash
# Mostra tutti i tag
git tag

# Lista ordinata cronologicamente
git tag --sort=committerdate

# Mostra solo tag con pattern specifico
git tag -l "v1.*"
git tag -l "*pre-*"
git tag -l "*checkpoint*"
```

### Dettagli Tag

```bash
# Mostra informazioni complete del tag
git show v1.0.0-pre-rag-optimization

# Mostra solo il messaggio del tag
git tag -n99 v1.0.0-pre-rag-optimization

# Log con decorazione tag
git log --oneline --decorate --graph --all
```

### Trova il Tag più Recente

```bash
# Tag più recente
git describe --tags

# Tag più recente raggiungibile dal commit corrente
git describe --tags --abbrev=0

# Mostra distanza dall'ultimo tag
git describe --tags --long
```

---

## Ripristino da Tag

### Metodo 1: Ispezione (Non Distruttivo) ✅

Visualizza il codice senza modificare il branch corrente:

```bash
# Entra in modalità "detached HEAD"
git checkout v1.0.0-pre-rag-optimization

# Esplora i file
ls
cat backend/config/rag.php

# Torna al branch corrente
git checkout main
```

### Metodo 2: Branch dal Tag ✅ (Raccomandato)

Crea un nuovo branch per lavorare partendo dal tag:

```bash
# Crea branch dal tag
git checkout -b rag-optimization-attempt v1.0.0-pre-rag-optimization

# Lavora sul branch, fai commit...

# Se va bene: merge in main
git checkout main
git merge rag-optimization-attempt
git tag -a v1.1.0-rag-optimized -m "RAG optimizations completed"

# Se va male: cancella il branch e resta su main
git checkout main
git branch -D rag-optimization-attempt
```

### Metodo 3: Reset Hard ⚠️ (Distruttivo)

**⚠️ ATTENZIONE**: Cancella TUTTE le modifiche non committate!

```bash
# Verifica cosa stai per perdere
git log --oneline v1.0.0-pre-rag-optimization..HEAD

# Reset hard (cancella tutto dopo il tag)
git reset --hard v1.0.0-pre-rag-optimization

# Se hai già pushato, devi forzare
git push --force origin main
```

### Metodo 4: Revert Selettivo ✅

Annulla commit specifici mantenendo la storia:

```bash
# Trova i commit da annullare
git log --oneline v1.0.0-pre-rag-optimization..HEAD

# Revert di un commit specifico
git revert <commit-hash>

# Revert di un range di commit
git revert v1.0.0-pre-rag-optimization..HEAD
```

### Metodo 5: Crea Branch "Backup" Prima di Esperimenti

```bash
# Prima di modifiche rischiose
git branch backup-before-rag-opt

# Lavora normalmente su main
# ... modifiche ...

# Se va male
git reset --hard backup-before-rag-opt

# Se va bene, cancella il backup
git branch -d backup-before-rag-opt
```

---

## Gestione Tag Remoti

### Push Tag

```bash
# Pusha un tag specifico
git push origin v1.0.0-pre-rag-optimization

# Pusha TUTTI i tag locali
git push --tags

# Pusha tag e branch insieme
git push origin main --follow-tags
```

### Elimina Tag

```bash
# Elimina tag locale
git tag -d v1.0.0-pre-rag-optimization

# Elimina tag remoto (opzione 1)
git push origin --delete v1.0.0-pre-rag-optimization

# Elimina tag remoto (opzione 2)
git push origin :refs/tags/v1.0.0-pre-rag-optimization
```

### Aggiorna Tag Esistente

```bash
# Elimina il vecchio tag
git tag -d v1.0.0-pre-rag-optimization

# Ricrea il tag
git tag -a v1.0.0-pre-rag-optimization -m "Nuovo messaggio"

# Forza il push del tag aggiornato
git push --force origin v1.0.0-pre-rag-optimization
```

### Fetch Tag Remoti

```bash
# Scarica tutti i tag dal remote
git fetch --tags

# Fetch tag senza scaricare branch
git fetch --tags --no-recurse-submodules
```

---

## Schema di Versioning

### Semantic Versioning (SemVer) - Consigliato per Release

Formato: `vMAJOR.MINOR.PATCH[-descriptor]`

```bash
# Rilasci ufficiali
git tag -a v1.0.0 -m "First stable release"
git tag -a v1.1.0 -m "New feature: RAG optimization"
git tag -a v1.1.1 -m "Bugfix: Horizon auth issue"
git tag -a v2.0.0 -m "Breaking change: New API format"

# Pre-release
git tag -a v1.2.0-beta.1 -m "Beta release for testing"
git tag -a v1.2.0-rc.1 -m "Release candidate"

# Checkpoint intermedi
git tag -a v1.1.0-pre-optimization -m "Before performance work"
git tag -a v1.1.0-post-optimization -m "After performance work"
```

**Regole SemVer:**
- `MAJOR`: Breaking changes (API incompatibile)
- `MINOR`: Nuove feature retrocompatibili
- `PATCH`: Bugfix retrocompatibili

### Date-Based Checkpoints - Per Sviluppo

Formato: `checkpoint-YYYY-MM-DD-descriptor`

```bash
# Checkpoint giornalieri
git tag -a checkpoint-2025-10-06-pre-rag -m "Before RAG optimization"
git tag -a checkpoint-2025-10-07-post-rag -m "After RAG optimization"
git tag -a checkpoint-2025-10-10-stable -m "Stable before deploy"

# Con timestamp per checkpoint multipli nello stesso giorno
git tag -a checkpoint-2025-10-06-1430-scraper-fix -m "Before scraper refactor"
```

### Feature Milestones - Per Funzionalità

Formato: `stable-<feature-name>`

```bash
# Milestone di sviluppo
git tag -a stable-horizon-config -m "Horizon configuration completed"
git tag -a stable-rag-baseline -m "RAG baseline before optimization"
git tag -a stable-rag-optimized -m "RAG optimizations completed"
git tag -a stable-widget-v2 -m "Widget redesign completed"
```

### Ambiente-Specific Tags - Per Deploy

Formato: `<env>/<version>` o `deploy-<env>-<date>`

```bash
# Tag per ambiente
git tag -a production/v1.2.0 -m "Deployed to production"
git tag -a staging/v1.2.1-beta -m "Deployed to staging"

# Tag di deploy con data
git tag -a deploy-prod-2025-10-06 -m "Production deployment"
git tag -a deploy-staging-2025-10-05 -m "Staging deployment"
```

---

## Best Practices

### ✅ DO - Cosa Fare

1. **Crea tag annotati** per checkpoint importanti
   ```bash
   git tag -a v1.0.0 -m "Messaggio descrittivo"
   ```

2. **Descrivi lo stato del sistema** nel messaggio del tag
   ```bash
   git tag -a v1.0.0 -m "Features:
   - Feature A completata
   - Feature B testata
   - Bug X fixato
   
   Ready for: Optimization phase"
   ```

3. **Tagga prima di modifiche rischiose**
   ```bash
   # Prima di refactoring importanti
   git tag -a pre-refactor-rag -m "Before RAG refactor"
   ```

4. **Usa nomi consistenti** seguendo uno schema
   ```bash
   # Scegli uno schema e mantienilo
   v1.0.0, v1.1.0, v1.2.0  # SemVer
   # OPPURE
   checkpoint-2025-10-06, checkpoint-2025-10-07  # Date-based
   ```

5. **Pusha i tag dopo la creazione**
   ```bash
   git push origin v1.0.0
   ```

6. **Documenta i tag nel CHANGELOG**
   ```markdown
   ## [v1.0.0] - 2025-10-06
   ### Added
   - Feature X
   ```

### ❌ DON'T - Cosa Evitare

1. **Non usare tag lightweight per rilasci importanti**
   ```bash
   # ❌ Evita
   git tag v1.0.0
   
   # ✅ Preferisci
   git tag -a v1.0.0 -m "Release 1.0.0"
   ```

2. **Non modificare tag esistenti** (crea nuovi tag)
   ```bash
   # ❌ Non fare
   git tag -f v1.0.0
   
   # ✅ Fai invece
   git tag -a v1.0.1 -m "Corrected release"
   ```

3. **Non lasciare tag solo locali** per checkpoint importanti
   ```bash
   # Dopo aver taggato, ricorda di pushare
   git push origin <tag-name>
   ```

4. **Non usare nomi ambigui**
   ```bash
   # ❌ Evita
   git tag test
   git tag temp
   git tag fix
   
   # ✅ Preferisci
   git tag test-rag-performance-2025-10-06
   git tag checkpoint-before-scraper-refactor
   ```

---

## Esempi Pratici

### Scenario 1: Checkpoint Prima di Ottimizzazioni RAG

```bash
# 1. Verifica stato pulito
git status

# 2. Committa modifiche pending
git add -A
git commit -m "feat: Horizon optimization completed"

# 3. Crea tag checkpoint
git tag -a v1.0.0-pre-rag-optimization -m "Stable checkpoint before RAG optimizations

System ready:
- Horizon configured for parallel processing
- HorizonServiceProvider gate registration fixed
- Conversation context enhancement enabled
- Contact info expansion active

Next phase: RAG performance tuning"

# 4. Pusha commit e tag
git push origin main
git push origin v1.0.0-pre-rag-optimization

# 5. Verifica
git show v1.0.0-pre-rag-optimization
```

### Scenario 2: Rollback dopo Ottimizzazioni Fallite

```bash
# Situazione: Le ottimizzazioni RAG hanno peggiorato le prestazioni

# Opzione A: Reset hard (perde modifiche)
git reset --hard v1.0.0-pre-rag-optimization
git push --force origin main

# Opzione B: Branch di backup (mantiene modifiche)
git checkout -b rag-optimization-failed v1.0.0-pre-rag-optimization
git checkout main
git reset --hard v1.0.0-pre-rag-optimization
git push --force origin main

# Le modifiche fallite sono salvate nel branch per analisi
git checkout rag-optimization-failed
# Analizza cosa è andato storto...
```

### Scenario 3: Rilascio Graduale con Multiple Versioni

```bash
# 1. Baseline stabile
git tag -a v1.0.0-baseline -m "RAG baseline"

# 2. Prima ottimizzazione (HyDE)
git commit -m "feat: Enable HyDE for complex queries"
git tag -a v1.1.0-hyde-enabled -m "HyDE optimization"
git push origin v1.1.0-hyde-enabled

# Test in staging...

# 3. Seconda ottimizzazione (Reranker)
git commit -m "feat: Add LLM reranker"
git tag -a v1.2.0-llm-reranker -m "LLM reranking added"
git push origin v1.2.0-llm-reranker

# Test in staging...

# 4. Se v1.2.0 ha problemi, torna a v1.1.0
git reset --hard v1.1.0-hyde-enabled
git push --force origin main

# Deploy v1.1.0 in production
git tag -a production/v1.1.0 -m "Deployed to production"
```

### Scenario 4: Confronto tra Versioni

```bash
# Confronta due tag
git diff v1.0.0-baseline..v1.2.0-optimized

# Confronta file specifico
git diff v1.0.0-baseline..v1.2.0-optimized -- backend/config/rag.php

# Mostra commit tra due tag
git log v1.0.0-baseline..v1.2.0-optimized --oneline

# Mostra file modificati
git diff --name-only v1.0.0-baseline..v1.2.0-optimized
```

### Scenario 5: Tag su Commit Precedente

```bash
# Hai dimenticato di taggare un commit importante

# Trova il commit hash
git log --oneline

# Crea tag su quel commit
git tag -a v1.0.0-stable <commit-hash> -m "Stable release"

# Pusha il tag
git push origin v1.0.0-stable
```

---

## Comandi Rapidi di Riferimento

### Creazione e Gestione

```bash
# Crea tag annotato
git tag -a <nome> -m "messaggio"

# Crea tag su commit specifico
git tag -a <nome> <commit-hash> -m "messaggio"

# Lista tutti i tag
git tag

# Mostra dettagli tag
git show <nome-tag>

# Elimina tag locale
git tag -d <nome-tag>
```

### Remote

```bash
# Pusha tag specifico
git push origin <nome-tag>

# Pusha tutti i tag
git push --tags

# Elimina tag remoto
git push origin --delete <nome-tag>

# Scarica tag dal remote
git fetch --tags
```

### Ripristino

```bash
# Ispeziona tag
git checkout <nome-tag>

# Crea branch da tag
git checkout -b <nome-branch> <nome-tag>

# Reset hard a tag
git reset --hard <nome-tag>

# Revert a tag
git revert <nome-tag>..HEAD
```

### Ricerca e Filtro

```bash
# Tag con pattern
git tag -l "v1.*"

# Tag più recente
git describe --tags --abbrev=0

# Log con tag
git log --oneline --decorate --graph

# Commit tra tag
git log <tag1>..<tag2> --oneline
```

---

## Workflow Consigliato per il Progetto ChatbotPlatform

### 1. Tag di Checkpoint (Prima di Modifiche Importanti)

```bash
# Formato: v<MAJOR>.<MINOR>.<PATCH>-pre-<feature>
git tag -a v1.0.0-pre-rag-optimization -m "Checkpoint before RAG optimization"
git tag -a v1.0.0-pre-widget-redesign -m "Checkpoint before widget redesign"
```

### 2. Tag di Feature Completata

```bash
# Formato: v<MAJOR>.<MINOR>.<PATCH>-<feature>
git tag -a v1.1.0-rag-optimized -m "RAG optimization completed and tested"
git tag -a v1.2.0-widget-v2 -m "Widget redesign completed"
```

### 3. Tag di Release Stabile

```bash
# Formato: v<MAJOR>.<MINOR>.<PATCH>
git tag -a v1.1.0 -m "Stable release with RAG optimizations"
```

### 4. Tag di Deploy Produzione

```bash
# Formato: production/v<version> o deploy-prod-<date>
git tag -a production/v1.1.0 -m "Deployed to production"
git tag -a deploy-prod-2025-10-06 -m "Production deployment"
```

---

## FAQ

### Q: Quando dovrei creare un tag?

**A:** Crea un tag quando:
- ✅ Prima di modifiche rischiose o refactoring importanti
- ✅ Al completamento di una feature importante
- ✅ Prima del deploy in produzione
- ✅ Quando raggiungi uno stato stabile che vuoi poter recuperare

### Q: Tag annotato vs lightweight: quale usare?

**A:** 
- **Tag annotato** (`-a`): Per checkpoint importanti, rilasci, milestone
- **Tag lightweight**: Solo per marker temporanei di debug

### Q: Posso modificare un tag esistente?

**A:** Tecnicamente sì con `-f`, ma **NON dovresti**. I tag dovrebbero essere immutabili. Se serve una correzione, crea un nuovo tag (es. `v1.0.1` invece di modificare `v1.0.0`).

### Q: Come elimino un tag già pushato?

**A:**
```bash
# Locale
git tag -d <nome-tag>

# Remoto
git push origin --delete <nome-tag>
```

### Q: Come trovo il tag più vicino al commit corrente?

**A:**
```bash
git describe --tags --abbrev=0
```

### Q: Posso fare checkout di un tag e lavorarci?

**A:** Sì, ma finisci in "detached HEAD" state. Meglio creare un branch:
```bash
git checkout -b nuovo-branch <nome-tag>
```

---

## Riferimenti

- [Git Tag Documentation](https://git-scm.com/docs/git-tag)
- [Semantic Versioning](https://semver.org/)
- [Git Best Practices](https://www.git-tower.com/learn/git/ebook/en/command-line/appendix/best-practices)

---

**Documento creato**: 2025-10-06  
**Ultima modifica**: 2025-10-06  
**Versione**: 1.0.0

