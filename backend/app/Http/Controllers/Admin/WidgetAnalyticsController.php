<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WidgetEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WidgetAnalyticsController extends Controller
{
    /**
     * Show analytics dashboard
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $tenant = null;
        $tenantId = $request->get('tenant_id');
        
        // Auto-scoping per clienti
        if (!$user->isAdmin()) {
            $userTenants = $user->tenants()->wherePivot('role', 'customer')->get();
            $tenants = $userTenants;
            
            // Se non specificato tenant_id, usa il primo tenant del cliente
            if (!$tenantId && $userTenants->isNotEmpty()) {
                $tenantId = $userTenants->first()->id;
            }
        } else {
            $tenants = Tenant::orderBy('name')->get();
        }
        
        if ($tenantId) {
            $tenant = Tenant::findOrFail($tenantId);
            
            // Controllo accesso per clienti
            if (!$user->isAdmin()) {
                $userTenantIds = $user->tenants()->wherePivot('role', 'customer')->pluck('tenant_id')->toArray();
                if (!in_array($tenant->id, $userTenantIds)) {
                    abort(403, 'Non hai accesso a questo tenant.');
                }
            }
        }
        
        // Date range
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays(30);
        
        if ($request->filled('start_date')) {
            $startDate = Carbon::parse($request->start_date);
        }
        
        if ($request->filled('end_date')) {
            $endDate = Carbon::parse($request->end_date);
        }
        
        // Get analytics data
        $analytics = [];
        if ($tenant) {
            $analytics = $this->getAnalyticsData($tenant->id, $startDate, $endDate);
        }
        
        return view('admin.widget-analytics.index', compact(
            'tenants', 'tenant', 'analytics', 'startDate', 'endDate'
        ));
    }
    
    /**
     * Get detailed analytics for specific tenant
     */
    public function show(Tenant $tenant, Request $request)
    {
        $this->checkTenantAccess($tenant);
        
        // Date range
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays(7);
        
        if ($request->filled('start_date')) {
            $startDate = Carbon::parse($request->start_date);
        }
        
        if ($request->filled('end_date')) {
            $endDate = Carbon::parse($request->end_date);
        }
        
        $details = $this->getDetailedAnalytics($tenant->id, $startDate, $endDate);
        
        $analytics = $details['metrics'];
        $dailyStats = $details['daily_stats'];
        $eventTypes = $details['event_types'];
        $recentEvents = $details['recent_events'];
        
        return view('admin.widget-analytics.show', compact(
            'tenant', 'analytics', 'startDate', 'endDate', 'dailyStats', 'eventTypes', 'recentEvents'
        ));
    }
    
    /**
     * API endpoint for collecting widget events
     */
    public function track(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'event_type' => 'required|string|max:50',
            'session_id' => 'required|string|max:100',
            'user_id' => 'nullable|string|max:100',
            'message_content' => 'nullable|string',
            'response_time_ms' => 'nullable|integer',
            'confidence_score' => 'nullable|numeric|min:0|max:1',
            'citations' => 'nullable|array',
            'model_used' => 'nullable|string|max:50',
            'tokens_used' => 'nullable|integer',
            'user_rating' => 'nullable|integer|min:1|max:5',
            'feedback_type' => 'nullable|string|max:20',
            'page_url' => 'nullable|url',
            'custom_properties' => 'nullable|array'
        ]);
        
        // Add technical data
        $validated['user_agent'] = $request->header('User-Agent');
        $validated['ip_address'] = $request->ip();
        $validated['referrer_url'] = $request->header('Referer');
        
        // Detect device info
        $userAgent = $request->header('User-Agent', '');
        $validated['is_mobile'] = $this->isMobile($userAgent);
        $validated['device_type'] = $this->detectDeviceType($userAgent);
        $validated['browser'] = $this->detectBrowser($userAgent);
        
        // Set message length if message provided
        if (isset($validated['message_content'])) {
            $validated['message_length'] = strlen($validated['message_content']);
        }
        
        // Set citations count
        if (isset($validated['citations'])) {
            $validated['citations_count'] = count($validated['citations']);
        }
        
        try {
            WidgetEvent::createEvent($validated);
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to track event'
            ], 500);
        }
    }
    
    /**
     * Get analytics data for dashboard
     */
    private function getAnalyticsData(int $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        return WidgetEvent::getUsageAnalytics($tenantId, $startDate, $endDate);
    }
    
    /**
     * Get detailed analytics data
     */
    private function getDetailedAnalytics(int $tenantId, Carbon $startDate, Carbon $endDate): array
    {
        // Usage metrics (flatten metrics expected by the view)
        $usage = WidgetEvent::getUsageAnalytics($tenantId, $startDate, $endDate);
        
        // Daily trends raw rows
        $dailyTrendsRows = WidgetEvent::forTenant($tenantId)
            ->inDateRange($startDate, $endDate)
            ->select([
                DB::raw('DATE(event_timestamp) as date'),
                DB::raw('COUNT(*) as total_events'),
                DB::raw('COUNT(DISTINCT session_id) as unique_sessions'),
                DB::raw("SUM(CASE WHEN event_type = 'message_sent' THEN 1 ELSE 0 END) as messages"),
                DB::raw("AVG(CASE WHEN event_type = 'message_received' THEN response_time_ms END) as avg_response_time")
            ])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
        
        // Map to structure expected by the blade ($dailyStats)
        $dailyStats = [];
        foreach ($dailyTrendsRows as $row) {
            $date = $row['date'];
            $dailyStats[$date] = [
                'events' => (int) ($row['total_events'] ?? 0),
                'sessions' => (int) ($row['unique_sessions'] ?? 0),
            ];
        }
        
        // Event types distribution
        $eventTypes = WidgetEvent::forTenant($tenantId)
            ->inDateRange($startDate, $endDate)
            ->select('event_type', DB::raw('COUNT(*) as count'))
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->get()
            ->toArray();
        
        // Additional performance metrics
        $avgConfidence = WidgetEvent::forTenant($tenantId)
            ->inDateRange($startDate, $endDate)
            ->byEventType('message_received')
            ->avg('confidence_score');
        $totalCitations = WidgetEvent::forTenant($tenantId)
            ->inDateRange($startDate, $endDate)
            ->sum('citations_count');
        
        // Recent events (last 50 in range)
        $recentEvents = WidgetEvent::forTenant($tenantId)
            ->whereBetween('created_at', [$startDate->copy()->startOfDay(), $endDate->copy()->endOfDay()])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
        
        $metrics = array_merge($usage, [
            'avg_confidence' => $avgConfidence,
            'total_citations' => $totalCitations,
        ]);
        
        return [
            'metrics' => $metrics,
            'daily_stats' => $dailyStats,
            'event_types' => $eventTypes,
            'recent_events' => $recentEvents,
        ];
    }
    
    /**
     * Calculate error rate
     */
    private function calculateErrorRate(int $tenantId, Carbon $startDate, Carbon $endDate): float
    {
        $total = WidgetEvent::forTenant($tenantId)->inDateRange($startDate, $endDate)->count();
        if ($total === 0) return 0;
        
        $errors = WidgetEvent::forTenant($tenantId)
            ->inDateRange($startDate, $endDate)
            ->where('had_error', true)
            ->count();
        
        return round(($errors / $total) * 100, 2);
    }
    
    /**
     * Detect if request is from mobile device
     */
    private function isMobile(string $userAgent): bool
    {
        return preg_match('/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $userAgent);
    }
    
    /**
     * Detect device type
     */
    private function detectDeviceType(string $userAgent): string
    {
        if (preg_match('/iPad/i', $userAgent)) {
            return 'tablet';
        } elseif (preg_match('/Mobile|Android|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i', $userAgent)) {
            return 'mobile';
        } else {
            return 'desktop';
        }
    }
    
    /**
     * Detect browser
     */
    private function detectBrowser(string $userAgent): string
    {
        if (preg_match('/Chrome/i', $userAgent) && !preg_match('/Edge/i', $userAgent)) {
            return 'Chrome';
        } elseif (preg_match('/Firefox/i', $userAgent)) {
            return 'Firefox';
        } elseif (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) {
            return 'Safari';
        } elseif (preg_match('/Edge/i', $userAgent)) {
            return 'Edge';
        } else {
            return 'Other';
        }
    }
    
    /**
     * Export analytics data as CSV
     */
    public function export(Tenant $tenant, Request $request): Response
    {
        $this->checkTenantAccess($tenant);
        
        $startDate = $request->filled('start_date') 
            ? Carbon::parse($request->start_date) 
            : now()->subDays(30);
        $endDate = $request->filled('end_date') 
            ? Carbon::parse($request->end_date) 
            : now();

        // Get all events for the tenant in the date range
        $events = WidgetEvent::forTenant($tenant->id)
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->orderBy('created_at', 'desc')
            ->get();

        // Generate CSV content
        $csvContent = "Timestamp,Event Type,Session ID,User Agent,Data\n";
        
        foreach ($events as $event) {
            $data = is_array($event->event_data) 
                ? json_encode($event->event_data) 
                : ($event->event_data ?? '');
            
            // Escape CSV data
            $userAgent = str_replace(['"', ',', "\n", "\r"], ['""', ';', ' ', ' '], $event->user_agent ?? '');
            $sessionId = str_replace(['"', ',', "\n", "\r"], ['""', ';', ' ', ' '], $event->session_id ?? '');
            $eventType = str_replace(['"', ',', "\n", "\r"], ['""', ';', ' ', ' '], $event->event_type ?? '');
            $escapedData = str_replace(['"', "\n", "\r"], ['""', ' ', ' '], $data);
            
            $csvContent .= sprintf(
                '"%s","%s","%s","%s","%s"' . "\n",
                $event->created_at->toISOString(),
                $eventType,
                $sessionId,
                $userAgent,
                $escapedData
            );
        }

        $filename = sprintf(
            'widget-analytics-%s-%s-to-%s.csv',
            Str::slug($tenant->name),
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d')
        );

        return response($csvContent, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Controlla se l'utente corrente ha accesso al tenant
     */
    private function checkTenantAccess(Tenant $tenant)
    {
        $user = auth()->user();
        
        if (!$user->isAdmin()) {
            $userTenantIds = $user->tenants()->wherePivot('role', 'customer')->pluck('tenant_id')->toArray();
            if (!in_array($tenant->id, $userTenantIds)) {
                abort(403, 'Non hai accesso a questo tenant.');
            }
        }
    }
}