<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Models\TenantForm;
use App\Services\Form\FormSubmissionService;
use App\Services\Form\FormTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * 📝 FormController - API endpoints per form dinamici
 * Gestisce trigger detection e form submission dal widget
 */
class FormController extends Controller
{
    public function __construct(
        private FormTriggerService $triggerService,
        private FormSubmissionService $submissionService
    ) {
        // Middleware gestito a livello di route
    }

    /**
     * 🎯 Check Form Triggers
     * Controlla se un messaggio utente deve attivare un form
     *
     * POST /api/v1/forms/check-triggers
     */
    public function checkTriggers(Request $request): JsonResponse
    {
        try {
            // Validazione input
            $validated = $request->validate([
                'tenant_id' => 'required|integer|exists:tenants,id',
                'message' => 'required|string|max:2000',
                'session_id' => 'required|string|max:255',
                'conversation_history' => 'array',
                'conversation_history.*.role' => 'in:user,assistant',
                'conversation_history.*.content' => 'string',
                'conversation_history.*.timestamp' => 'nullable|string', // Più flessibile
            ]);

            Log::info('📝 Form Trigger Check', [
                'tenant_id' => $validated['tenant_id'],
                'message' => substr($validated['message'], 0, 100),
                'session_id' => $validated['session_id'],
            ]);

            // Check per trigger
            $trigger = $this->triggerService->checkForTriggers(
                tenantId: $validated['tenant_id'],
                message: $validated['message'],
                sessionId: $validated['session_id'],
                conversationHistory: $validated['conversation_history'] ?? []
            );

            if ($trigger) {
                return response()->json([
                    'triggered' => true,
                    'form' => $trigger,
                ]);
            }

            return response()->json([
                'triggered' => false,
                'form' => null,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'triggered' => false,
                'error' => 'Invalid request data',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('📝 Form trigger check error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'triggered' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * 📤 Submit Form
     * Gestisce la sottomissione di un form dal widget
     *
     * POST /api/v1/forms/submit
     */
    public function submit(Request $request): JsonResponse
    {
        try {
            // Validazione base
            $validated = $request->validate([
                'form_id' => 'required|integer|exists:tenant_forms,id',
                'session_id' => 'required|string|max:255',
                'form_data' => 'required|array',
                'chat_context' => 'array',
                'trigger_type' => 'required|string|in:keyword,auto,question,manual',
                'trigger_value' => 'nullable|string|max:255',
                'ip_address' => 'nullable|ip',
                'user_agent' => 'nullable|string|max:500',
            ]);

            // Carica form e verifica accesso
            $form = TenantForm::with('fields')
                ->where('id', $validated['form_id'])
                ->where('active', true)
                ->first();

            if (! $form) {
                return response()->json([
                    'success' => false,
                    'message' => 'Form non trovato o non attivo',
                ], 404);
            }

            // Verifica tenant (sicurezza)
            $tenantId = $request->attributes->get('tenant_id');
            if (! $tenantId || $form->tenant_id !== $tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Accesso negato',
                ], 403);
            }

            Log::info('📝 Form Submission', [
                'form_id' => $form->id,
                'form_name' => $form->name,
                'tenant_id' => $tenantId,
                'session_id' => $validated['session_id'],
            ]);

            // Protezione anti-spam time-based (invece di status-based)
            $recentSubmission = FormSubmission::where('session_id', $validated['session_id'])
                ->where('tenant_id', $tenantId)
                ->where('tenant_form_id', $form->id)
                ->where('submitted_at', '>=', now()->subMinutes(2)) // Solo ultimi 2 minuti
                ->first();

            if ($recentSubmission) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hai già inviato una richiesta simile di recente. Attendi 2 minuti prima di inviarne un\'altra.',
                ], 409);
            }

            // Validazione dinamica dei campi del form
            $fieldValidation = $this->validateFormFields($form, $validated['form_data']);
            if (! $fieldValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Alcuni campi non sono validi',
                    'errors' => $fieldValidation['errors'],
                ], 422);
            }

            // Crea la submission
            $submission = $this->submissionService->submitForm(
                form: $form,
                sessionId: $validated['session_id'],
                formData: $validated['form_data'],
                chatContext: $validated['chat_context'] ?? [],
                triggerType: $validated['trigger_type'],
                triggerValue: $validated['trigger_value'],
                ipAddress: $validated['ip_address'] ?? $request->ip(),
                userAgent: $validated['user_agent'] ?? $request->userAgent()
            );

