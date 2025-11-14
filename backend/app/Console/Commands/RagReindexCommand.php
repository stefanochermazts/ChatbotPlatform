<?php

namespace App\Console\Commands;

use App\Jobs\IngestUploadedDocumentJob;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RagReindexCommand extends Command
{
    protected $signature = 'rag:reindex {--tenant= : ID del tenant da reindicizzare} {--document= : ID del documento specifico} {--dry-run : Mostra cosa verrebbe fatto senza eseguire}';

    protected $description = 'Rigenera gli embedding e reindicizza i documenti nel vettore store';

    private const CONFIRMATION_TIMEOUT = 30;

    public function handle(): int
    {
        $documentId = $this->option('document');
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $query = Document::query()
            ->withCount('chunks')
            ->orderByDesc('updated_at');

        if ($documentId !== null) {
            $query->whereKey((int) $documentId);
        }

        if ($tenantId !== null) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->warn('Nessun documento trovato con i criteri specificati.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tenant', 'Titolo', 'Chunks', 'Ultimo aggiornamento'],
            $documents->map(fn (Document $doc) => [
                $doc->id,
                $doc->tenant_id,
                mb_strimwidth($doc->title ?? '—', 0, 60, '…'),
                $doc->chunks_count,
                optional($doc->updated_at)->toDateTimeString() ?? '—',
            ])
        );

        if ($dryRun) {
            $this->info('Dry-run terminato. Nessuna operazione eseguita.');

            return self::SUCCESS;
        }

        if (! $this->confirmAction($documents->count(), $tenantId, $documentId)) {
            $this->info('Operazione annullata.');

            return self::SUCCESS;
        }

        $progress = $this->output->createProgressBar($documents->count());
        $progress->start();

        foreach ($documents as $document) {
            DB::table('documents')
                ->whereKey($document->id)
                ->update([
                    'ingestion_status' => 'queued',
                    'ingestion_progress' => 0,
                    'updated_at' => Carbon::now(),
                ]);

            IngestUploadedDocumentJob::dispatch($document->id);
            Log::info('rag.reindex.dispatched', [
                'document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
            ]);

            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);
        $this->info('Reindicizzazione avviata. Controlla la coda "ingestion".');

        return self::SUCCESS;
    }

    private function confirmAction(int $count, ?string $tenantId, ?string $documentId): bool
    {
        $target = $documentId !== null
            ? "il documento #{$documentId}"
            : ($tenantId !== null
                ? "tutti i documenti del tenant #{$tenantId}"
                : 'tutti i documenti');

        $question = "Confermi di voler reindicizzare {$count} documenti ({$target})?";

        return $this->confirm($question, false, self::CONFIRMATION_TIMEOUT);
    }
}
