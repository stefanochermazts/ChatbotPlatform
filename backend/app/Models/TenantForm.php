<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'active',
        'trigger_keywords',
        'trigger_after_messages',
        'trigger_after_questions',
        'user_confirmation_email_subject',
        'user_confirmation_email_body',
        'email_logo_path',
        'admin_notification_email',
        'auto_response_enabled',
        'auto_response_message',
    ];

    protected $casts = [
        'active' => 'boolean',
        'trigger_keywords' => 'array',
        'trigger_after_questions' => 'array',
        'trigger_after_messages' => 'integer',
        'auto_response_enabled' => 'boolean',
    ];

    /**
     * Relazione con Tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relazione con FormField
     */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('order');
    }

    /**
     * Relazione con FormSubmission
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Scope per form attivi
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope per tenant specifico
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Verifica se il form può essere triggerato da una parola chiave
     */
    public function canBeTriggeredByKeyword(string $text): bool
    {
        if (!$this->active || empty($this->trigger_keywords)) {
            return false;
        }

        $textLower = mb_strtolower($text);
        
        foreach ($this->trigger_keywords as $keyword) {
            if (mb_stripos($textLower, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se il form può essere triggerato dopo N messaggi
     */
    public function canBeTriggeredByMessageCount(int $messageCount): bool
    {
        return $this->active 
            && $this->trigger_after_messages 
            && $messageCount >= $this->trigger_after_messages;
    }

    /**
     * Verifica se il form può essere triggerato da una domanda specifica
     */
    public function canBeTriggeredByQuestion(string $question): bool
    {
        if (!$this->active || empty($this->trigger_after_questions)) {
            return false;
        }

        $questionLower = mb_strtolower($question);

        foreach ($this->trigger_after_questions as $triggerQuestion) {
            $similarity = similar_text(
                mb_strtolower($triggerQuestion), 
                $questionLower, 
                $percent
            );
            
            // Se la similarity è >= 80%, considera match
            if ($percent >= 80) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ottieni template email di conferma con placeholder sostituiti
     */
    public function getConfirmationEmailBody(array $formData = []): string
    {
        $template = $this->user_confirmation_email_body ?? 
            "Gentile utente,\n\nabbiamo ricevuto la sua richiesta tramite il chatbot.\n\nDati inviati:\n{form_data}\n\nLa contatteremo al più presto.\n\nCordiali saluti,\n{tenant_name}";

        // Sostituisci placeholder
        $template = str_replace('{tenant_name}', $this->tenant->name, $template);
        $template = str_replace('{form_name}', $this->name, $template);
        
        // Formatta i dati del form
        $formDataText = '';
        foreach ($formData as $key => $value) {
            $formDataText .= "- {$key}: {$value}\n";
        }
        
        $template = str_replace('{form_data}', $formDataText, $template);

        return $template;
    }

    /**
     * Ottieni numero di sottomissioni pending
     */
    public function getPendingSubmissionsCountAttribute(): int
    {
        return $this->submissions()->where('status', 'pending')->count();
    }

    /**
     * Ottieni numero totale di sottomissioni
     */
    public function getTotalSubmissionsCountAttribute(): int
    {
        return $this->submissions()->count();
    }
}