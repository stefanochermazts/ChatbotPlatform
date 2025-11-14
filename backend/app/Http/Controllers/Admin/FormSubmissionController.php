<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\FormAdminResponseMail;
use App\Models\FormResponse;
use App\Models\FormSubmission;
use App\Models\TenantForm;
use App\Services\Form\FormSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * 📊 FormSubmissionController - Gestione sottomissioni admin
 * Dashboard per visualizzare, filtrare e rispondere alle sottomissioni
 */
class FormSubmissionController extends Controller
{
    public function __construct(
        private FormSubmissionService $submissionService
    ) {
        // Autenticazione gestita dal middleware EnsureAdminToken a livello route
    }

    /**
     * 📋 Lista sottomissioni con filtri
     */
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,responded,closed',
            'form_id' => 'nullable|integer|exists:tenant_forms,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        // Ricava tenant_id dinamicamente dal form_id se presente
        $tenantId = null;
        if (! empty($validated['form_id'])) {
            $form = TenantForm::find($validated['form_id']);
            if ($form) {
                $tenantId = $form->tenant_id;
            }
        }

        // TODO: Implementare gestione admin per vedere tutti i tenant
        // Per ora se non c'è form_id specifico, default al tenant 5
        if (! $tenantId) {
            $tenantId = 5; // Fallback per admin
        }

        $filters = array_filter($validated);
        $submissions = $this->submissionService->getSubmissions($tenantId, $filters);
        $stats = $this->submissionService->getSubmissionStats($tenantId);

        // Carica form per filtro dropdown
        // Se abbiamo un tenant specifico, filtra per quel tenant
        // Altrimenti carica tutti i form per admin
        if ($tenantId) {
            $forms = TenantForm::forTenant($tenantId)
                ->select('id', 'name', 'tenant_id')
                ->orderBy('name')
                ->get();
        } else {
            $forms = TenantForm::with('tenant:id,name')
                ->select('id', 'name', 'tenant_id')
                ->orderBy('name')
                ->get();
        }

