<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WidgetEventController extends Controller
{
    /**
     * Track a widget event
     */
    public function track(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_type' => 'required|string|in:widget_loaded,chatbot_opened,chatbot_closed,message_sent,message_received,message_error,widget_error,form_triggered_immediate,form_trigger_error_immediate,form_triggered,form_trigger_error,form_submitted,form_cancelled,form_error',
            'session_id' => 'required|string|max:255',
            'event_data' => 'nullable|array',
            'event_data.query' => 'nullable|string',
            'event_data.response' => 'nullable|string',
            'event_data.response_time' => 'nullable|numeric',
            'event_data.citations' => 'nullable|integer',
            'event_data.confidence' => 'nullable|numeric|between:0,1',
            'event_data.tokens_used' => 'nullable|integer',
            'event_data.error' => 'nullable|string',
            'event_data.page_url' => 'nullable|string|url',
            'event_data.widget_config' => 'nullable|array',
            'event_data.timestamp' => 'nullable|string',
            'event_data.user_agent' => 'nullable|string',
            'event_data.screen_resolution' => 'nullable|string',
            'event_data.viewport_size' => 'nullable|string',
            'event_data.timezone' => 'nullable|string',
            'event_data.language' => 'nullable|string',
            'event_data.query_length' => 'nullable|integer',
            'event_data.response_length' => 'nullable|integer',
            'event_data.context' => 'nullable|string',
            'event_data.stack' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid event data',
                'errors' => $validator->errors()
            ], 400);
        }

        // Get tenant from request
        $tenantId = (int) $request->attributes->get('tenant_id');
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant ID is required'
            ], 400);
        }

        $data = $validator->validated();
        
        try {
            // Create widget event
            $eventData = $data['event_data'] ?? [];
            
            // Build event attributes, only including non-null values where appropriate
            $eventAttributes = [
                'tenant_id' => $tenantId,
                'event_type' => $data['event_type'],
                'session_id' => $data['session_id'],
                'event_timestamp' => now(),
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'custom_properties' => $eventData,
                'had_error' => $data['event_type'] === 'message_error' || $data['event_type'] === 'widget_error',
            ];
            
            // Add optional fields only if they have values
            if (isset($eventData['response_time'])) {
                $eventAttributes['response_time_ms'] = (int) round($eventData['response_time']);
            }
            if (isset($eventData['confidence'])) {
                $eventAttributes['confidence_score'] = (float) $eventData['confidence'];
            }
            if (isset($eventData['tokens_used'])) {
                $eventAttributes['tokens_used'] = (int) $eventData['tokens_used'];
            }
            if (isset($eventData['citations'])) {
                $eventAttributes['citations_count'] = is_array($eventData['citations']) ? count($eventData['citations']) : (int)$eventData['citations'];
            }
            
            $event = WidgetEvent::create($eventAttributes);

            // Log successful tracking for debugging (can be removed in production)
            Log::info('widget.event_tracked', [
                'tenant_id' => $tenantId,
                'event_type' => $data['event_type'],
                'session_id' => $data['session_id'],
                'event_id' => $event->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Event tracked successfully',
                'event_id' => $event->id,
            ]);

        } catch (\Throwable $e) {
            Log::error('widget.event_tracking_failed', [
                'tenant_id' => $tenantId,
                'event_type' => $data['event_type'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to track event'
            ], 500);
        }
    }

    /**
     * Get session statistics for a specific session
     * Useful for debugging or real-time analytics
     */
    public function sessionStats(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid session ID',
                'errors' => $validator->errors()
            ], 400);
        }

        $tenantId = (int) $request->attributes->get('tenant_id');
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant ID is required'
            ], 400);
        }

        try {
            $sessionId = $validator->validated()['session_id'];
            
            $events = WidgetEvent::forTenant($tenantId)
                ->where('session_id', $sessionId)
                ->orderBy('event_timestamp')
                ->get();

            $stats = [
                'session_id' => $sessionId,
                'tenant_id' => $tenantId,
                'total_events' => $events->count(),
                'session_duration' => null,
                'events_by_type' => $events->groupBy('event_type')->map->count(),
                'total_messages' => $events->where('event_type', 'message_sent')->count(),
                'total_responses' => $events->where('event_type', 'message_received')->count(),
                'avg_response_time' => $events->where('event_type', 'message_received')->avg('response_time_ms'),
                'total_tokens' => $events->sum('tokens_used'),
                'had_errors' => $events->where('had_error', true)->count() > 0,
                'error_count' => $events->where('had_error', true)->count(),
            ];

            // Calculate session duration if we have multiple events
            if ($events->count() > 1) {
                $firstEvent = $events->first();
                $lastEvent = $events->last();
                $stats['session_duration'] = $lastEvent->event_timestamp->diffInSeconds($firstEvent->event_timestamp);
            }

            return response()->json([
                'success' => true,
                'stats' => $stats,
            ]);

        } catch (\Throwable $e) {
            Log::error('widget.session_stats_failed', [
                'tenant_id' => $tenantId,
                'session_id' => $request->input('session_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve session stats'
            ], 500);
        }
    }

    /**
     * Health check endpoint for widget analytics
     */
    public function health(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        
        try {
            // Count recent events (last 24 hours) for this tenant
            $recentEvents = WidgetEvent::forTenant($tenantId)
                ->where('event_timestamp', '>=', now()->subDay())
                ->count();

            return response()->json([
                'success' => true,
                'status' => 'healthy',
                'tenant_id' => $tenantId,
                'recent_events_24h' => $recentEvents,
                'timestamp' => now()->toISOString(),
            ]);

        } catch (\Throwable $e) {
            Log::error('widget.health_check_failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status' => 'unhealthy',
                'error' => 'Database connection issue'
            ], 500);
        }
    }

    /**
     * Track a widget event from public embed (no API key required)
     */
    public function trackPublic(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event' => 'required|string|max:255',
            'properties' => 'nullable|array',
            'properties.tenant_id' => 'nullable|integer',
            'properties.page_url' => 'nullable|string',
            'properties.user_agent' => 'nullable|string',
            'properties.timestamp' => 'nullable|integer',
            'properties.theme' => 'nullable|string',
            'properties.referrer' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid event data',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $data = $validator->validated();
            $properties = $data['properties'] ?? [];
            
            // Create simplified widget event for embed tracking
            $widgetEvent = WidgetEvent::create([
                'tenant_id' => $properties['tenant_id'] ?? null,
                'event_type' => $data['event'],
                'session_id' => $properties['session_id'] ?? 'embed_' . uniqid(),
                'event_timestamp' => now(),
                'user_agent' => $properties['user_agent'] ?? $request->header('User-Agent'),
                'ip_address' => $request->ip(),
                'page_url' => $properties['page_url'] ?? $request->header('Referer'),
                'referrer_url' => $properties['referrer'] ?? $request->header('Referer'),
                'widget_theme' => $properties['theme'] ?? null,
                'custom_properties' => [
                    'source' => 'embed',
                    'timestamp' => $properties['timestamp'] ?? time(),
                    'original_properties' => $properties,
                ],
            ]);

            Log::info('Public widget event tracked', [
                'event_id' => $widgetEvent->id,
                'event_type' => $data['event'],
                'tenant_id' => $properties['tenant_id'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Event tracked successfully',
                'event_id' => $widgetEvent->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to track public widget event', [
                'error' => $e->getMessage(),
                'event_type' => $data['event'] ?? 'unknown',
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to track event'
            ], 500);
        }
    }
}