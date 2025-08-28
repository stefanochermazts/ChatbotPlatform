# 📚 Documentazione ChatbotPlatform

## 🎯 **Documentazione Funzionalità Principali**

### **📖 Guide Complete alle Funzionalità**

| **Documento** | **Descrizione** | **Target** |
|---------------|-----------------|------------|
| **[`doc-widget.md`](./doc-widget.md)** | 🤖 **Widget Chatbot**: Configurazione, personalizzazione, theming, accessibilità, embedding e analytics | **Admin, Frontend Dev** |
| **[`doc-rag-tester.md`](./doc-rag-tester.md)** | 🧠 **RAG Tester**: Testing avanzato, debug, HyDE, conversation context, reranking intelligente | **Admin, RAG Engineer** |
| **[`doc-documenti.md`](./doc-documenti.md)** | 📄 **Gestione Documenti**: Upload, web scraping, ingestion, chunking, sincronizzazione Milvus | **Admin, Content Manager** |
| **[`doc-scraper.md`](./doc-scraper.md)** | 🕷️ **Web Scraper**: Architettura completa, configurazioni avanzate, multi-scraper, deduplicazione | **Admin, Content Manager** |
| **[`doc-clienti.md`](./doc-clienti.md)** | 👥 **Gestione Clienti**: Configurazioni tenant, RAG personalizzato, API keys, multi-KB | **Admin, Account Manager** |

### **📑 Descrizioni Tecniche Dettagliate**

| **Documento** | **Descrizione** | **Target** |
|---------------|-----------------|------------|
| **[`desc-widget.md`](./desc-widget.md)** | 🤖 **Widget Tecnico**: Architettura frontend, design system, accessibilità, performance, sicurezza | **Technical Lead, Frontend Architect** |
| **[`desc-rag-tester.md`](./desc-rag-tester.md)** | 🧠 **RAG Testing Avanzato**: Framework testing, analytics, optimization, workflow simulation | **AI/ML Engineer, Data Scientist** |
| **[`desc-documenti.md`](./desc-documenti.md)** | 📄 **Document Processing**: Pipeline ingestion, chunking, indicizzazione, sincronizzazione | **Data Engineer, Backend Architect** |
| **[`desc-scraper.md`](./desc-scraper.md)** | 🕷️ **Web Scraping Engine**: Crawler intelligente, extraction, deduplicazione, compliance | **Backend Engineer, Data Engineer** |
| **[`desc-clienti.md`](./desc-clienti.md)** | 👥 **Multitenant Platform**: Architettura tenant, security, scalabilità, business intelligence | **Platform Architect, DevOps Lead** |

---

## 🔧 **Documentazione Tecnica Specializzata**

### **Configurazioni Avanzate**
- **[`web-scraper-guide.md`](./web-scraper-guide.md)** - 🕷️ **Web Scraper Avanzato**: Best practices, troubleshooting, pattern complessi
- **[`milvus-partitions.md`](./milvus-partitions.md)** - 🗄️ **Milvus**: Partizioni, performance, troubleshooting
- **[`forms.md`](./forms.md)** - 📝 **Forms**: Configurazione e gestione form tenant

### **Implementazioni Tecniche**
- **[`conversation-context-implementation.md`](./conversation-context-implementation.md)** - 💬 **Conversation Context**: Implementazione memoria conversazionale
- **[`hyde-implementation.md`](./hyde-implementation.md)** - 🚀 **HyDE**: Hypothetical Document Embeddings
- **[`llm-reranking-implementation.md`](./llm-reranking-implementation.md)** - 🎯 **LLM Reranking**: Reranking intelligente
- **[`office-document-support.md`](./office-document-support.md)** - 📋 **Office Docs**: Supporto DOCX, XLSX, PPTX

### **Accessibilità e UX**
- **[`accessibility-testing-checklist.md`](./accessibility-testing-checklist.md)** - ♿ **Accessibility**: Checklist WCAG 2.1 AA
- **[`dark-mode-implementation.md`](./dark-mode-implementation.md)** - 🌙 **Dark Mode**: Implementazione e theming
- **[`error-handling-implementation.md`](./error-handling-implementation.md)** - ⚠️ **Error Handling**: Gestione errori UX

### **DevOps e Performance**
- **[`build-system-implementation.md`](./build-system-implementation.md)** - ⚙️ **Build System**: Vite, Asset pipeline
- **[`widget-analytics-dashboard.md`](./widget-analytics-dashboard.md)** - 📊 **Analytics**: Dashboard e metriche
- **[`milvus-windows-troubleshooting.md`](./milvus-windows-troubleshooting.md)** - 🪟 **Windows**: Setup Milvus development

---

## 🗂️ **Documentazione Architetturale**

### **Analisi Funzionale (Cartella `/docs`)**
- **[`../docs/analisi-funzionale/analisi-funzionale.md`](../docs/analisi-funzionale/analisi-funzionale.md)** - 📋 **Analisi Completa**: Requisiti, architettura, stack tecnologico
- **[`../docs/rag.md`](../docs/rag.md)** - 🧠 **RAG System**: Documentazione tecnica completa implementazione
- **[`../docs/scraper.md`](../docs/scraper.md)** - 🕷️ **Scraper**: Specifiche tecniche
- **[`../docs/api.md`](../docs/api.md)** - 🔌 **API**: Documentazione API e endpoint

