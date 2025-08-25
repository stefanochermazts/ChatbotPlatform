<?php

namespace App\Services\Form;

use App\Models\TenantForm;
use App\Models\FormSubmission;
use App\Models\FormResponse;
use App\Jobs\SendFormConfirmationEmail;
use App\Jobs\SendFormAdminNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FormSubmissionService
{
    /**
     * Sottomette un form compilato dall'utente (API method)
     */
    public function submitForm(
        TenantForm $form,
        string $sessionId,
        array $formData,
        array $chatContext = [],
        string $triggerType = 'manual',
        ?string $triggerValue = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): FormSubmission {
        Log::info('[FormSubmission] Processing form submission', [
            'form_id' => $form->id,
            'form_name' => $form->name,
            'session_id' => $sessionId,
            'trigger_type' => $triggerType
        ]);

        // Controlla se l'utente ha già una sottomissione pending
        $existingSubmission = FormSubmission::where('session_id', $sessionId)
            ->where('tenant_id', $form->tenant_id)
            ->where('status', FormSubmission::STATUS_PENDING)
            ->first();

        if ($existingSubmission) {
            throw new \Exception('User already has a pending submission for this session');
        }

        try {
            // Crea la sottomissione
            $submission = FormSubmission::create([
                'tenant_form_id' => $form->id,
                'tenant_id' => $form->tenant_id,
                'session_id' => $sessionId,
                'user_email' => $this->extractUserEmail($formData),
                'user_name' => $this->extractUserName($formData),
                'form_data' => $formData,
                'chat_context' => $chatContext,
                'status' => FormSubmission::STATUS_PENDING,
                'submitted_at' => now(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'trigger_type' => $triggerType,
                'trigger_value' => $triggerValue,
                'confirmation_email_sent' => false,
                'admin_notification_sent' => false,
            ]);

            Log::info('[FormSubmission] Submission created successfully', [
                'submission_id' => $submission->id,
                'form_id' => $form->id,
                'tenant_id' => $form->tenant_id
            ]);

            // Invia email di conferma se l'utente ha fornito l'email
            if ($submission->user_email) {
                $this->queueConfirmationEmail($submission);
                $submission->update(['confirmation_email_sent' => true]);
            }

            // Invia notifica admin se configurata
            if ($form->admin_notification_email) {
                $this->queueAdminNotification($submission);
                $submission->update(['admin_notification_sent' => true]);
            }

            // Auto-risposta se abilitata (crea come primo messaggio del thread)
            if ($form->auto_response_enabled && $form->auto_response_message) {
                $autoResponse = $this->createAutoResponse($submission, $form->auto_response_message);
                
                // Aggiorna statistiche submission per auto-risposta
                if ($autoResponse) {
                    $submission->updateResponseStats($autoResponse);
                }
            }

            // Attiva conversazione se necessario
            $priority = $this->determinePriority($formData, $triggerType);
            $submission->activateConversation($priority);

            return $submission;

        } catch (\Exception $e) {
            Log::error('[FormSubmission] Failed to create submission', [
                'form_id' => $form->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Sottomette un form compilato dall'utente (legacy method)
     */
    public function submitFormLegacy(array $data): array
    {
        Log::info('[FormSubmission] Processing form submission', [
            'form_id' => $data['form_id'] ?? null,
            'session_id' => $data['session_id'] ?? null,
        ]);

        // Valida i dati base
        $validator = Validator::make($data, [
            'form_id' => 'required|exists:tenant_forms,id',
            'session_id' => 'required|string|max:255',
            'form_data' => 'required|array',
            'chat_context' => 'nullable|array',
            'trigger_type' => 'nullable|string',
            'trigger_value' => 'nullable|string',
            'ip_address' => 'nullable|ip',
            'user_agent' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::error('[FormSubmission] Validation failed', [
                'errors' => $validator->errors()->toArray(),
                'data' => $data
            ]);

            return [
                'success' => false,
                'errors' => $validator->errors()->toArray(),
                'message' => 'Dati non validi'
            ];
        }

        // Ottieni il form
        $form = TenantForm::with('fields')->find($data['form_id']);
        if (!$form || !$form->active) {
            Log::error('[FormSubmission] Form not found or inactive', [
                'form_id' => $data['form_id']
            ]);

            return [
                'success' => false,
                'message' => 'Form non trovato o non attivo'
            ];
        }

        // Controlla se l'utente ha già una sottomissione pending
        $existingSubmission = FormSubmission::forSession($data['session_id'])
            ->forTenant($form->tenant_id)
            ->pending()
            ->first();

        if ($existingSubmission) {
            Log::warning('[FormSubmission] User already has pending submission', [
                'session_id' => $data['session_id'],
                'existing_submission_id' => $existingSubmission->id
            ]);

            return [
                'success' => false,
                'message' => 'Hai già una richiesta in corso. Attendi la risposta prima di inviarne una nuova.'
            ];
        }

        // Valida i dati del form usando i campi definiti
        $formValidationResult = $this->validateFormData($form, $data['form_data']);
        if (!$formValidationResult['valid']) {
            Log::error('[FormSubmission] Form data validation failed', [
                'form_id' => $form->id,
                'errors' => $formValidationResult['errors']
            ]);

            return [
                'success' => false,
                'errors' => $formValidationResult['errors'],
                'message' => 'Alcuni campi non sono validi'
            ];
        }

        try {
            // Crea la sottomissione
            $submission = FormSubmission::create([
                'tenant_form_id' => $form->id,
                'tenant_id' => $form->tenant_id,
                'session_id' => $data['session_id'],
                'user_email' => $this->extractUserEmail($data['form_data']),
                'user_name' => $this->extractUserName($data['form_data']),
                'form_data' => $data['form_data'],
                'chat_context' => $data['chat_context'] ?? null,
                'status' => FormSubmission::STATUS_PENDING,
                'submitted_at' => now(),
                'ip_address' => $data['ip_address'] ?? request()->ip(),
                'user_agent' => $data['user_agent'] ?? request()->userAgent(),
                'trigger_type' => $data['trigger_type'] ?? FormSubmission::TRIGGER_MANUAL,
                'trigger_value' => $data['trigger_value'] ?? null,
            ]);

            Log::info('[FormSubmission] Submission created successfully', [
                'submission_id' => $submission->id,
                'form_id' => $form->id,
                'tenant_id' => $form->tenant_id
            ]);

            // Invia email di conferma se l'utente ha fornito l'email
            if ($submission->user_email) {
                $this->queueConfirmationEmail($submission);
            }

            // Invia notifica admin se configurata
            if ($form->admin_notification_email) {
                $this->queueAdminNotification($submission);
            }

            // Auto-risposta se abilitata
            if ($form->auto_response_enabled && $form->auto_response_message) {
                $this->createAutoResponse($submission, $form->auto_response_message);
            }

            return [
                'success' => true,
                'submission_id' => $submission->id,
                'message' => 'Richiesta inviata con successo! Ti risponderemo al più presto.',
                'confirmation_email_sent' => !empty($submission->user_email),
            ];

        } catch (\Exception $e) {
            Log::error('[FormSubmission] Failed to create submission', [
                'form_id' => $form->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Errore interno. Riprova più tardi.'
            ];
        }
    }

    /**
     * Valida i dati del form usando i campi definiti
     */
    private function validateFormData(TenantForm $form, array $formData): array
    {
        $rules = [];
        $fieldLabels = [];

        foreach ($form->fields as $field) {
            $rules[$field->name] = $field->getValidationRulesForLaravel();
            $fieldLabels[$field->name] = $field->label;
        }

        $validator = Validator::make($formData, $rules);

        // Personalizza i messaggi di errore
        $validator->setAttributeNames($fieldLabels);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'errors' => $validator->errors()->toArray()
            ];
        }

        return ['valid' => true];
    }

    /**
     * Estrae l'email dell'utente dai dati del form
     */
    private function extractUserEmail(array $formData): ?string
    {
        // Cerca campi che potrebbero contenere l'email
        $emailFields = ['email', 'mail', 'e_mail', 'user_email', 'email_address'];
        
        foreach ($emailFields as $field) {
            if (isset($formData[$field]) && filter_var($formData[$field], FILTER_VALIDATE_EMAIL)) {
                return $formData[$field];
            }
        }

        return null;
    }

    /**
     * Estrae il nome dell'utente dai dati del form
     */
    private function extractUserName(array $formData): ?string
    {
        // Cerca campi che potrebbero contenere il nome
        $nameFields = ['name', 'nome', 'full_name', 'user_name', 'first_name', 'cognome', 'nome_cognome'];
        
        foreach ($nameFields as $field) {
            if (isset($formData[$field]) && !empty(trim($formData[$field]))) {
                return trim($formData[$field]);
            }
        }

        // Prova a combinare nome e cognome se separati
        $firstName = $formData['first_name'] ?? $formData['nome'] ?? null;
        $lastName = $formData['last_name'] ?? $formData['cognome'] ?? null;
        
        if ($firstName && $lastName) {
            return trim($firstName . ' ' . $lastName);
        }

        return $firstName ?: $lastName ?: null;
    }

    /**
     * Mette in coda l'email di conferma
     */
    private function queueConfirmationEmail(FormSubmission $submission): void
    {
        try {
            SendFormConfirmationEmail::dispatch($submission);
            
            Log::info('[FormSubmission] Confirmation email queued', [
                'submission_id' => $submission->id,
                'user_email' => $submission->user_email
            ]);
        } catch (\Exception $e) {
            Log::error('[FormSubmission] Failed to queue confirmation email', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Mette in coda la notifica admin
     */
    private function queueAdminNotification(FormSubmission $submission): void
    {
        try {
            SendFormAdminNotification::dispatch($submission);
            
            Log::info('[FormSubmission] Admin notification queued', [
                'submission_id' => $submission->id,
                'admin_email' => $submission->tenantForm->admin_notification_email
            ]);
        } catch (\Exception $e) {
            Log::error('[FormSubmission] Failed to queue admin notification', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Crea una risposta automatica
     */
    private function createAutoResponse(FormSubmission $submission, string $message): ?FormResponse
    {
        try {
            $threadId = FormResponse::generateThreadId();
            
            $response = FormResponse::create([
                'form_submission_id' => $submission->id,
                'admin_user_id' => null, // Sistema automatico
                'response_content' => $message,
                'response_type' => FormResponse::TYPE_AUTO,
                'closes_submission' => false,
                'thread_id' => $threadId,
                'is_thread_starter' => true,
                'priority' => FormResponse::PRIORITY_NORMAL,
            ]);

            Log::info('[FormSubmission] Auto-response created', [
                'submission_id' => $submission->id,
                'response_id' => $response->id,
                'thread_id' => $threadId
            ]);
            
            return $response;
        } catch (\Exception $e) {
            Log::error('[FormSubmission] Failed to create auto-response', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Determina priorità iniziale basata sui dati del form
     */
    private function determinePriority(array $formData, string $triggerType): string
    {
        // Parole chiave urgenti
        $urgentKeywords = ['urgente', 'immediato', 'emergenza', 'subito', 'blocco'];
        $highKeywords = ['problema', 'errore', 'non funziona', 'aiuto'];
        
        // Controlla contenuto per keyword di priorità
        $text = strtolower(json_encode($formData));
        
        foreach ($urgentKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return 'urgent';
            }
        }
        
        foreach ($highKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return 'high';
            }
        }
        
        // Trigger automatico dopo molti messaggi = priorità alta
        if ($triggerType === 'auto') {
            return 'high';
        }
        
        return 'normal';
    }

    /**
     * Ottieni sottomissioni per un tenant con filtri
     */
    public function getSubmissions(int $tenantId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = FormSubmission::with(['tenantForm', 'responses'])
            ->forTenant($tenantId);

        // Applica filtri
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['form_id'])) {
            $query->where('tenant_form_id', $filters['form_id']);
        }

        if (isset($filters['date_from'])) {
            $query->where('submitted_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('submitted_at', '<=', $filters['date_to']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('user_email', 'ILIKE', "%{$search}%")
                  ->orWhere('user_name', 'ILIKE', "%{$search}%")
                  ->orWhereJsonContains('form_data', $search);
            });
        }

        return $query->orderBy('submitted_at', 'desc')
                    ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Ottieni statistiche delle sottomissioni per un tenant
     */
    public function getSubmissionStats(int $tenantId): array
    {
        $submissions = FormSubmission::forTenant($tenantId);

        return [
            'total' => $submissions->count(),
            'pending' => $submissions->pending()->count(),
            'responded' => $submissions->responded()->count(),
            'closed' => $submissions->closed()->count(),
            'today' => $submissions->whereDate('submitted_at', today())->count(),
            'this_week' => $submissions->whereBetween('submitted_at', [
                now()->startOfWeek(),
                now()->endOfWeek()
            ])->count(),
            'this_month' => $submissions->whereMonth('submitted_at', now()->month)
                                     ->whereYear('submitted_at', now()->year)
                                     ->count(),
        ];
    }
}
