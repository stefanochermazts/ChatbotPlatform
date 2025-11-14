<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_form_id',
        'name',
        'label',
        'type',
        'placeholder',
        'required',
        'validation_rules',
        'options',
        'help_text',
        'order',
        'active',
    ];

    protected $casts = [
        'required' => 'boolean',
        'active' => 'boolean',
        'validation_rules' => 'array',
        'options' => 'array',
        'order' => 'integer',
    ];

    /**
     * Tipi di campo supportati
     */
    public const FIELD_TYPES = [
        'text' => 'Testo',
        'email' => 'Email',
        'phone' => 'Telefono',
        'textarea' => 'Area di testo',
        'select' => 'Selezione',
        'checkbox' => 'Checkbox',
        'radio' => 'Radio button',
        'date' => 'Data',
        'number' => 'Numero',
    ];

    /**
     * Relazione con TenantForm
     */
    public function tenantForm(): BelongsTo
    {
        return $this->belongsTo(TenantForm::class);
    }

    /**
     * Scope per campi attivi
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope per ordinamento
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Ottieni regole di validazione Laravel
     */
    public function getValidationRulesForLaravel(): array
    {
        $rules = [];

        // Required
        if ($this->required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        // Type-specific validation
        switch ($this->type) {
            case 'email':
                $rules[] = 'email';
                break;
            case 'phone':
                $rules[] = 'regex:/^[\+]?[0-9\s\-\(\)]+$/';
                break;
            case 'date':
                $rules[] = 'date';
                break;
            case 'number':
                $rules[] = 'numeric';
                break;
            case 'select':
            case 'radio':
                if (! empty($this->options)) {
                    $validOptions = array_keys($this->options);
                    $rules[] = 'in:'.implode(',', $validOptions);
                }
                break;
        }

        // Custom validation rules
        if ($this->validation_rules) {
            $rules = array_merge($rules, $this->validation_rules);
        }

        return $rules;
    }

    /**
     * Genera HTML input per il campo
     */
    public function renderInputHtml(string $value = '', array $errors = []): string
    {
        $id = "field_{$this->name}";
        $name = $this->name;
        $label = htmlspecialchars($this->label);
        $placeholder = htmlspecialchars($this->placeholder ?? '');
        $required = $this->required ? 'required' : '';
        $value = htmlspecialchars($value);
        $hasError = ! empty($errors);
        $errorClass = $hasError ? 'border-red-500' : 'border-gray-300';

        $html = "<div class=\"mb-4\">\n";
        $html .= "  <label for=\"{$id}\" class=\"block text-sm font-medium text-gray-700 mb-1\">{$label}";
        if ($this->required) {
            $html .= ' <span class="text-red-500">*</span>';
        }
        $html .= "</label>\n";

        switch ($this->type) {
            case 'textarea':
                $html .= "  <textarea id=\"{$id}\" name=\"{$name}\" placeholder=\"{$placeholder}\" {$required} class=\"w-full px-3 py-2 {$errorClass} rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500\">{$value}</textarea>\n";
                break;

            case 'select':
                $html .= "  <select id=\"{$id}\" name=\"{$name}\" {$required} class=\"w-full px-3 py-2 {$errorClass} rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500\">\n";
                $html .= "    <option value=\"\">Seleziona...</option>\n";
                if ($this->options) {
                    foreach ($this->options as $optValue => $optLabel) {
                        $selected = $value === $optValue ? 'selected' : '';
                        $html .= "    <option value=\"{$optValue}\" {$selected}>{$optLabel}</option>\n";
                    }
                }
                $html .= "  </select>\n";
                break;

            case 'checkbox':
                if ($this->options) {
                    $selectedValues = is_array($value) ? $value : explode(',', $value);
                    foreach ($this->options as $optValue => $optLabel) {
                        $checked = in_array($optValue, $selectedValues) ? 'checked' : '';
                        $html .= "  <div class=\"flex items-center mb-2\">\n";
                        $html .= "    <input type=\"checkbox\" id=\"{$id}_{$optValue}\" name=\"{$name}[]\" value=\"{$optValue}\" {$checked} class=\"mr-2\">\n";
                        $html .= "    <label for=\"{$id}_{$optValue}\" class=\"text-sm text-gray-700\">{$optLabel}</label>\n";
                        $html .= "  </div>\n";
                    }
                }
                break;

            case 'radio':
                if ($this->options) {
                    foreach ($this->options as $optValue => $optLabel) {
                        $checked = $value === $optValue ? 'checked' : '';
                        $html .= "  <div class=\"flex items-center mb-2\">\n";
                        $html .= "    <input type=\"radio\" id=\"{$id}_{$optValue}\" name=\"{$name}\" value=\"{$optValue}\" {$checked} class=\"mr-2\">\n";
                        $html .= "    <label for=\"{$id}_{$optValue}\" class=\"text-sm text-gray-700\">{$optLabel}</label>\n";
                        $html .= "  </div>\n";
                    }
                }
                break;

            default:
                $type = $this->type === 'phone' ? 'tel' : $this->type;
                $html .= "  <input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" placeholder=\"{$placeholder}\" {$required} class=\"w-full px-3 py-2 {$errorClass} rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500\">\n";
                break;
        }

        // Help text
        if ($this->help_text) {
            $html .= '  <p class="mt-1 text-xs text-gray-500">'.htmlspecialchars($this->help_text)."</p>\n";
        }

        // Error messages
        if ($hasError) {
            foreach ($errors as $error) {
                $html .= "  <p class=\"mt-1 text-xs text-red-500\">{$error}</p>\n";
            }
        }

        $html .= "</div>\n";

        return $html;
    }

    /**
     * Valida un valore per questo campo
     */
    public function validateValue($value): array
    {
        $validator = validator(
            [$this->name => $value],
            [$this->name => $this->getValidationRulesForLaravel()]
        );

        return $validator->errors()->get($this->name);
    }
}
