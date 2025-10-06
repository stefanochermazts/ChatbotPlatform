# RAG Performance Optimization Plan
**ChatbotPlatform - Latency, Throughput & Cost Improvement**

**Generated**: 2025-10-06  
**Target**: P95 latency ≤ 2.5s, maintain/improve recall & groundedness, reduce cost

---

## Overview

This plan provides 15 prioritized steps to optimize the RAG pipeline without compromising quality or introducing breaking changes.

### Goals
- ✅ Reduce end-to-end P95 chat latency toward ≤ 2.5s
- ✅ Maintain or improve recall@K and groundedness
- ✅ Eliminate ingestion/queue bottlenecks
- ✅ Reduce reprocessing and waste
- ✅ Improve observability (metrics/traces/logs)
- ✅ Add controllability (feature flags, tunables)

### Non-Goals
- ❌ No big rewrites or vendor lock-in
- ❌ Don't remove safety (tenant isolation, ACLs, PII redaction)
- ❌ Maintain OpenAI API compatibility

---

## Step 1: Establish Baseline Metrics

**Action**: Establish a baseline for end‑to‑end chat latency, throughput, and cost.

**Reasoning**: Without a reliable baseline we cannot quantify improvement. Capturing P95 latency, token usage, embedding cost, and queue lag gives concrete targets and helps identify the biggest contributors to latency.

**Implementation**:
- Add Laravel middleware (`App\Http\Middleware\LatencyMetrics`) for request timing
- Push metrics to Prometheus/StatsD with labels: `tenant_id`, `endpoint`, `status`
- Store short-term snapshot in Redis (`latency:chat:{tenant_id}`)
- Log JSON to `storage/logs/latency.log` with `correlation_id`
- Run synthetic load test with k6 (20 VUs, 2min duration)
- Record queue lag via Horizon API

**Error Handling**:
- Record latency even on API failures (tag with `error=true`)
- Generate UUID if `X-Request-ID` missing
- Ensure middleware overhead < 5ms

**Testing**:
- Unit test middleware timestamps and labels
- Mock OpenAI client to verify cost recording
- Verify Prometheus counters via `/metrics` endpoint

**💡 Tip**: Wrap in feature flag (`feature:latency_metrics`) for production rollout

**❓ Question**: Do you have existing Prometheus/StatsD collector?

---

## Step 2: Distributed Tracing & Fine-Grained Metrics

**Action**: Instrument full RAG pipeline with OpenTelemetry distributed tracing.

**Reasoning**: Latency accumulates across many services. Tracing pinpoints the slowest stage and verifies optimization impact.

**Implementation**:
- Add OpenTelemetry PHP SDK with Jaeger/Zipkin exporter
- Create spans for each job class (`RunWebScrapingJob`, `GenerateEmbeddingsJob`)
- Propagate `traceparent` header through SSE streams
- Add custom attributes: `document_id`, `chunk_count`, `embedding_batch_size`, `vector_index_probe`
- Export queue lag, retry count, backoff duration metrics

**Error Handling**:
- Fallback to no-op tracer if exporter unavailable
- Try/catch span creation, log to `storage/logs/otel_error.log`

**Testing**:
- Unit test span creation with expected attributes
- E2E test: verify chat request produces 5+ span hierarchy in Jaeger

**💡 Tip**: Group related spans under parent (e.g., "Parsing") for readability

**❓ Question**: Which tracing backend (Jaeger, Zipkin, Lightstep)?

---

## Step 3: Optimize Web Scraper

**Action**: Add concurrency, caching, and conditional requests to scraper.

**Reasoning**: Scraping is single-threaded bottleneck. Parallelism and HTTP caching dramatically cut latency and cost.

**Implementation**:
- Refactor `WebScraperService::scrape()` with Guzzle async pool (concurrency: 8)
- ETag/Last-Modified support: store in DB, send conditional headers
- Redis URL deduplication set (`scraper:seen:{tenant_id}`, TTL 24h)
- Per-tenant robots.txt parser (`spatie/robots.txt`), respect crawl-delay
- Feature flag for JS rendering (`feature:js_rendering`), Puppeteer timeout 8s
- Store raw HTML/markdown in `scraped_contents` table

