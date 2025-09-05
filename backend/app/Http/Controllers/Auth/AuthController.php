<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rules;

class AuthController extends Controller
{
    /**
     * Mostra il form di login
     */
    public function showLogin()
    {
        return view('auth.login');
    }

    /**
     * Gestisce il login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $credentials = $request->only('email', 'password');
        
        // Trova l'utente
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user) {
            return back()->withErrors([
                'email' => 'Le credenziali fornite non corrispondono ai nostri record.',
            ])->onlyInput('email');
        }

        // Verifica se l'utente è attivo
        if (!$user->is_active) {
            return back()->withErrors([
                'email' => 'Il tuo account è stato disattivato.',
            ])->onlyInput('email');
        }

        // Verifica se l'email è verificata
        if (!$user->hasVerifiedEmail()) {
            return back()->withErrors([
                'email' => 'Devi verificare la tua email prima di accedere.',
            ])->onlyInput('email');
        }

        // Tenta il login
        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            
            // Aggiorna ultimo login
            $user->update(['last_login_at' => now()]);

            // Redirect in base al ruolo
            if ($user->isAdmin()) {
                return redirect()->intended(route('admin.dashboard'));
            } else {
                // Cliente - redirect al primo tenant
                $firstTenant = $user->tenants()->first();
                if ($firstTenant) {
                    return redirect()->intended(route('tenant.dashboard', $firstTenant->id));
                }
                
                return redirect()->intended(route('dashboard'));
            }
        }

        return back()->withErrors([
            'email' => 'Le credenziali fornite non corrispondono ai nostri record.',
        ])->onlyInput('email');
    }

    /**
     * Gestisce il logout
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Mostra il form di registrazione
     */
    public function showRegister()
    {
        return view('auth.register');
    }

    /**
     * Gestisce la registrazione (solo per inviti)
     */
    public function register(Request $request)
    {
        // La registrazione diretta non è permessa
        // Solo attraverso inviti da admin
        abort(404);
    }

    /**
     * Mostra form per reset password
     */
    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    /**
     * Invia email di reset password
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
                    ? back()->with(['status' => __($status)])
                    : back()->withErrors(['email' => __($status)]);
    }

    /**
     * Mostra form per reset password
     */
    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->email]);
    }

    /**
     * Reset della password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withErrors(['email' => [__($status)]]);
    }

    /**
     * Verifica email
     */
    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (!hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('login')->with('status', 'Email già verificata.');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('login')->with('status', 'Email verificata con successo! Ora puoi accedere.');
    }

    /**
     * Reinvia email di verifica
     */
    public function resendVerification(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard'));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Email di verifica inviata!');
    }
}
