<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WidgetEvent extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'tenant_id', 'event_type', 'session_id', 'user_id', 'message_content',
        'message_length', 'citations', 'citations_count', 'confidence_score',
        'response_time_ms', 'model_used', 'tokens_used', 'user_rating',
        'feedback_type', 'feedback_text', 'widget_version', 'user_agent',
        'ip_address', 'referrer_url', 'page_url', 'country_code', 'region',
        'city', 'device_type', 'browser', 'os', 'is_mobile', 'widget_theme',
        'widget_position', 'conversation_context_enabled', 'load_time_ms',
        'interaction_duration_ms', 'messages_in_session', 'resolved_query',
        'intent_detected', 'escalation_reason', 'satisfaction_score',
        'had_error', 'error_type', 'error_message', 'custom_properties',
        'event_timestamp'
    ];
    
    protected $casts = [
        'citations' => 'array',
        'is_mobile' => 'boolean',
        'conversation_context_enabled' => 'boolean',
        'resolved_query' => 'boolean',
        'had_error' => 'boolean',
        'custom_properties' => 'array',
        'event_timestamp' => 'datetime'
    ];
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
    
    public function scopeByEventType($query, $eventType)
    {
        return $query->where('event_type', $eventType);
    }
    
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('event_timestamp', [$startDate, $endDate]);
    }
    
    public static function getUsageAnalytics(int $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        return [
            'total_events' => self::forTenant($tenantId)
                ->inDateRange($startDate, $endDate)
                ->count(),
            'total_sessions' => self::forTenant($tenantId)
                ->inDateRange($startDate, $endDate)
                ->distinct('session_id')
                ->count('session_id'),
            'total_messages' => self::forTenant($tenantId)
                ->inDateRange($startDate, $endDate)
                ->byEventType('message_sent')
                ->count(),
            'total_responses' => self::forTenant($tenantId)
                ->inDateRange($startDate, $endDate)
                ->byEventType('message_received')
                ->count(),
            'widget_opens' => self::forTenant($tenantId)
                ->inDateRange($startDate, $endDate)
                ->byEventType('widget_opened')
                ->count(),
            'avg_response_time' => self::forTenant($tenantId)
                ->inDateRange($startDate, $endDate)
                ->byEventType('message_received')
                ->avg('response_time_ms'),
            'total_tokens_used' => self::forTenant($tenantId)
                ->inDateRange($startDate, $endDate)
                ->sum('tokens_used'),
        ];
    }
    
    public static function createEvent(array $data): self
    {
        $data['event_timestamp'] = $data['event_timestamp'] ?? now();
        return self::create($data);
    }
}