# Sistema di Autenticazione ChatBot Platform

## Panoramica

È stato implementato un sistema di autenticazione completo con supporto per ruoli multipli e gestione multitenant. Il sistema supporta tre ruoli principali:

- **Admin**: Accesso completo alla piattaforma, gestione di tutti i tenant e utenti
- **Cliente**: Accesso limitato ai propri tenant, nessuna gestione utenti
- **Agente**: (Preparato per implementazioni future)

## Architettura

### Modelli

#### User Model (`app/Models/User.php`)
- Implementa `MustVerifyEmail` per verifica email obbligatoria
- Relazione many-to-many con `Tenant` tramite tabella pivot `tenant_user`
- Metodi helper per controllo ruoli: `isAdmin()`, `isAdminForTenant()`, `isCustomerForTenant()`
- Campi aggiuntivi: `is_active`, `last_login_at`

#### Tenant Model (aggiornato)
- Relazione many-to-many con `User`
- Supporto per associazioni multiple con ruoli diversi

### Database

#### Migrazioni
1. `add_auth_fields_to_users_table`: Aggiunge `is_active` e `last_login_at`
2. `create_tenant_user_table`: Tabella pivot per associazioni User-Tenant con ruoli

#### Struttura tenant_user
```sql
- id
- tenant_id (FK a tenants)
- user_id (FK a users)  
- role (enum: admin, customer, agent)
- timestamps
```

## Flusso di Autenticazione

### Registrazione/Invito
1. **Admin crea utente**: Form in `/admin/users/create`
2. **Password temporanea**: Generata automaticamente
3. **Email di invito**: Opzionale, contiene link di verifica
4. **Verifica email**: Obbligatoria prima del primo accesso
5. **Primo login**: L'utente può impostare la propria password

### Login
1. **Controllo credenziali**: Email e password
2. **Verifica stato**: Account attivo e email verificata
3. **Aggiornamento last_login_at**
4. **Redirect basato su ruolo**:
   - Admin → `/admin/dashboard`
   - Cliente → `/tenant/{id}/dashboard` (primo tenant)

### Reset Password
- Flusso standard Laravel con email di reset
- Admin può resettare password utenti (genera password temporanea)

## Autorizzazioni

### Policy System

#### UserPolicy (`app/Policies/UserPolicy.php`)
- `viewAny`: Solo admin
- `view`: Admin o utenti dello stesso tenant
- `create/update/delete`: Solo admin
- `manageRoles`: Solo admin (non può modificare i propri ruoli)
- `accessAdmin`: Admin attivi con email verificata

#### TenantPolicy (`app/Policies/TenantPolicy.php`)
- `viewAny`: Solo admin
- `view`: Admin o utenti associati al tenant
- `update`: Admin o clienti del tenant (limitato)
- `delete`: Solo admin
- `manageUsers`: Solo admin

### Middleware

#### EnsureAuthenticated (`app/Http/Middleware/EnsureAuthenticated.php`)
- Verifica autenticazione
- Controllo account attivo
- Controllo email verificata
- Supporto per ruoli specifici

#### EnsureTenantAccess (`app/Http/Middleware/EnsureTenantAccess.php`)
- Verifica accesso ai tenant specifici
- Admin bypassa tutti i controlli
- Altri utenti solo sui propri tenant

## Route e Controller

### Route di Autenticazione (`routes/web.php`)
```php
// Login/Logout
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Password Reset
Route::get('/forgot-password', ...)->name('password.request');
Route::post('/forgot-password', ...)->name('password.email');
Route::get('/reset-password/{token}', ...)->name('password.reset');
Route::post('/reset-password', ...)->name('password.update');

// Email Verification
Route::get('/email/verify/{id}/{hash}', ...)->name('verification.verify');
Route::post('/email/verification-notification', ...)->name('verification.send');
```

### Route Admin Gestione Utenti
```php
Route::resource('users', UserManagementController::class);
Route::patch('/users/{user}/toggle-status', ...)->name('users.toggle-status');
Route::post('/users/{user}/resend-invitation', ...)->name('users.resend-invitation');
Route::post('/users/{user}/reset-password', ...)->name('users.reset-password');
```

### Controller

#### AuthController (`app/Http/Controllers/Auth/AuthController.php`)
- Gestione login/logout
- Password reset
- Verifica email
- Redirect intelligente basato su ruoli

#### UserManagementController (`app/Http/Controllers/Admin/UserManagementController.php`)
- CRUD completo utenti
- Filtri avanzati (ruolo, tenant, stato)
- Gestione associazioni tenant-ruolo
- Azioni rapide (attiva/disattiva, resend invito, reset password)

## Sistema Email

### Notifications