**Error Handling**:
- HTTP 429/5xx: exponential backoff (2s → 60s max)
- Puppeteer crash: fallback to plain HTML extraction
- Async pool handles individual failures gracefully

**Testing**:
- Mock 304 Not Modified responses
- Integration test async pool with varied status codes
- Load test 100 URLs, verify parallelism

**💡 Tip**: Cache robots.txt per domain for 12h

---

## Step 4: Cache & Parallelize Document Parsing

**Action**: Cache parsed Markdown and run parsing jobs in parallel.

**Reasoning**: Parsing large binaries is CPU-intensive and often repeated, inflating latency.

**Implementation**:
- `ParserCacheService`: SHA-256 hash of file → cached markdown in Redis
- Check cache before invoking `smalot/pdfparser`, `phpoffice/phpword`
- Parallel parsing with Laravel Octane task workers or separate queue jobs
- PDFs: selective page extraction (detect image-only pages with `pdfinfo`)
- Tables: feature flag (`feature:table_extraction`), limit to first 5 tables

**Error Handling**:
- Parser exception: mark as `parse_failed`, continue with other files
- Hash collision: re-parse and overwrite cache

**Testing**:
- Unit test cache key generation
- Benchmark 50-page PDF parsing with/without cache (>50% reduction)
- Verify feature flag disables table extraction

**💡 Tip**: Store markdown size in cache metadata for quick re-parse decisions

---

## Step 5: Standardize Chunking Parameters

**Action**: Token-based chunking with deterministic IDs.

**Reasoning**: Inconsistent chunk sizes cause variable token counts, affecting retrieval quality and wasting LLM calls.

**Implementation**:
- Centralize in `ChunkerService.php` with `config/rag.php` values
- Use `tiktoken-php` for token-based splitting (max 500 tokens, overlap 100)
- Deterministic chunk IDs: `hash_hmac('sha256', $document_id . $offset, app.key)`
- Store `chunk_hash` in `document_chunks`, skip embedding if match
- Discard chunks < 20 tokens

**Error Handling**:
- Token estimation fails: fallback to character-based (max 2000 chars)

**Testing**:
- Unit test 3000-token doc yields correct chunk count with overlap
- Verify identical documents → identical chunk IDs
- Load test 10k-page corpus, ensure <200ms per document

**💡 Tip**: Per-tenant overrides in `rag-tenant-defaults.php`

---

## Step 6: Batch Embeddings & Align with Rate Limits

**Action**: Batch embedding requests, add caching, align concurrency with OpenAI limits.

**Reasoning**: Embedding calls dominate cost and latency. Batching reduces overhead, caching prevents re-embedding.

**Implementation**:
- Batch up to 100 chunks per OpenAI request (`text-embedding-3-large`)
- Exponential backoff on 429/500 (1s → 32s max)
- Store `embedding_vector` + `embedding_hash` in `document_chunks`
- Redis LRU cache (`embeddings:hash:{hash}`) for hot chunks
- Align concurrency with `x-ratelimit-remaining` header via Horizon queue config

**Error Handling**:
- Batch fails after 5 retries: mark `embedding_failed`, nightly reprocess job
- Validate response vector dimension

**Testing**:
- Mock OpenAI: 100 chunks → single HTTP request
- Simulate 429, verify backoff respects `retry-after`
- Load test 10k chunks embedding time

**💡 Tip**: Feature flag `embedding_dry_run` for capacity planning

---

## Step 7: Tune pgvector Index Parameters

**Action**: Optimize IVF list/probe counts, maintain healthy statistics.

**Reasoning**: Mis-configured vector indexes cause unnecessary scans, raising latency.

**Implementation**:
- Re-create index: `USING ivfflat (embedding_vector) WITH (lists = 100)`
- Set `probes = 10` (adjustable per tenant)
- GIN index on `text_search` for BM25
- Nightly `ANALYZE` and `VACUUM` via Horizon job
- Feature flags for `vector_lists`, `vector_probes` A/B testing

