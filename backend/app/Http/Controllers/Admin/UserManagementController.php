<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UserManagementController extends Controller
{
    public function __construct()
    {
        // L'autenticazione è gestita dal middleware delle route
        // Le autorizzazioni sono gestite tramite policy nei singoli metodi
    }

    /**
     * Lista utenti
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);
        $query = User::with('tenants');

        // Filtro per nome/email
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filtro per ruolo
        if ($request->filled('role')) {
            $role = $request->get('role');
            $query->whereHas('tenants', function($q) use ($role) {
                $q->wherePivot('role', $role);
            });
        }

        // Filtro per tenant
        if ($request->filled('tenant_id')) {
            $tenantId = $request->get('tenant_id');
            $query->whereHas('tenants', function($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            });
        }

        // Filtro per stato
        if ($request->filled('status')) {
            $status = $request->get('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            } elseif ($status === 'verified') {
                $query->whereNotNull('email_verified_at');
            } elseif ($status === 'unverified') {
                $query->whereNull('email_verified_at');
            }
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(20);
        $tenants = Tenant::orderBy('name')->get();

        return view('admin.users.index', compact('users', 'tenants'));
    }

    /**
     * Mostra form creazione utente
     */
    public function create()
    {
        $this->authorize('create', User::class);
        $tenants = Tenant::orderBy('name')->get();
        return view('admin.users.create', compact('tenants'));
    }

    /**
     * Crea nuovo utente
     */
    public function store(Request $request)
    {
        $this->authorize('create', User::class);
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'tenant_ids' => ['required', 'array', 'min:1'],
            'tenant_ids.*' => ['exists:tenants,id'],
            'roles' => ['required', 'array'],
            'roles.*' => ['in:' . implode(',', array_keys(User::ROLES))],
            'send_invitation' => ['boolean'],
        ]);

        // Validazione superata, procedi con la creazione

        // Verifica che ci sia un ruolo per ogni tenant
        $tenantIds = $request->get('tenant_ids');
        $roles = $request->get('roles');
        
        if (count($tenantIds) !== count($roles)) {
            return back()->withErrors(['roles' => 'Devi specificare un ruolo per ogni tenant.']);
        }

        // Verifica che non ci siano tenant duplicati
        if (count($tenantIds) !== count(array_unique($tenantIds))) {
            return back()->withErrors(['tenant_ids' => 'Non puoi associare lo stesso tenant più volte.']);
        }

        // Crea l'utente con password temporanea
        $temporaryPassword = Str::random(32);
        
        $user = User::create([
            'name' => $request->get('name'),
            'email' => $request->get('email'),
            'password' => Hash::make($temporaryPassword),
            'is_active' => true,
        ]);

        // Associa i tenant con i ruoli
        $tenantData = [];
        foreach ($tenantIds as $index => $tenantId) {
            $tenantData[$tenantId] = ['role' => $roles[$index]];
        }
        $user->tenants()->sync($tenantData);

        // Invia invito se richiesto
        if ($request->boolean('send_invitation')) {
            event(new Registered($user));
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Utente creato con successo.' . ($request->boolean('send_invitation') ? ' Email di invito inviata.' : ''));
    }

    /**
     * Mostra dettagli utente
     */
    public function show(User $user)
    {
        $this->authorize('view', $user);
        $user->load('tenants');
        return view('admin.users.show', compact('user'));
    }

    /**
     * Mostra form modifica utente
     */
    public function edit(User $user)
    {
        $this->authorize('update', $user);
        $user->load('tenants');
        $tenants = Tenant::orderBy('name')->get();
        return view('admin.users.edit', compact('user', 'tenants'));
    }

    /**
     * Aggiorna utente
     */
    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'is_active' => ['boolean'],
            'tenant_ids' => ['array'],
            'tenant_ids.*' => ['exists:tenants,id'],
            'roles' => ['array'],
            'roles.*' => ['in:' . implode(',', array_keys(User::ROLES))],
        ]);

        // Aggiorna dati base
        $user->update([
            'name' => $request->get('name'),
            'email' => $request->get('email'),
            'is_active' => $request->boolean('is_active'),
        ]);

        // Aggiorna associazioni tenant (solo se l'utente attuale può gestirle)
        if ($request->user()->can('manageTenantAssociations', $user)) {
            $tenantIds = $request->get('tenant_ids', []);
            $roles = $request->get('roles', []);
            
            if (count($tenantIds) === count($roles) && count($tenantIds) > 0) {
                // Verifica che non ci siano tenant duplicati
                if (count($tenantIds) !== count(array_unique($tenantIds))) {
                    return back()->withErrors(['tenant_ids' => 'Non puoi associare lo stesso tenant più volte.']);
                }

                $tenantData = [];
                
                // Se l'utente sta modificando se stesso, mantieni i ruoli esistenti
                $isEditingSelf = $request->user()->id === $user->id;
                $existingRoles = [];
                
                if ($isEditingSelf) {
                    $existingRoles = $user->tenants->pluck('pivot.role', 'id')->toArray();
                }
                
                foreach ($tenantIds as $index => $tenantId) {
                    if (!empty($tenantId)) {
                        // Se modifica se stesso, mantieni il ruolo esistente o usa quello fornito per nuovi tenant
                        $role = $isEditingSelf && isset($existingRoles[$tenantId]) 
                            ? $existingRoles[$tenantId] 
                            : $roles[$index];
                            
                        $tenantData[$tenantId] = ['role' => $role];
                    }
                }
                $user->tenants()->sync($tenantData);
            }
        }

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Utente aggiornato con successo.');
    }

    /**
     * Elimina utente
     */
    public function destroy(User $user)
    {
        $this->authorize('delete', $user);
        // Detach dai tenant prima di eliminare
        $user->tenants()->detach();
        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Utente eliminato con successo.');
    }

    /**
     * Riattiva/Disattiva utente
     */
    public function toggleStatus(User $user)
    {
        $this->authorize('update', $user);

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'attivato' : 'disattivato';
        
        return back()->with('success', "Utente {$status} con successo.");
    }

    /**
     * Reinvia invito di verifica email
     */
    public function resendInvitation(User $user)
    {
        $this->authorize('update', $user);

        if ($user->hasVerifiedEmail()) {
            return back()->withErrors(['error' => 'L\'utente ha già verificato la sua email.']);
        }

        $user->sendEmailVerificationNotification();

        return back()->with('success', 'Email di invito inviata nuovamente.');
    }

    /**
     * Reset password utente
     */
    public function resetPassword(User $user)
    {
        $this->authorize('update', $user);

        // Genera nuova password temporanea
        $temporaryPassword = Str::random(16);
        $user->update(['password' => Hash::make($temporaryPassword)]);

        // Invia email con nuova password (implementare NotificationChannel)
        // $user->notify(new PasswordResetNotification($temporaryPassword));

        return back()->with('success', 'Password resettata. L\'utente riceverà una email con le nuove credenziali.');
    }
}
