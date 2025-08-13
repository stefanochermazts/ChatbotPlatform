<?php

namespace App\Jobs;

use App\Services\RAG\MilvusClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteVectorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int> */
    private array $documentIds;

    public function __construct(array $documentIds)
    {
        $this->onQueue('indexing');
        $this->documentIds = array_values(array_unique(array_map('intval', $documentIds)));
    }

    public function handle(MilvusClient $milvus): void
    {
        if ($this->documentIds === []) {
            return;
        }
        try {
            $rows = DB::table('document_chunks')
                ->whereIn('document_id', $this->documentIds)
                ->select('document_id', 'chunk_index')
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            $primaryIds = [];
            foreach ($rows as $r) {
                $primaryIds[] = (int) (((int) $r->document_id) * 100000 + (int) $r->chunk_index);
            }
            $milvus->deleteByPrimaryIds($primaryIds);
        } catch (\Throwable $e) {
            Log::error('jobs.delete_vectors_failed', [
                'doc_ids' => $this->documentIds,
                'error' => $e->getMessage(),
            ]);
        }
    }
}


