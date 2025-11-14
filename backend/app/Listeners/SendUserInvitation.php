<?php

namespace App\Listeners;

use App\Notifications\UserInvitation;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendUserInvitation implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        $user = $event->user;

        // Invia notifica di invito solo se l'email non è ancora verificata
        if (! $user->hasVerifiedEmail()) {
            $user->notify(new UserInvitation(true));
        }
    }
}
