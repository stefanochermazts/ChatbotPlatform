<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantForm;
use App\Models\FormField;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TenantFormController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $user = auth()->user();
        
        $query = TenantForm::with(['tenant', 'fields'])
            ->withCount(['submissions as pending_count' => function ($query) {
                $query->where('status', 'pending');
            }])
            ->withCount('submissions as total_count');

        // Auto-scoping per clienti
        if (!$user->isAdmin()) {
            $userTenantIds = $user->tenants()->wherePivot('role', 'customer')->pluck('tenant_id');
            $query->whereIn('tenant_id', $userTenantIds);
        } else {
            // Filtri per admin
            if ($request->filled('tenant_id')) {
                $query->where('tenant_id', $request->tenant_id);
            }
        }

        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('description', 'ILIKE', "%{$search}%");
            });
        }

        $forms = $query->orderBy('created_at', 'desc')->paginate(15);
        $tenants = Tenant::orderBy('name')->get();

        return view('admin.forms.index', compact('forms', 'tenants'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $tenants = Tenant::orderBy('name')->get();
        $fieldTypes = FormField::FIELD_TYPES;

        return view('admin.forms.create', compact('tenants', 'fieldTypes'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'active' => 'boolean',
            
            // Trigger settings - accetta anche textarea
            'trigger_keywords' => 'nullable|array',
            'trigger_keywords.*' => 'string|max:255',
            'trigger_keywords_text' => 'nullable|string',
            'trigger_after_messages' => 'nullable|integer|min:1|max:100',
            'trigger_after_questions' => 'nullable|array',
            'trigger_after_questions.*' => 'string|max:500',
            'trigger_questions_text' => 'nullable|string',
            
            // Email settings
            'user_confirmation_email_subject' => 'required|string|max:255',
            'user_confirmation_email_body' => 'nullable|string',
            'admin_notification_email' => 'nullable|email|max:255',
            'auto_response_enabled' => 'boolean',
            'auto_response_message' => 'nullable|string',
            
            // Form fields
            'fields' => 'required|array|min:1',
            'fields.*.name' => 'required|string|max:100',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.type' => 'required|in:text,email,phone,textarea,select,checkbox,radio,date,number',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.required' => 'boolean',
            'fields.*.help_text' => 'nullable|string',
            'fields.*.options' => 'nullable|array',
            'fields.*.validation_rules' => 'nullable|array',
        ]);

        // Converti textarea in array se presenti
        if (!empty($validated['trigger_keywords_text'])) {
            $validated['trigger_keywords'] = array_filter(
                array_map('trim', explode("\n", $validated['trigger_keywords_text']))
            );
        } else {
            $validated['trigger_keywords'] = array_filter($validated['trigger_keywords'] ?? []);
        }
        
        if (!empty($validated['trigger_questions_text'])) {
            $validated['trigger_after_questions'] = array_filter(
                array_map('trim', explode("\n", $validated['trigger_questions_text']))
            );
        } else {
            $validated['trigger_after_questions'] = array_filter($validated['trigger_after_questions'] ?? []);
        }

        // Rimuovi i campi textarea dal validated array
        unset($validated['trigger_keywords_text'], $validated['trigger_questions_text']);

        $form = TenantForm::create($validated);

        // Crea i campi del form
        foreach ($validated['fields'] as $index => $fieldData) {
            $fieldData['tenant_form_id'] = $form->id;
            $fieldData['order'] = $index;
            $fieldData['active'] = true;
            
            // Pulisci opzioni vuote
            if (isset($fieldData['options'])) {
                $fieldData['options'] = array_filter($fieldData['options']);
                if (empty($fieldData['options'])) {
                    $fieldData['options'] = null;
                }
            }

            FormField::create($fieldData);
        }

        return redirect()
            ->route('admin.forms.show', $form)
            ->with('success', 'Form creato con successo!');
    }

    /**
     * Display the specified resource.
     */
    public function show(TenantForm $form): View
    {
        $form->load([
            'tenant',
            'fields' => function ($query) {
                $query->orderBy('order');
            },
            'submissions' => function ($query) {
                $query->with('responses')->latest()->limit(10);
            }
        ]);

        $stats = [
            'total_submissions' => $form->submissions()->count(),
            'pending_submissions' => $form->submissions()->pending()->count(),
            'responded_submissions' => $form->submissions()->responded()->count(),
            'closed_submissions' => $form->submissions()->closed()->count(),
        ];

        return view('admin.forms.show', compact('form', 'stats'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TenantForm $form): View
    {
        $form->load('fields');
        $tenants = Tenant::orderBy('name')->get();
        $fieldTypes = FormField::FIELD_TYPES;

        return view('admin.forms.edit', compact('form', 'tenants', 'fieldTypes'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TenantForm $form): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'active' => 'boolean',
            
            // Trigger settings - accetta anche textarea
            'trigger_keywords' => 'nullable|array',
            'trigger_keywords.*' => 'string|max:255',
            'trigger_keywords_text' => 'nullable|string',
            'trigger_after_messages' => 'nullable|integer|min:1|max:100',
            'trigger_after_questions' => 'nullable|array',
            'trigger_after_questions.*' => 'string|max:500',
            'trigger_questions_text' => 'nullable|string',
            
            // Email settings
            'user_confirmation_email_subject' => 'required|string|max:255',
            'user_confirmation_email_body' => 'nullable|string',
            'admin_notification_email' => 'nullable|email|max:255',
            'auto_response_enabled' => 'boolean',
            'auto_response_message' => 'nullable|string',
            
            // Form fields
            'fields' => 'required|array|min:1',
            'fields.*.id' => 'nullable|exists:form_fields,id',
            'fields.*.name' => 'required|string|max:100',
            'fields.*.label' => 'required|string|max:255',
            'fields.*.type' => 'required|in:text,email,phone,textarea,select,checkbox,radio,date,number',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.required' => 'boolean',
            'fields.*.help_text' => 'nullable|string',
            'fields.*.options' => 'nullable|array',
            'fields.*.validation_rules' => 'nullable|array',
        ]);

        // Converti textarea in array se presenti (UPDATE)
        if (!empty($validated['trigger_keywords_text'])) {
            $validated['trigger_keywords'] = array_filter(
                array_map('trim', explode("\n", $validated['trigger_keywords_text']))
            );
            \Log::info('[FormUpdate] Keywords converted from textarea', [
                'raw' => $validated['trigger_keywords_text'],
                'parsed' => $validated['trigger_keywords']
            ]);
        } else {
            $validated['trigger_keywords'] = array_filter($validated['trigger_keywords'] ?? []);
        }
        
        if (!empty($validated['trigger_questions_text'])) {
            $validated['trigger_after_questions'] = array_filter(
                array_map('trim', explode("\n", $validated['trigger_questions_text']))
            );
            \Log::info('[FormUpdate] Questions converted from textarea', [
                'raw' => $validated['trigger_questions_text'],
                'parsed' => $validated['trigger_after_questions']
            ]);
        } else {
            $validated['trigger_after_questions'] = array_filter($validated['trigger_after_questions'] ?? []);
        }

        // Rimuovi i campi textarea dal validated array
        unset($validated['trigger_keywords_text'], $validated['trigger_questions_text']);

        $form->update($validated);

        // Aggiorna campi del form
        $existingFieldIds = collect($validated['fields'])
            ->pluck('id')
            ->filter()
            ->toArray();

        // Cancella campi rimossi
        $form->fields()->whereNotIn('id', $existingFieldIds)->delete();

        // Aggiorna o crea campi
        foreach ($validated['fields'] as $index => $fieldData) {
            $fieldData['tenant_form_id'] = $form->id;
            $fieldData['order'] = $index;
            $fieldData['active'] = true;
            
            // Pulisci opzioni vuote
            if (isset($fieldData['options'])) {
                $fieldData['options'] = array_filter($fieldData['options']);
                if (empty($fieldData['options'])) {
                    $fieldData['options'] = null;
                }
            }

            if (isset($fieldData['id']) && $fieldData['id']) {
                // Aggiorna campo esistente
                $field = FormField::find($fieldData['id']);
                if ($field && $field->tenant_form_id === $form->id) {
                    unset($fieldData['id']);
                    $field->update($fieldData);
                }
            } else {
                // Crea nuovo campo
                unset($fieldData['id']);
                FormField::create($fieldData);
            }
        }

        return redirect()
            ->route('admin.forms.show', $form)
            ->with('success', 'Form aggiornato con successo!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TenantForm $form): RedirectResponse
    {
        $submissionsCount = $form->submissions()->count();
        
        if ($submissionsCount > 0) {
            return redirect()
                ->route('admin.forms.show', $form)
                ->with('error', "Impossibile eliminare il form: ci sono {$submissionsCount} sottomissioni associate.");
        }

        $form->delete();

        return redirect()
            ->route('admin.forms.index')
            ->with('success', 'Form eliminato con successo!');
    }

    /**
     * Toggle form active status
     */
    public function toggleActive(TenantForm $form): RedirectResponse
    {
        $form->update(['active' => !$form->active]);

        $status = $form->active ? 'attivato' : 'disattivato';
        
        return redirect()
            ->back()
            ->with('success', "Form {$status} con successo!");
    }

    /**
     * Preview form for testing
     */
    public function preview(TenantForm $form): View
    {
        $form->load('fields');
        
        return view('admin.forms.preview', compact('form'));
    }

    /**
     * Test form submission (for admin testing)
     */
    public function testSubmit(Request $request, TenantForm $form)
    {
        // Valida i dati del form usando i campi definiti
        $rules = [];
        foreach ($form->fields as $field) {
            $rules[$field->name] = $field->getValidationRulesForLaravel();
        }

        $validated = $request->validate($rules);

        // Non salvare, solo restituire successo per test
        return response()->json([
            'success' => true,
            'message' => 'Form valido! (Test mode - dati non salvati)',
            'data' => $validated,
        ]);
    }
}