<?php

namespace App\Mail;

use App\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * 📧 FormConfirmationMail
 * Email di conferma inviata all'utente dopo submission del form
 */
class FormConfirmationMail extends Mailable
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

        $subject = $form->user_confirmation_email_subject 
            ?? "Conferma ricezione richiesta: {$form->name}";

        // Replace placeholders in subject
        $subject = $this->replacePlaceholders($subject);

        return new Envelope(
            from: config('mail.from.address', 'noreply@chatbotplatform.com'),
            subject: $subject,
            replyTo: $form->admin_notification_email 
                ? [$form->admin_notification_email]
                : null
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.form-confirmation'
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
            'userName' => $this->submission->user_name ?? 'Gentile utente',
            'formattedData' => $this->submission->getFormattedDataAttribute(),
            'submissionDate' => $this->submission->submitted_at->format('d/m/Y H:i'),
            'tenantLogo' => $this->getTenantLogoUrl(),
            'emailBody' => $this->getCustomEmailBody(),
            'supportEmail' => $form->admin_notification_email,
            'submissionId' => $this->submission->id,
        ];
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * Ottieni URL del logo tenant
     */
    private function getTenantLogoUrl(): ?string
    {
        $form = $this->submission->tenantForm;
        
        if ($form->email_logo_path && Storage::disk('public')->exists($form->email_logo_path)) {
            return Storage::disk('public')->url($form->email_logo_path);
        }

        return null;
    }

    /**
     * Ottieni corpo email personalizzato
     */
    private function getCustomEmailBody(): string
    {
        $form = $this->submission->tenantForm;
        
        $defaultBody = "Abbiamo ricevuto la sua richiesta per {form_name}.\n\n" .
                      "Dati inviati:\n{form_data}\n\n" .
                      "La contatteremo al più presto.\n\n" .
                      "Cordiali saluti,\n{tenant_name}";

        $emailBody = $form->user_confirmation_email_body ?? $defaultBody;
        
        return $this->replacePlaceholders($emailBody);
    }

    /**
     * Sostituisci placeholder nel testo
     */
    private function replacePlaceholders(string $text): string
    {
        $tenant = $this->submission->tenant;
        $form = $this->submission->tenantForm;
        $formattedData = $this->submission->getFormattedDataAttribute();

        // Formatta i dati del form per l'email
        $formDataText = '';
        foreach ($formattedData as $field) {
            $formDataText .= "- {$field['label']}: {$field['value']}\n";
        }

        $placeholders = [
            '{tenant_name}' => $tenant->name ?? 'ChatBot Platform',
            '{form_name}' => $form->name,
            '{form_data}' => trim($formDataText),
            '{user_name}' => $this->submission->user_name ?? 'Gentile utente',
            '{user_email}' => $this->submission->user_email ?? '',
            '{submission_id}' => $this->submission->id,
            '{submission_date}' => $this->submission->submitted_at->format('d/m/Y H:i'),
            '{support_email}' => $form->admin_notification_email ?? '',
            '{form_description}' => $form->description ?? '',
        ];

        return str_replace(
            array_keys($placeholders), 
            array_values($placeholders), 
            $text
        );
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $viewData = $this->viewData();
        
        return $this->view('emails.form-confirmation', $viewData)
                   ->with($viewData);
    }
}












