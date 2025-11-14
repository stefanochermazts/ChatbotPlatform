<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuickAction;
use App\Models\QuickActionExecution;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class QuickActionController extends Controller
{
    /**
     * Get available quick actions for the authenticated tenant
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant_id');

        $actions = QuickAction::forTenant($tenantId)
            ->enabled()
            ->ordered()
            ->get(['id', 'action_type', 'label', 'icon', 'description', 'button_style', 'confirmation_message', 'required_fields']);

        // Transform for frontend consumption
        $transformedActions = $actions->map(function ($action) {
            return [
                'id' => $action->id,
                'type' => $action->action_type,
                'label' => $action->label,
                'icon' => $action->icon,
                'description' => $action->description,
                'style' => $action->button_style,
                'confirmation' => $action->confirmation_message,
                'required_fields' => $action->getRequiredFieldsForDisplay(),
            ];
        });

        return response()->json([
            'success' => true,
            'actions' => $transformedActions,
        ]);
    }

    /**
     * Execute a quick action
     */
    public function execute(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action_id' => 'required|integer|exists:quick_actions,id',
            'action_data' => 'required|array',
            'session_id' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request data',
                'errors' => $validator->errors(),
            ], 400);
        }

        $tenantId = (int) $request->attributes->get('tenant_id');
        $data = $validator->validated();

        $action = QuickAction::forTenant($tenantId)->find($data['action_id']);

        if (! $action || ! $action->canExecute()) {
            return response()->json([
                'success' => false,
                'message' => 'Action not available',
            ], 404);
        }

        // Basic rate limiting
        $userIdentifier = $data['action_data']['email'] ?? $request->ip();
        $rateLimitKey = "quick_action:{$userIdentifier}:{$action->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, $action->rate_limit_per_user)) {
            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded',
                'retry_after' => RateLimiter::availableIn($rateLimitKey),
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 3600);

        // Create execution record
        $execution = QuickActionExecution::createForAction($action, [
            'session_id' => $data['session_id'],
            'user_identifier' => $userIdentifier,
            'request_data' => $data['action_data'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer_url' => $request->header('Referer'),
        ]);

        try {
            // Execute based on action type
            $result = $this->handleActionExecution($action, $execution);

            $execution->markAsCompleted(200, $result);
            $action->incrementExecutions();

            return response()->json([
                'success' => true,
                'message' => $action->success_message ?: 'Action executed successfully',
                'result' => $result,
                'execution_id' => $execution->execution_id,
            ]);

        } catch (\Exception $e) {
            $execution->markAsFailed($e->getMessage());

            Log::error('Quick action execution failed', [
                'execution_id' => $execution->execution_id,
                'action_id' => $action->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $action->error_message ?: 'Action execution failed',
            ], 500);
        }
    }

    /**
     * Handle action execution based on type
     */
    private function handleActionExecution(QuickAction $action, QuickActionExecution $execution): array
    {
        $data = $execution->request_data;

        switch ($action->action_type) {
            case 'contact_support':
                Log::info('Support request received', [
                    'tenant' => $action->tenant->name,
                    'name' => $data['name'] ?? 'Unknown',
                    'email' => $data['email'] ?? 'Unknown',
                    'message' => $data['message'] ?? 'No message',
                ]);

                return ['ticket_id' => 'SUP-'.uniqid(), 'status' => 'submitted'];

            case 'request_callback':
                Log::info('Callback request received', [
                    'tenant' => $action->tenant->name,
                    'name' => $data['name'] ?? 'Unknown',
                    'phone' => $data['phone'] ?? 'Unknown',
                ]);

                return ['callback_id' => 'CB-'.uniqid(), 'status' => 'scheduled'];

            case 'download_brochure':
                $downloadToken = base64_encode(json_encode([
                    'email' => $data['email'],
                    'tenant_id' => $action->tenant_id,
                    'expires' => time() + 3600,
                ]));

                return [
                    'download_url' => url('/api/v1/quick-actions/download?token='.$downloadToken),
                    'expires_in' => 3600,
                ];

            default:
                // Custom action - for now just log and return success
                Log::info('Custom quick action executed', [
                    'action_type' => $action->action_type,
                    'tenant' => $action->tenant->name,
                    'data' => $data,
                ]);

                return ['status' => 'executed', 'custom_result' => true];
        }
    }
}
