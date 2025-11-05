<?php

namespace App\Mail;

use App\Models\FormSubmission;
use App\Models\FormResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 📧 FormAdminResponseMail
 * Email di risposta inviata dall'admin all'utente
 */
class FormAdminResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public FormSubmission $submission,
        public FormResponse $response
    ) {
        $this->timeout = 30;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $form = $this->submission->tenantForm;
        $tenant = $this->submission->tenant;

        $subject = $this->response->email_subject ?? 
                  "Re: {$form->name} - Risposta alla tua richiesta";

        return Envelope::create(
            from: config('mail.from.address', 'noreply@chatbotplatform.com'),
            fromName: $tenant->name ?? config('mail.from.name', 'ChatBot Platform'),
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
        return Content::with('emails.form-admin-response');
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
            'response' => $this->response,
            'form' => $form,
            'tenant' => $tenant,
            'userName' => $this->submission->user_name ?? 'Gentile utente',
            'adminName' => $this->response->adminUser->name ?? 'Il nostro team',
            'responseDate' => $this->response->created_at->format('d/m/Y H:i'),
            'submissionId' => $this->submission->id,
            'supportEmail' => $form->admin_notification_email,
        ];
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $viewData = $this->viewData();
        
        return $this->view('emails.form-admin-response', $viewData)
                   ->with($viewData);
    }
}


















