**Error Handling**:
- Wrap index recreation in transaction, revert on failure
- Monitor `pg_stat_user_indexes`, alert if >30% seq scans

**Testing**:
- Benchmark k=10 query on 1M chunks before/after (≤30% reduction)
- Verify GIN improves BM25 latency

**💡 Tip**: Consider `hnsw` index if PostgreSQL 17 supports it

---

## Step 8: Adjust Hybrid Retriever Weighting

**Action**: Tune K1/K2, α (vector weight), MMR λ, add selective filters.

**Reasoning**: Fine-tuning balance improves recall without increasing token budget. Filters reduce candidate set size.

**Implementation**:
- Expose in `config/rag.php`: `RETRIEVER_K1` (50), `RETRIEVER_K2` (100), `RETRIEVER_ALPHA` (0.7), `MMR_LAMBDA` (0.3)
- Apply filters (tenant_id, collection, language, tags, date) before vector search
- Two-stage retrieval: K1 vectors → BM25 re-rank → MMR → final K (10)
- Cache combined scores for 60s in Redis

**Error Handling**:
- <5 vector results: fallback to pure BM25
- Validate filter inputs in request DTO

**Testing**:
- Unit test varying α shifts ranking
- A/B test 500 queries, measure recall@10 and latency
- Verify filter restrictions

**💡 Tip**: Admin UI to experiment with α and λ in real time

---

## Step 9: Review Reranker Cost/Benefit

**Action**: Optionally disable reranker to save compute.

**Reasoning**: Cross-encoder rerankers are CPU/GPU intensive. If recall is satisfactory, disabling reduces latency.

**Implementation**:
- Wrap `RerankerService::rerank()` in feature flag `feature:reranker_enabled`
- Cache layer: `hash(query + candidate_ids)`, TTL 5min

**Error Handling**:
- Reranker exception: log, disable for request, continue with pre-ranked list

**Testing**:
- Benchmark query with/without reranker (≥200ms improvement)
- Verify cache returns same ordering

**💡 Tip**: Enable only for high-value tenants with >5% relevance improvement

---

## Step 10: Refine Context Builder

**Action**: Token budget enforcement, citation deduplication.

**Reasoning**: Over-filling context leads to truncation, increasing latency and reducing quality.

**Implementation**:
- Compute remaining token budget (4096 - system_prompt - user_message)
- Sort chunks by score, add until budget exhausted
- Deduplicate source URLs/headings before adding
- Append citation block (`[^1]: https://...`)
- Emit metrics: `context_chunks_used`, `context_tokens_used`

**Error Handling**:
- No chunks fit: "search-only" mode fallback

**Testing**:
- Unit test budget enforcement
- Verify duplicate URL deduplication
- Load test 100 concurrent requests, <30ms context building

**💡 Tip**: Per-tenant token budget config for premium customers

---

## Step 11: Scale & Prioritize Queues

**Action**: Dedicated queues, worker sizing, backoff policies, lag monitoring.

**Reasoning**: Queue congestion is hidden latency source. Proper configuration keeps pipeline flowing.

**Implementation**:
- Define queues in `config/horizon.php`: `scraping`, `parsing`, `embedding`, `indexing`
- Allocate workers: 2 scraping (IO-bound), 4 embedding (CPU-bound), 2 parsing
- Set `retry_after` and `backoff` per queue
- Enable Horizon "balance" auto-scaling
- Redis key `queue:lag:{queue}` updated per minute, expose as Prometheus gauge

**Error Handling**:
- Lag >30s: trigger alert, spin up extra workers via Supervisor
- Failed jobs → `failed` queue with detailed logs

**Testing**:
- Burst 500 documents, verify lag <threshold
- Test retry backoff and eventual fail queue routing

**💡 Tip**: Laravel Octane for API layer to reduce bootstrapping overhead

---

## Step 12: Centralized Feature Flag System

**Action**: Install `spatie/laravel-feature-flags`, replace hard-coded conditionals.

**Reasoning**: Toggle heavy components without redeploying enables rapid experimentation.

