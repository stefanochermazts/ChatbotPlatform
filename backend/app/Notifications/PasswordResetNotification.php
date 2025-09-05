<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $temporaryPassword;

    public function __construct(string $temporaryPassword)
    {
        $this->temporaryPassword = $temporaryPassword;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Password reimpostata - ChatBot Platform')
            ->greeting('Ciao ' . $notifiable->name . '!')
            ->line('La tua password è stata reimpostata da un amministratore.')
            ->line('La tua nuova password temporanea è: **' . $this->temporaryPassword . '**')
            ->line('Ti consigliamo di cambiare questa password al primo accesso.')
            ->action('Accedi alla Piattaforma', route('login'))
            ->line('Se non hai richiesto questa modifica, contatta immediatamente l\'amministratore.')
            ->salutation('Cordiali saluti, Il team ChatBot Platform');
    }
}
