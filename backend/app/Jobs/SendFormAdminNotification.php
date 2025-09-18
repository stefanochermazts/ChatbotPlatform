<?php

namespace App\Jobs;

use App\Models\FormSubmission;
use App\Mail\FormAdminNotificationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * 📢 SendFormAdminNotification Job
 * Invia notifica email agli admin quando un form viene sottomesso
 */
class SendFormAdminNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60]; // Retry delays in seconds

    /**
     * Create a new job instance.
     */
    public function __construct(
        public FormSubmission $submission
    ) {
        $this->onQueue('email');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('📢 Sending form admin notification', [
                'submission_id' => $this->submission->id,
                'form_name' => $this->submission->tenantForm->name,
                'tenant_id' => $this->submission->tenant_id
            ]);

            // Carica relazioni necessarie
            $this->submission->load(['tenantForm', 'tenant']);

            $form = $this->submission->tenantForm;

            // Verifica che ci sia un'email admin configurata
            if (!$form->admin_notification_email) {
                Log::warning('📢 No admin email configured for form', [
                    'submission_id' => $this->submission->id,
                    'form_id' => $form->id
                ]);
                return;
            }

            // Supporta email multiple separate da virgola
            $adminEmails = explode(',', $form->admin_notification_email);
            $adminEmails = array_map('trim', $adminEmails);
            $adminEmails = array_filter($adminEmails, function($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL);
            });

            if (empty($adminEmails)) {
                Log::warning('📢 No valid admin emails found', [
                    'submission_id' => $this->submission->id,
                    'raw_emails' => $form->admin_notification_email
                ]);
                return;
            }

            // Crea e invia la mail
            $mail = new FormAdminNotificationMail($this->submission);
            
            foreach ($adminEmails as $adminEmail) {
                try {
                    Mail::to($adminEmail)->send($mail);
                    Log::info('📢 Admin notification sent', [
                        'submission_id' => $this->submission->id,
                        'admin_email' => $adminEmail
                    ]);
                } catch (\Exception $e) {
                    Log::error('📢 Failed to send to specific admin email', [
                        'submission_id' => $this->submission->id,
                        'admin_email' => $adminEmail,
                        'error' => $e->getMessage()
                    ]);
                    // Continua con gli altri email
                }
            }

            // Aggiorna timestamp invio
            $this->submission->markAdminNotificationSent();

            Log::info('📢 Form admin notification completed', [
                'submission_id' => $this->submission->id,
                'emails_sent' => count($adminEmails)
            ]);

        } catch (\Exception $e) {
            Log::error('📢 Failed to send form admin notification', [
                'submission_id' => $this->submission->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Rilancia l'eccezione per retry automatico
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('📢 Form admin notification job failed permanently', [
            'submission_id' => $this->submission->id,
            'form_id' => $this->submission->tenantForm->id,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Opzionalmente, puoi notificare via altri canali (Slack, webhook, etc.)
        // o aggiornare lo stato nel database
    }

    /**
     * Calculate backoff delay for retries
     */
    public function backoff(): array
    {
        return $this->backoff;
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(10);
    }
}





