**Implementation**:
- Define flags: `js_rendering`, `table_extraction`, `reranker_enabled`, `embedding_dry_run`, `large_context`
- Replace conditionals with `Feature::isEnabled()` checks
- Admin UI (Livewire) for per-tenant flag management

**Error Handling**:
- Default all flags to "enabled" for backward compatibility

**Testing**:
- Mock `Feature::isEnabled()` to verify code paths
- Integration test: `js_rendering` off → Puppeteer skipped

**💡 Tip**: Audit columns in `feature_flags` table to track changes

---

## Step 13: Cost-Tracking Dashboards & Alerts

**Action**: Track OpenAI costs, create Grafana dashboards, set alerts.

**Reasoning**: Visibility enables proactive cost control.

**Implementation**:
- Extend latency middleware: emit `openai_tokens_used`, `openai_cost_usd`
- Store daily aggregates in `costs` table
- Grafana dashboard: P95 latency, cost per request, embeddings cost, queue lag
- Alertmanager thresholds (e.g., cost/day >$500)

**Error Handling**:
- Missing token usage: fallback to token estimator

**Testing**:
- Simulate 100 requests, verify daily aggregate
- Inject cost spike, verify alert fires

**💡 Tip**: Per-tenant cost budget with auto-throttling

---

## Step 14: Load Testing & Regression Validation

**Action**: k6 load tests, RAG benchmark suite, cost comparison.

**Reasoning**: Confirm P95 ≤2.5s, recall unchanged, cost reduced.

**Implementation**:
- k6: 100 concurrent users, 1 req/s, 10min against `/v1/chat/completions`
- Capture P95 latency, error rate, token usage
- Run 200-query RAG eval suite for recall@10 and groundedness
- Compare cost metrics from Step 13
- Store results in markdown report

**Error Handling**:
- Latency >2.5s: rollback via Git tags, investigate with traces

**Testing**:
- Automate k6 in CI, fail if P95 >2.5s
- Nightly RAG benchmark, alert on >5% recall regression

**💡 Tip**: Run with feature flags on/off to quantify each optimization

---

## Step 15: Update Documentation

**Action**: Revise docs with new config, observability, feature flags.

**Reasoning**: Communicate changes to developers and customers.

**Implementation**:
- Update `docs/rag.md`: chunking, vector tuning, token budget
- New `docs/observability.md`: Prometheus, Grafana, tracing
- Document feature-flag admin UI in `docs/admin.md`
- README: "Performance Tuning" checklist, "Cost Monitoring" guide
- Changelog: "vX.Y – latency & cost optimizations"

**Error Handling**:
- Grep for stale config key references

**Testing**:
- Run `markdownlint` in CI
- Verify `php artisan config:cache` reflects new keys

**💡 Tip**: Create video walkthrough of new dashboards

---

## Priority Summary

**High Priority (Weeks 1-2)**:
1. ✅ Step 1: Baseline metrics
2. ✅ Step 5: Standardize chunking
3. ✅ Step 6: Batch embeddings
4. ✅ Step 11: Scale queues

**Medium Priority (Weeks 3-4)**:
5. ✅ Step 3: Optimize scraper
6. ✅ Step 4: Cache parsing
7. ✅ Step 7: Tune pgvector
8. ✅ Step 8: Hybrid retriever tuning

**Low Priority (Weeks 5-6)**:
9. ✅ Step 2: Distributed tracing
10. ✅ Step 9: Review reranker
11. ✅ Step 10: Context builder
12. ✅ Step 12: Feature flags
13. ✅ Step 13: Cost dashboards

**Final Steps**:
14. ✅ Step 14: Load testing
15. ✅ Step 15: Documentation

---

## Expected Outcomes

- **Latency**: P95 < 2.5s (currently unknown baseline)
- **Cost**: 30-50% reduction in embedding costs via batching/caching
- **Throughput**: 2-3x improvement via parallel scraping and queue optimization
- **Quality**: Maintain or improve recall@10 and groundedness
- **Observability**: Full tracing, metrics, and cost dashboards

---

**Next Step**: Confirm you want to proceed with Step 1 (Baseline Metrics)

