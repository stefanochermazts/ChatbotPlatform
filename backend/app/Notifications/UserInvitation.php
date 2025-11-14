<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class UserInvitation extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    protected $isNewUser;

    public function __construct(bool $isNewUser = true)
    {
        $this->isNewUser = $isNewUser;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        $message = new MailMessage;
        $message->subject($this->isNewUser ? 'Invito alla piattaforma ChatBot' : 'Verifica il tuo indirizzo email');

        if ($this->isNewUser) {
            $message->greeting('Benvenuto!');
            $message->line('Sei stato invitato ad accedere alla piattaforma ChatBot.');
            $message->line('Per completare la registrazione e attivare il tuo account, clicca sul pulsante qui sotto per verificare il tuo indirizzo email.');
        } else {
            $message->greeting('Ciao!');
            $message->line('Per favore, clicca sul pulsante qui sotto per verificare il tuo indirizzo email.');
        }

        $message->action('Verifica Indirizzo Email', $verificationUrl);

        if ($this->isNewUser) {
            $message->line('Dopo aver verificato la tua email, potrai accedere utilizzando questa email e la password che riceverai separatamente.');
        }

        $message->line('Se non hai richiesto questo invito, puoi ignorare questa email.');

        return $message;
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
