<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuickAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'action_type', 'label', 'icon', 'description',
        'action_method', 'action_url', 'action_payload', 'required_fields',
        'display_order', 'is_enabled', 'requires_auth', 'button_style', 'confirmation_message',
        'rate_limit_per_user', 'rate_limit_global',
        'success_message', 'success_action', 'success_url', 'error_message',
        'requires_jwt', 'jwt_expiry_minutes', 'requires_hmac',
        'custom_config', 'last_used_at', 'total_executions',
    ];

    protected $casts = [
        'action_payload' => 'array',
        'required_fields' => 'array',
        'custom_config' => 'array',
        'is_enabled' => 'boolean',
        'requires_auth' => 'boolean',
        'requires_jwt' => 'boolean',
        'requires_hmac' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(QuickActionExecution::class);
    }

    // Scopes
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('label');
    }

    // Helper Methods
    public function canExecute(): bool
    {
        return $this->is_enabled;
    }

    public function incrementExecutions(): void
    {
        $this->increment('total_executions');
        $this->update(['last_used_at' => now()]);
    }

    public function getResolvedActionUrl(): ?string
    {
        if (! $this->action_url) {
            return null;
        }

        // If it's a relative URL, prepend the tenant's base URL
        if (str_starts_with($this->action_url, '/')) {
            return url($this->action_url);
        }

        return $this->action_url;
    }

    public function getRequiredFieldsForDisplay(): array
    {
        $fields = $this->required_fields ?? [];

        // Map field names to display labels
        $fieldLabels = [
            'email' => 'Email',
            'phone' => 'Telefono',
            'name' => 'Nome',
            'company' => 'Azienda',
            'message' => 'Messaggio',
            'subject' => 'Oggetto',
        ];

        return array_map(function ($field) use ($fieldLabels) {
            return [
                'name' => $field,
                'label' => $fieldLabels[$field] ?? ucfirst($field),
                'required' => true,
            ];
        }, $fields);
    }

    // Static methods for default actions
    public static function getDefaultActionsForTenant(int $tenantId): array
    {
        return [
            [
                'tenant_id' => $tenantId,
                'action_type' => 'contact_support',
                'label' => 'Contatta Supporto',
                'icon' => '💬',
                'description' => 'Invia un messaggio al team di supporto',
                'action_method' => 'POST',
                'action_url' => '/api/v1/quick-actions/contact-support',
                'required_fields' => ['email', 'name', 'message'],
                'display_order' => 1,
                'button_style' => 'primary',
                'confirmation_message' => 'Vuoi inviare una richiesta di supporto?',
                'success_message' => 'La tua richiesta è stata inviata con successo!',
                'success_action' => 'message',
            ],
            [
                'tenant_id' => $tenantId,
                'action_type' => 'request_callback',
                'label' => 'Richiedi Richiamata',
                'icon' => '📞',
                'description' => 'Richiedi di essere ricontattato dal nostro team',
                'action_method' => 'POST',
                'action_url' => '/api/v1/quick-actions/request-callback',
                'required_fields' => ['phone', 'name'],
                'display_order' => 2,
                'button_style' => 'secondary',
                'confirmation_message' => 'Vuoi richiedere una richiamata?',
                'success_message' => 'Ti ricontatteremo il prima possibile!',
                'success_action' => 'message',
            ],
            [
                'tenant_id' => $tenantId,
                'action_type' => 'download_brochure',
                'label' => 'Scarica Brochure',
                'icon' => '📄',
                'description' => 'Scarica la nostra brochure informativa',
                'action_method' => 'GET',
                'action_url' => '/api/v1/quick-actions/download-brochure',
                'required_fields' => ['email'],
                'display_order' => 3,
                'button_style' => 'outline',
                'success_message' => 'Download avviato!',
                'success_action' => 'download',
            ],
        ];
    }
}
