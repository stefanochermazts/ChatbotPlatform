<?php

namespace App\Mail;

use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 📢 FormAdminNotificationMail
 * Email di notifica inviata agli admin quando viene sottomesso un form
 */
class FormAdminNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public FormSubmission $submission
    ) {
        // Set timeout for mail sending
        $this->timeout = 30;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $form = $this->submission->tenantForm;
        $tenant = $this->submission->tenant;

        $subject = "🚨 Nuova sottomissione form: {$form->name}";
        
        if ($this->submission->user_email) {
            $subject .= " da {$this->submission->user_email}";
        }

        return new Envelope(
            from: config('mail.from.address', 'noreply@chatbotplatform.com'),
            subject: $subject,
            replyTo: $this->submission->user_email 
                ? [$this->submission->user_email]
                : null
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.form-admin-notification'
        );
    }

    /**
     * Get the data that should be passed to the view.
     */
    public function viewData(): array
    {
        $form = $this->submission->tenantForm;
        $tenant = $this->submission->tenant;

        return [
            'submission' => $this->submission,
            'form' => $form,
            'tenant' => $tenant,
            'formattedData' => $this->submission->getFormattedDataAttribute(),
            'submissionDate' => $this->submission->submitted_at->format('d/m/Y H:i:s'),
            'triggerDescription' => $this->submission->getTriggerDescriptionAttribute(),
            'chatContext' => $this->getChatContextSummary(),
            'adminDashboardUrl' => $this->getAdminDashboardUrl(),
            'respondUrl' => $this->getRespondUrl(),
            'userInfo' => $this->getUserInfo(),
            'urgencyLevel' => $this->determineUrgencyLevel(),
        ];
    }

    /**
     * Ottieni informazioni utente
     */
    private function getUserInfo(): array
    {
        return [
            'name' => $this->submission->user_name ?? 'Non fornito',
            'email' => $this->submission->user_email ?? 'Non fornito',
            'ip' => $this->submission->ip_address ?? 'Non disponibile',
            'user_agent' => $this->submission->user_agent ?? 'Non disponibile',
            'session_id' => $this->submission->session_id,
        ];
    }

    /**
     * Ottieni riassunto contesto chat
     */
    private function getChatContextSummary(): ?string
    {
        if (!$this->submission->chat_context || empty($this->submission->chat_context)) {
            return null;
        }

        $context = $this->submission->chat_context;
        $summary = '';

        // Prendi gli ultimi 3 messaggi per il riassunto
        $recentMessages = array_slice($context, -3);

        foreach ($recentMessages as $message) {
            $role = $message['role'] === 'user' ? '👤 Utente' : '🤖 Bot';
            $content = mb_substr($message['content'], 0, 100);
            if (mb_strlen($message['content']) > 100) {
                $content .= '...';
            }
            $summary .= "{$role}: {$content}\n\n";
        }

        return trim($summary);
    }

    /**
     * Genera URL dashboard admin
     */
    private function getAdminDashboardUrl(): string
    {
        return url("/admin/forms/{$this->submission->tenantForm->id}/submissions");
    }

    /**
     * Genera URL risposta diretta
     */
    private function getRespondUrl(): string
    {
        return url("/admin/forms/submissions/{$this->submission->id}/respond");
    }

    /**
     * Determina livello di urgenza basato su context e trigger
     */
    private function determineUrgencyLevel(): string
    {
        // Cerca parole chiave di urgenza
        $urgentKeywords = ['urgente', 'immediato', 'subito', 'emergenza', 'problema', 'errore'];
        
        $text = strtolower($this->submission->trigger_value ?? '');
        if ($this->submission->chat_context) {
            foreach ($this->submission->chat_context as $message) {
                $text .= ' ' . strtolower($message['content'] ?? '');
            }
        }

        foreach ($urgentKeywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return 'ALTA';
            }
        }

        // Se è stato triggerato automaticamente dopo molti messaggi
        if ($this->submission->trigger_type === 'auto' && 
            is_array($this->submission->chat_context) && 
            count($this->submission->chat_context) >= 3) {
            return 'MEDIA';
        }

        return 'NORMALE';
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $viewData = $this->viewData();
        
        return $this->view('emails.form-admin-notification', $viewData)
                   ->with($viewData);
    }
}












