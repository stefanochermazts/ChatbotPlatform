<?php

namespace App\Services\Form;

use App\Models\TenantForm;
use App\Models\FormSubmission;
use Illuminate\Support\Facades\Log;

class FormTriggerService
{
    /**
     * Verifica se deve essere triggerato un form basandosi sul messaggio dell'utente
     */
    public function checkForTriggers(
        int $tenantId, 
        string $message, 
        string $sessionId, 
        array $conversationHistory = []
    ): ?array {
        Log::info('[FormTrigger] Checking triggers', [
            'tenant_id' => $tenantId,
            'message' => $message,
            'session_id' => $sessionId,
            'history_count' => count($conversationHistory)
        ]);

        // Ottieni tutti i form attivi per il tenant
        $forms = TenantForm::active()
            ->forTenant($tenantId)
            ->with('fields')
            ->get();

        if ($forms->isEmpty()) {
            Log::debug('[FormTrigger] No active forms for tenant', ['tenant_id' => $tenantId]);
            return null;
        }

        // Note: Rimosso il blocco globale per submission pending.
        // Ora controlliamo per form specifico durante il trigger check.

        // Controlla trigger per keyword
        foreach ($forms as $form) {
            if ($this->checkKeywordTrigger($form, $message)) {
                Log::info('[FormTrigger] Keyword trigger activated', [
                    'form_id' => $form->id,
                    'form_name' => $form->name,
                    'trigger_type' => 'keyword'
                ]);

                return $this->buildTriggerResponse($form, 'keyword', $message);
            }
        }

        // Controlla trigger per numero di messaggi
        $messageCount = count($conversationHistory) + 1; // +1 per il messaggio corrente
        foreach ($forms as $form) {
            if ($this->checkMessageCountTrigger($form, $messageCount)) {
                Log::info('[FormTrigger] Message count trigger activated', [
                    'form_id' => $form->id,
                    'form_name' => $form->name,
                    'trigger_type' => 'message_count',
                    'message_count' => $messageCount
                ]);

                return $this->buildTriggerResponse($form, 'auto', "After {$messageCount} messages");
            }
        }

        // Controlla trigger per domande specifiche
        foreach ($forms as $form) {
            if ($this->checkQuestionTrigger($form, $message)) {
                Log::info('[FormTrigger] Question trigger activated', [
                    'form_id' => $form->id,
                    'form_name' => $form->name,
                    'trigger_type' => 'question'
                ]);

                return $this->buildTriggerResponse($form, 'question', $message);
            }
        }

        Log::debug('[FormTrigger] No triggers activated');
        return null;
    }