        return view('admin.forms.submissions.index', compact(
            'submissions',
            'stats',
            'forms',
            'validated'
        ));
    }

    /**
     * 👁️ Visualizza dettagli sottomissione
     */
    public function show(FormSubmission $submission): View
    {
        // TODO: Implementare policy check per accesso tenant

        $submission->load([
            'tenantForm',
            'tenant',
            'responses' => function ($query) {
                $query->orderBy('created_at', 'desc');
            },
            'responses.adminUser',
        ]);

        return view('admin.forms.submissions.show', compact('submission'));
    }

    /**
     * 💬 Form per rispondere a sottomissione
     */
    public function respond(FormSubmission $submission): View
    {
        // TODO: Implementare policy check per accesso tenant

        $submission->load(['tenantForm', 'tenant']);

        return view('admin.forms.submissions.respond', compact('submission'));
    }

    /**
     * 📤 Invia risposta alla sottomissione
     */
    public function sendResponse(Request $request, FormSubmission $submission): RedirectResponse
    {
        $validated = $request->validate([
            'response_content' => 'required|string|max:5000',
            'response_type' => 'required|in:web,email',
            'email_subject' => 'required_if:response_type,email|string|max:255',
            'closes_submission' => 'boolean',
            'internal_notes' => 'nullable|string|max:1000',
        ]);

        try {
            // Genera thread ID se non esiste
            $threadId = $submission->responses()->exists()
                ? $submission->responses()->first()->thread_id
                : FormResponse::generateThreadId();

            // Determina se è thread starter
            $isThreadStarter = ! $submission->responses()->exists();

            // Crea la risposta
            $response = FormResponse::create([
                'form_submission_id' => $submission->id,
                'admin_user_id' => Auth::id(),
                'response_content' => $validated['response_content'],
                'response_type' => $validated['response_type'],
                'email_subject' => $validated['email_subject'] ?? null,
                'closes_submission' => $validated['closes_submission'] ?? false,
                'internal_notes' => $validated['internal_notes'] ?? null,
                // Threading fields
                'thread_id' => $threadId,
                'is_thread_starter' => $isThreadStarter,
                'priority' => $request->get('priority', FormResponse::PRIORITY_NORMAL),
            ]);

            // Invia email se richiesto
            if ($validated['response_type'] === 'email' && $submission->user_email) {
                // Genera Message-ID per threading
                $response->email_message_id = $response->generateEmailMessageId();
                $response->email_references = $response->generateEmailReferences();
                $response->save();

                $this->sendResponseEmail($submission, $response);
                $response->markEmailSent();
                $response->markUserNotified();
            }

            // Aggiorna statistiche submission
            $submission->updateResponseStats($response);

            // Aggiorna status della sottomissione
            if ($validated['closes_submission']) {
                $submission->close();
                $submission->deactivateConversation();
            } else {
                $submission->markAsResponded();
                $submission->activateConversation($response->priority);
            }

            $message = $validated['response_type'] === 'email'
                ? 'Risposta inviata via email con successo!'
                : 'Risposta registrata con successo!';

            return redirect()
                ->route('admin.forms.submissions.show', $submission)
                ->with('success', $message);

        } catch (\Exception $e) {
            return back()
                ->withInput()
                ->with('error', 'Errore nell\'invio della risposta: '.$e->getMessage());
        }
    }

    /**
     * 🔄 Cambia status sottomissione
     */
    public function updateStatus(Request $request, FormSubmission $submission): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,responded,closed',
        ]);

        $submission->update(['status' => $validated['status']]);

        $statusLabels = [
            'pending' => 'In attesa',
            'responded' => 'Risposta inviata',
            'closed' => 'Chiusa',
        ];

        return back()->with('success',
            "Status aggiornato a: {$statusLabels[$validated['status']]}"
        );
    }

    /**
     * 🗑️ Elimina sottomissione
     */
    public function destroy(FormSubmission $submission): RedirectResponse
    {
        // TODO: Implementare policy check per accesso tenant

        try {
            $submission->delete();

            return redirect()
                ->route('admin.forms.submissions.index')
                ->with('success', 'Sottomissione eliminata con successo');

        } catch (\Exception $e) {
            return back()->with('error', 'Errore nell\'eliminazione: '.$e->getMessage());
        }
    }

    /**
     * 📊 Export sottomissioni (CSV)
     */
    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|in:pending,responded,closed',
            'form_id' => 'nullable|integer|exists:tenant_forms,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        // Ricava tenant_id dinamicamente dal form_id se presente
        $tenantId = null;
        if (! empty($validated['form_id'])) {
            $form = TenantForm::find($validated['form_id']);
            if ($form) {
                $tenantId = $form->tenant_id;
            }
        }

        // TODO: Implementare gestione admin per vedere tutti i tenant
        // Per ora se non c'è form_id specifico, default al tenant 5
        if (! $tenantId) {
            $tenantId = 5; // Fallback per admin
        }

        $filters = array_filter($validated);
        $filters['per_page'] = 1000; // Export massimo 1000 record

        $submissions = $this->submissionService->getSubmissions($tenantId, $filters);

        $filename = 'sottomissioni_'.date('Y-m-d_H-i-s').'.csv';

        return response()->streamDownload(function () use ($submissions) {
            $handle = fopen('php://output', 'w');

            // Header CSV
            fputcsv($handle, [
                'ID',
                'Form',
                'Data Sottomissione',
                'Status',
                'Nome Utente',
                'Email Utente',
                'Trigger',
                'Dati Form',
                'Numero Risposte',
            ]);

            // Dati
            foreach ($submissions as $submission) {
                $formData = '';
                foreach ($submission->getFormattedDataAttribute() as $field) {
                    $formData .= "{$field['label']}: {$field['value']}; ";
                }

                fputcsv($handle, [
                    $submission->id,
                    $submission->tenantForm->name,
                    $submission->submitted_at->format('d/m/Y H:i'),
                    $submission->getStatusLabelAttribute(),
                    $submission->user_name ?? 'N/A',
                    $submission->user_email ?? 'N/A',
                    $submission->getTriggerDescriptionAttribute(),
                    trim($formData),
                    $submission->responses()->count(),
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * 📧 Invia email di risposta
     */
    private function sendResponseEmail(FormSubmission $submission, FormResponse $response): void
    {
        $mail = new FormAdminResponseMail($submission, $response);
        Mail::to($submission->user_email)->send($mail);
    }

    /**
     * 📈 Statistiche avanzate (AJAX)
     */
    public function stats(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|in:today,week,month,quarter,year',
            'form_id' => 'nullable|integer|exists:tenant_forms,id',
        ]);

        // Ricava tenant_id dinamicamente dal form_id se presente
        $tenantId = null;
        $formId = $validated['form_id'] ?? null;

        if ($formId) {
            $form = TenantForm::find($formId);
            if ($form) {
                $tenantId = $form->tenant_id;
            }
        }

        // TODO: Implementare gestione admin per vedere tutti i tenant
        // Per ora se non c'è form_id specifico, default al tenant 5
        if (! $tenantId) {
            $tenantId = 5; // Fallback per admin
        }

        $period = $validated['period'] ?? 'month';

        // Calcola statistiche per il periodo
        $baseQuery = FormSubmission::forTenant($tenantId);

        if ($formId) {
            $baseQuery->where('tenant_form_id', $formId);
        }

        switch ($period) {
            case 'today':
                $baseQuery->whereDate('submitted_at', today());
                break;
            case 'week':
                $baseQuery->whereBetween('submitted_at', [
                    now()->startOfWeek(),
                    now()->endOfWeek(),
                ]);
                break;
            case 'month':
                $baseQuery->whereMonth('submitted_at', now()->month)
                    ->whereYear('submitted_at', now()->year);
                break;
            case 'quarter':
                $baseQuery->whereBetween('submitted_at', [
                    now()->startOfQuarter(),
                    now()->endOfQuarter(),
                ]);
                break;
            case 'year':
                $baseQuery->whereYear('submitted_at', now()->year);
                break;
        }

        $stats = [
            'total' => $baseQuery->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'responded' => (clone $baseQuery)->where('status', 'responded')->count(),
            'closed' => (clone $baseQuery)->where('status', 'closed')->count(),
            'response_rate' => 0,
            'avg_response_time' => 0, // TODO: Calcolare tempo medio di risposta
        ];

        if ($stats['total'] > 0) {
            $stats['response_rate'] = round(
                (($stats['responded'] + $stats['closed']) / $stats['total']) * 100,
                1
            );
        }

        return response()->json($stats);
    }
}