#### UserInvitation (`app/Notifications/UserInvitation.php`)
- Estende `VerifyEmail` di Laravel
- Template personalizzato per inviti
- Link di verifica con scadenza

#### PasswordResetNotification (`app/Notifications/PasswordResetNotification.php`)
- Notifica per password resettate da admin
- Include password temporanea

### Event Listeners

#### SendUserInvitation (`app/Listeners/SendUserInvitation.php`)
- Ascolta evento `Registered`
- Invia automaticamente email di invito per nuovi utenti

## Interfaccia Utente

### View di Autenticazione
- `auth/login.blade.php`: Form di login pulito e responsive
- `auth/forgot-password.blade.php`: Richiesta reset password
- `auth/reset-password.blade.php`: Form reset password

### Admin Panel - Gestione Utenti
- `admin/users/index.blade.php`: Lista utenti con filtri avanzati
- `admin/users/create.blade.php`: Creazione utente con associazioni tenant
- `admin/users/show.blade.php`: Dettagli utente con azioni rapide
- `admin/users/edit.blade.php`: Modifica utente e associazioni

### Caratteristiche UI
- Design responsive con Tailwind CSS
- Filtri in tempo reale con Alpine.js
- Badge colorati per stati e ruoli
- Azioni inline per operazioni rapide
- Conferme JavaScript per azioni distruttive

## Sicurezza

### Implementazioni di Sicurezza
- Hash password con bcrypt
- Token CSRF su tutti i form
- Rate limiting su reset password e verifica email
- Validazione input con FormRequest
- Soft delete supportato tramite policy
- Session regeneration dopo login
- Logout forzato per account inattivi

### Controlli di Accesso
- Scoping automatico tenant per utenti non-admin
- Policy-based authorization
- Middleware stack per verifiche multiple
- Protezione route sensibili

## Configurazione

### Environment Variables
```env
# Mail configuration per inviti
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_ENCRYPTION=tls

# Auth settings (Laravel standard)
AUTH_GUARD=web
AUTH_PASSWORD_BROKER=users
```

### Service Provider Registration
- Event listeners registrati in `AppServiceProvider`
- Policy registrate automaticamente
- Middleware alias configurati in `bootstrap/app.php`

## Utilizzo

### Per Amministratori
1. Accesso via `/admin/login` (token) o `/login` (credenziali)
2. Gestione utenti in `/admin/users`
3. Creazione utenti con associazioni tenant multiple
4. Controllo stati e invio inviti
5. Reset password e gestione accessi

### Per Clienti
1. Ricezione email di invito
2. Verifica email tramite link
3. Primo login e impostazione password
4. Accesso limitato ai propri tenant

## Testing

### Comandi Utili
```bash
# Esegui migrazioni
php artisan migrate

# Crea utente admin test
php artisan tinker
User::create([
    'name' => 'Admin Test',
    'email' => 'admin@test.com', 
    'password' => Hash::make('password'),
    'email_verified_at' => now(),
    'is_active' => true
]);

# Test email configuration
php artisan queue:work
```

### Test Cases Raccomandati
- [ ] Login con credenziali valide/invalide
- [ ] Reset password flow completo
- [ ] Verifica email nuovi utenti
- [ ] Creazione utenti da admin
- [ ] Controlli autorizzazione per ruoli
- [ ] Scoping tenant per clienti
- [ ] Disattivazione/riattivazione utenti

## Roadmap Future

### Funzionalità da Implementare
- [ ] Dashboard clienti personalizzate
- [ ] Ruolo Agente con permessi specifici
- [ ] Two-factor authentication (2FA)
- [ ] Audit log per azioni sensibili
- [ ] API tokens per integrazione esterna
- [ ] Single Sign-On (SSO)
- [ ] Gestione sessioni multiple
- [ ] Notifiche in-app

### Miglioramenti UI/UX
- [ ] Wizard setup primo accesso
- [ ] Dashboard analytics per admin
- [ ] Ricerca avanzata utenti
- [ ] Bulk operations utenti
- [ ] Export/import utenti CSV
- [ ] Dark mode support

## Manutenzione

### Operazioni Routine
- Pulizia token scaduti: `php artisan auth:clear-resets`
- Monitoraggio accessi falliti
- Backup database con associazioni utenti
- Verifica integrità relazioni tenant-user

### Troubleshooting
- Problemi email: Verificare configurazione SMTP
- Login loops: Controllare middleware e policy
- Errori autorizzazione: Verificare associazioni tenant
- Performance: Indicizzare colonne di ricerca frequenti

Questo sistema fornisce una base solida e scalabile per l'autenticazione multitenant con controlli granulari di accesso e un'interfaccia amministrativa completa.
