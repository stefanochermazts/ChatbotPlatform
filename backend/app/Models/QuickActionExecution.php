<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class QuickActionExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'quick_action_id', 'session_id', 'user_identifier', 'execution_id',
        'request_data', 'request_method', 'request_url', 'request_headers',
        'response_status', 'response_data', 'response_headers', 'response_time_ms',
        'status', 'error_message', 'error_trace',
        'jwt_token_hash', 'hmac_signature', 'security_validated',
        'ip_address', 'user_agent', 'referer_url',
        'is_retry', 'retry_count', 'original_execution_id',
        'rate_limited', 'rate_limit_key',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'request_data' => 'array',
        'request_headers' => 'array',
        'response_data' => 'array',
        'is_retry' => 'boolean',
        'rate_limited' => 'boolean',
        'security_validated' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function quickAction(): BelongsTo
    {
        return $this->belongsTo(QuickAction::class);
    }

    public function originalExecution(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_execution_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'original_execution_id');
    }

    // Scopes
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRecent($query, $hours = 24)
    {
        return $query->where('started_at', '>=', now()->subHours($hours));
    }

    // Helper Methods
    public function markAsCompleted(int $responseStatus, ?array $responseData = null, ?string $responseHeaders = null): void
    {
        $this->update([
            'status' => $responseStatus >= 200 && $responseStatus < 300 ? 'success' : 'failed',
            'response_status' => $responseStatus,
            'response_data' => $responseData,
            'response_headers' => $responseHeaders,
            'completed_at' => now(),
            'response_time_ms' => $this->started_at ? now()->diffInMilliseconds($this->started_at) : null,
        ]);
    }

    public function markAsFailed(string $errorMessage, ?string $errorTrace = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'error_trace' => $errorTrace,
            'completed_at' => now(),
            'response_time_ms' => $this->started_at ? now()->diffInMilliseconds($this->started_at) : null,
        ]);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getDurationMs(): ?int
    {
        return $this->response_time_ms;
    }

    // Static methods
    public static function generateExecutionId(): string
    {
        return 'exec_'.Str::random(32);
    }

    public static function createForAction(QuickAction $action, array $data): self
    {
        return self::create([
            'tenant_id' => $action->tenant_id,
            'quick_action_id' => $action->id,
            'execution_id' => self::generateExecutionId(),
            'session_id' => $data['session_id'] ?? 'unknown',
            'user_identifier' => $data['user_identifier'] ?? null,
            'request_data' => $data['request_data'] ?? [],
            'request_method' => $action->action_method,
            'request_url' => $action->getResolvedActionUrl(),
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'referer_url' => $data['referer_url'] ?? null,
            'jwt_token_hash' => $data['jwt_token_hash'] ?? null,
            'hmac_signature' => $data['hmac_signature'] ?? null,
            'started_at' => now(),
        ]);
    }

    public static function countRecentExecutionsForUser(string $userIdentifier, int $hours = 1): int
    {
        return self::where('user_identifier', $userIdentifier)
            ->where('started_at', '>=', now()->subHours($hours))
            ->count();
    }

    public static function countRecentExecutionsGlobal(int $quickActionId, int $hours = 1): int
    {
        return self::where('quick_action_id', $quickActionId)
            ->where('started_at', '>=', now()->subHours($hours))
            ->count();
    }
}
