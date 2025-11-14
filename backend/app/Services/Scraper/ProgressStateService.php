<?php

namespace App\Services\Scraper;

use App\Models\ScraperProgress;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProgressStateService
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    private const TARGET_STATUS_DISPATCHED = 'dispatched';

    /**
     * @var array<string, string[]>
     */
    private array $allowedTransitions = [
        self::STATUS_RUNNING => [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
        self::STATUS_CANCELLED => [],
    ];

    public function __construct(private readonly DatabaseManager $db) {}

    public function transitionToDispatched(int $tenantId, int $progressId): bool
    {
        try {
            return $this->db->transaction(function () use ($tenantId, $progressId): bool {
                $progress = ScraperProgress::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $progressId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $this->isValidTransition($progress->status, self::TARGET_STATUS_DISPATCHED)) {
                    Log::warning('Scraper progress invalid transition attempt', [
                        'progress_id' => $progressId,
                        'tenant_id' => $tenantId,
                        'from' => $progress->status,
                        'to' => self::TARGET_STATUS_DISPATCHED,
                    ]);

                    return false;
                }

                $progress->update([
                    'status' => self::TARGET_STATUS_DISPATCHED,
                    'completed_at' => now(),
                ]);

                return true;
            });
        } catch (ModelNotFoundException $exception) {
            Log::warning('Scraper progress record not found during transition', [
                'progress_id' => $progressId,
                'tenant_id' => $tenantId,
            ]);

            return false;
        } catch (QueryException $exception) {
            Log::error('Failed to update scraper progress status due to database constraint', [
                'progress_id' => $progressId,
                'tenant_id' => $tenantId,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Unable to transition scraper progress to dispatched', 0, $exception);
        }
    }

    public function isValidTransition(string $from, string $to): bool
    {
        if (! $this->isAllowedStatus($from) || ! $this->isAllowedStatus($to)) {
            return false;
        }

        if ($from === $to) {
            return true;
        }

        return in_array($to, $this->allowedTransitions[$from] ?? [], true);
    }

    private function isAllowedStatus(string $status): bool
    {
        return array_key_exists($status, $this->allowedTransitions);
    }
}