            return response()->json([
                'success' => true,
                'submission_id' => $submission->id,
                'message' => $form->auto_response_message ?? 'Richiesta inviata con successo! Ti risponderemo al più presto.',
                'confirmation_email_sent' => $submission->confirmation_email_sent,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Dati non validi',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('📝 Form submission error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['form_data']), // Exclude form data from logs for privacy
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore interno del server. Riprova più tardi.',
            ], 500);
        }
    }

    /**
     * 📋 List User Submissions
     * Elenca le submissions dell'utente per la sessione corrente
     *
     * GET /api/v1/forms/submissions
     */
    public function listSubmissions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id' => 'required|string|max:255',
                'limit' => 'integer|min:1|max:50',
            ]);

            $tenantId = $request->user()->tenant_id;
            $limit = $validated['limit'] ?? 10;

            $submissions = FormSubmission::with(['tenantForm:id,name'])
                ->where('tenant_id', $tenantId)
                ->where('session_id', $validated['session_id'])
                ->orderBy('submitted_at', 'desc')
                ->limit($limit)
                ->get();

            $formattedSubmissions = $submissions->map(function ($submission) {
                return [
                    'id' => $submission->id,
                    'form_name' => $submission->tenantForm->name,
                    'status' => $submission->status,
                    'submitted_at' => $submission->submitted_at->format('Y-m-d H:i:s'),
                    'form_data' => $submission->getFormattedDataAttribute(),
                ];
            });

            return response()->json([
                'success' => true,
                'submissions' => $formattedSubmissions,
            ]);

        } catch (\Exception $e) {
            Log::error('📝 List submissions error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore nel recupero delle richieste',
            ], 500);
        }
    }

    /**
     * ✅ Validazione dinamica dei campi del form
     */
    private function validateFormFields(TenantForm $form, array $formData): array
    {
        $rules = [];
        $messages = [];
        $attributes = [];

        foreach ($form->fields as $field) {
            $fieldRules = [];

            // Required validation
            if ($field->required) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            // Type-specific validation
            switch ($field->type) {
                case 'email':
                    $fieldRules[] = 'email';
                    break;
                case 'phone':
                    $fieldRules[] = 'regex:/^[\+]?[0-9\s\-\(\)]+$/';
                    break;
                case 'number':
                    $fieldRules[] = 'numeric';
                    break;
                case 'date':
                    $fieldRules[] = 'date';
                    break;
                case 'select':
                case 'radio':
                    if ($field->options) {
                        $validOptions = array_keys($field->options);
                        $fieldRules[] = 'in:'.implode(',', $validOptions);
                    }
                    break;
                case 'checkbox':
                    $fieldRules[] = 'array';
                    if ($field->options) {
                        $validOptions = array_keys($field->options);
                        $fieldRules[] = 'array';
                        $rules[$field->name.'.*'] = 'in:'.implode(',', $validOptions);
                    }
                    break;
                case 'textarea':
                    $fieldRules[] = 'string';
                    $fieldRules[] = 'max:2000';
                    break;
                default:
                    $fieldRules[] = 'string';
                    $fieldRules[] = 'max:255';
                    break;
            }

            // Custom validation rules from field configuration
            if ($field->validation_rules) {
                $customRules = is_array($field->validation_rules)
                    ? $field->validation_rules
                    : json_decode($field->validation_rules, true);

                if (is_array($customRules)) {
                    $fieldRules = array_merge($fieldRules, $customRules);
                }
            }

            $rules[$field->name] = $fieldRules;
            $attributes[$field->name] = $field->label;

            // Custom error messages
            if ($field->required) {
                $messages[$field->name.'.required'] = "Il campo {$field->label} è obbligatorio";
            }

            if ($field->type === 'email') {
                $messages[$field->name.'.email'] = "Il campo {$field->label} deve essere un indirizzo email valido";
            }
        }

        $validator = Validator::make($formData, $rules, $messages, $attributes);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray(),
            ];
        }

        return [
            'valid' => true,
            'errors' => [],
        ];
    }
}
