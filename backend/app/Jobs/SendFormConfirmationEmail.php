<?php

namespace App\Jobs;

use App\Models\FormSubmission;
use App\Mail\FormConfirmationMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * 📧 SendFormConfirmationEmail Job
 * Invia email di conferma all'utente dopo submission form
 */
class SendFormConfirmationEmail implements ShouldQueue
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
            Log::info('📧 Sending form confirmation email', [
                'submission_id' => $this->submission->id,
                'user_email' => $this->submission->user_email,
                'form_name' => $this->submission->tenantForm->name
            ]);

            // Verifica che ci sia un'email valida
            if (!$this->submission->user_email) {
                Log::warning('📧 No user email found for submission', [
                    'submission_id' => $this->submission->id
                ]);
                return;
            }

            // Carica relazioni necessarie
            $this->submission->load(['tenantForm', 'tenant']);

            // Crea e invia la mail
            $mail = new FormConfirmationMail($this->submission);
            Mail::to($this->submission->user_email)->send($mail);

            // Aggiorna timestamp invio
            $this->submission->markConfirmationEmailSent();

            Log::info('📧 Form confirmation email sent successfully', [
                'submission_id' => $this->submission->id,
                'user_email' => $this->submission->user_email
            ]);

        } catch (\Exception $e) {
            Log::error('📧 Failed to send form confirmation email', [
                'submission_id' => $this->submission->id,
                'user_email' => $this->submission->user_email,
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
        Log::error('📧 Form confirmation email job failed permanently', [
            'submission_id' => $this->submission->id,
            'user_email' => $this->submission->user_email,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Opzionalmente, puoi aggiornare lo stato dell'email nel database
        // per tracciare che l'invio è fallito definitivamente
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
