---

## 🚀 **Quick Start per Ruolo**

### **👨‍💼 Admin/Manager**
1. **Setup Cliente**: [`doc-clienti.md`](./doc-clienti.md) - Configurazione tenant e RAG
2. **Caricamento Contenuti**: [`doc-documenti.md`](./doc-documenti.md) - Upload e gestione documenti
3. **Web Scraping**: [`doc-scraper.md`](./doc-scraper.md) - Acquisizione automatica contenuti web
4. **Configurazione Widget**: [`doc-widget.md`](./doc-widget.md) - Personalizzazione e deploy
5. **Testing Sistema**: [`doc-rag-tester.md`](./doc-rag-tester.md) - Verifica qualità risposte

### **👨‍💻 Developer**
1. **Architettura**: [`../docs/analisi-funzionale/analisi-funzionale.md`](../docs/analisi-funzionale/analisi-funzionale.md)
2. **RAG Implementation**: [`../docs/rag.md`](../docs/rag.md) + [`desc-rag-tester.md`](./desc-rag-tester.md)
3. **Widget Development**: [`desc-widget.md`](./desc-widget.md) + [`accessibility-testing-checklist.md`](./accessibility-testing-checklist.md)
4. **Document Processing**: [`desc-documenti.md`](./desc-documenti.md) + [`desc-scraper.md`](./desc-scraper.md)
5. **API Integration**: [`../docs/api.md`](../docs/api.md)

### **🏗️ Technical Lead/Platform Architect**
1. **Sistema Completo**: [`desc-clienti.md`](./desc-clienti.md) - Architettura multitenant e scalabilità
2. **Frontend Architecture**: [`desc-widget.md`](./desc-widget.md) - Design system e performance
3. **Data Processing**: [`desc-documenti.md`](./desc-documenti.md) + [`desc-scraper.md`](./desc-scraper.md)
4. **AI/ML Pipeline**: [`desc-rag-tester.md`](./desc-rag-tester.md) - Testing e optimization framework

### **🔧 DevOps/SysAdmin**
1. **Milvus Setup**: [`milvus-partitions.md`](./milvus-partitions.md) + [`milvus-windows-troubleshooting.md`](./milvus-windows-troubleshooting.md)
2. **Build & Deploy**: [`build-system-implementation.md`](./build-system-implementation.md)
3. **Monitoring**: [`widget-analytics-dashboard.md`](./widget-analytics-dashboard.md)
4. **Troubleshooting**: [`web-scraper-guide.md`](./web-scraper-guide.md) + [`error-handling-implementation.md`](./error-handling-implementation.md)

---

## 📋 **Changelog Documentazione**

### **v2.1 - Gennaio 2025**
- ✅ **Creati 5 documenti descrittivi tecnici**: Serie "desc-" per approfondimento architetturale
- ✅ **Aggiornato sistema navigazione**: Sezione Technical Lead/Platform Architect
- ✅ **Espansa copertura documentazione**: 10 documenti principali + documentazione tecnica specializzata

### **v2.0 - Gennaio 2025**
- ✅ **Creata documentazione funzionalità complete**: 5 guide principali
- ✅ **Rimossa documentazione obsoleta**: Quick start guide duplicate
- ✅ **Aggiornata documentazione tecnica**: Links e riferimenti incrociati
- ✅ **Creato indice navigazione**: README con guida per ruolo

### **v1.x - 2024**
- Documentazione tecnica implementazioni specifiche
- Guide setup e troubleshooting
- Analisi funzionale e architettura

---

## 🆘 **Supporto e Troubleshooting**

### **Problemi Comuni**
1. **Widget non funziona** → [`doc-widget.md`](./doc-widget.md) sezione troubleshooting
2. **Documenti non indicizzati** → [`doc-documenti.md`](./doc-documenti.md) sezione debug
3. **Scraping fallisce** → [`doc-scraper.md`](./doc-scraper.md) sezione troubleshooting
4. **RAG risponde male** → [`doc-rag-tester.md`](./doc-rag-tester.md) sezione ottimizzazione
5. **Cliente non configurato** → [`doc-clienti.md`](./doc-clienti.md) sezione setup

### **Debug Logging**
```bash
# Widget e frontend
tail -f storage/logs/laravel.log | grep -i widget

# RAG e retrieval  
tail -f storage/logs/laravel.log | grep -i "KbSearchService\|RagTestController"

# Documenti e ingestion
tail -f storage/logs/laravel.log | grep -i "ingestion\|IngestUploadedDocumentJob"

# Web scraping  
tail -f storage/logs/laravel.log | grep -i "scraping\|WebScraperService\|RunWebScrapingJob"

# Tenant e configurazioni
tail -f storage/logs/laravel.log | grep -i "TenantRagConfig"
```

---

**🔗 Per documentazione di sviluppo legacy vedere singoli file tecnici nella cartella**