    /**
     * Verifica trigger per parole chiave
     */
    private function checkKeywordTrigger(TenantForm $form, string $message): bool
    {
        if (!$form->trigger_keywords || empty($form->trigger_keywords)) {
            return false;
        }

        $messageLower = mb_strtolower($message);
        
        foreach ($form->trigger_keywords as $keyword) {
            $keywordLower = mb_strtolower(trim($keyword));
            if ($keywordLower && mb_strpos($messageLower, $keywordLower) !== false) {
                Log::debug('[FormTrigger] Keyword match found', [
                    'form_id' => $form->id,
                    'keyword' => $keyword,
                    'message' => $message
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica trigger per numero di messaggi
     */
    private function checkMessageCountTrigger(TenantForm $form, int $messageCount): bool
    {
        if (!$form->trigger_after_messages) {
            return false;
        }

        return $messageCount >= $form->trigger_after_messages;
    }

    /**
     * Verifica trigger per domande specifiche
     */
    private function checkQuestionTrigger(TenantForm $form, string $message): bool
    {
        if (!$form->trigger_after_questions || empty($form->trigger_after_questions)) {
            return false;
        }

        $messageLower = mb_strtolower($message);
        
        foreach ($form->trigger_after_questions as $triggerQuestion) {
            $questionLower = mb_strtolower(trim($triggerQuestion));
            
            if (!$questionLower) {
                continue;
            }

            // Calcola similarità usando similar_text
            $similarity = 0;
            similar_text($questionLower, $messageLower, $similarity);
            
            // Se la similarità è >= 70%, considera match
            if ($similarity >= 70) {
                Log::debug('[FormTrigger] Question similarity match', [
                    'form_id' => $form->id,
                    'trigger_question' => $triggerQuestion,
                    'user_message' => $message,
                    'similarity' => $similarity
                ]);
                return true;
            }

            // Controlla anche se il messaggio contiene parole chiave della domanda trigger
            $triggerWords = explode(' ', $questionLower);
            $triggerWords = array_filter($triggerWords, function($word) {
                return mb_strlen($word) > 3; // Solo parole di almeno 4 caratteri
            });

            if (count($triggerWords) > 0) {
                $matchedWords = 0;
                foreach ($triggerWords as $word) {
                    if (mb_strpos($messageLower, $word) !== false) {
                        $matchedWords++;
                    }
                }

                $wordMatchPercentage = ($matchedWords / count($triggerWords)) * 100;
                if ($wordMatchPercentage >= 60) { // Se almeno il 60% delle parole chiave matchano
                    Log::debug('[FormTrigger] Question keyword match', [
                        'form_id' => $form->id,
                        'trigger_question' => $triggerQuestion,
                        'user_message' => $message,
                        'word_match_percentage' => $wordMatchPercentage
                    ]);
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Costruisce la risposta del trigger
     */
    private function buildTriggerResponse(TenantForm $form, string $triggerType, string $triggerValue): array
    {
        return [
            'form_id' => $form->id,
            'form_name' => $form->name,
            'form_description' => $form->description,
            'trigger_type' => $triggerType,
            'trigger_value' => $triggerValue,
            'fields' => $form->fields->map(function ($field) {
                return [
                    'id' => $field->id,
                    'name' => $field->name,
                    'label' => $field->label,
                    'type' => $field->type,
                    'placeholder' => $field->placeholder,
                    'required' => $field->required,
                    'help_text' => $field->help_text,
                    'options' => $field->options,
                    'order' => $field->order,
                ];
            })->sortBy('order')->values()->toArray(),
            'message' => $this->generateTriggerMessage($form, $triggerType),
        ];
    }

    /**
     * Genera il messaggio da mostrare quando si attiva il form
     */
    private function generateTriggerMessage(TenantForm $form, string $triggerType): string
    {
        $messages = [
            'keyword' => "Ho notato che potresti aver bisogno di assistenza per **{$form->name}**. Posso aiutarti compilando questo form:",
            'auto' => "Ti va di compilare un breve form per **{$form->name}**? Potrebbe aiutarmi a fornirti un'assistenza più mirata:",
            'question' => "Per rispondere al meglio alla tua domanda su **{$form->name}**, potrei aver bisogno di alcune informazioni aggiuntive:",
            'manual' => "Ecco il form per **{$form->name}**:",
        ];

        return $messages[$triggerType] ?? $messages['manual'];
    }

    /**
     * Verifica se un form può essere triggerato manualmente dall'admin
     */
    public function canTriggerManually(int $formId, string $sessionId): bool
    {
        $form = TenantForm::active()->find($formId);
        if (!$form) {
            return false;
        }

        // Controlla se l'utente ha già un form pending in questa sessione
        $existingSubmission = FormSubmission::forSession($sessionId)
            ->forTenant($form->tenant_id)
            ->pending()
            ->first();

        return !$existingSubmission;
    }

    /**
     * Triggera manualmente un form (per test o admin)
     */
    public function triggerManually(int $formId): ?array
    {
        $form = TenantForm::active()->with('fields')->find($formId);
        if (!$form) {
            return null;
        }

        Log::info('[FormTrigger] Manual trigger activated', [
            'form_id' => $form->id,
            'form_name' => $form->name,
        ]);

        return $this->buildTriggerResponse($form, 'manual', 'Admin triggered');
    }

    /**
     * Ottieni statistiche trigger per un form
     */
    public function getTriggerStats(int $formId): array
    {
        $submissions = FormSubmission::where('tenant_form_id', $formId)->get();

        $stats = [
            'total' => $submissions->count(),
            'by_trigger_type' => $submissions->groupBy('trigger_type')->map->count(),
            'conversion_rate' => 0,
        ];

        // Calcola conversion rate (submitted vs responded)
        if ($stats['total'] > 0) {
            $responded = $submissions->where('status', FormSubmission::STATUS_RESPONDED)->count();
            $stats['conversion_rate'] = round(($responded / $stats['total']) * 100, 2);
        }

        return $stats;
    }
}
