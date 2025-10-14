<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚨 Nuova Sottomissione Form - {{ $form->name }}</title>
    <style>
        /* Reset CSS per email */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333333;
            background-color: #f8fafc;
            margin: 0;
            padding: 20px;
        }
        
        .email-container {
            max-width: 700px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .email-header {
            background: linear-gradient(135deg, #ed64a6 0%, #d53f8c 100%);
            color: white;
            padding: 25px 30px;
            text-align: center;
        }
        
        .email-header h1 {
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 8px 0;
        }
        
        .tenant-info {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .urgency-banner {
            padding: 15px 30px;
            text-align: center;
            font-weight: 600;
            font-size: 14px;
        }
        
        .urgency-alta {
            background: #fed7d7;
            color: #c53030;
        }
        
        .urgency-media {
            background: #feebc8;
            color: #c05621;
        }
        
        .urgency-normale {
            background: #e6fffa;
            color: #285e61;
        }
        
        .email-body {
            padding: 30px;
        }
        
        .quick-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #4299e1;
            flex: 1;
            min-width: 150px;
        }
        
        .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .section {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .section-title {
            color: #2d3748;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-data-grid {
            display: grid;
            gap: 10px;
        }
        
        .form-data-item {
            display: flex;
            align-items: flex-start;
            padding: 10px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        
        .form-data-label {
            font-weight: 600;
            color: #4a5568;
            min-width: 120px;
            margin-right: 15px;
        }
        
        .form-data-value {
            color: #2d3748;
            flex: 1;
            word-break: break-word;
        }
        
        .user-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .user-info-item {
            background: white;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }
        
        .user-info-label {
            font-size: 12px;
            font-weight: 600;
            color: #4a5568;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .user-info-value {
            color: #2d3748;
            font-weight: 500;
        }
        
        .chat-context {
            background: white;
            border-radius: 6px;
            padding: 15px;
            border: 1px solid #e2e8f0;
            font-family: monospace;
            font-size: 13px;
            line-height: 1.5;
            white-space: pre-wrap;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin: 25px 0;
            flex-wrap: wrap;
        }
        
        .action-button {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            text-align: center;
            transition: all 0.2s ease;
            flex: 1;
            min-width: 140px;
        }
        
        .btn-primary {
            background: #4299e1;
            color: white;
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .email-footer {
            background: #2d3748;
            color: white;
            padding: 20px 30px;
            text-align: center;
            font-size: 13px;
        }
        
        .footer-text {
            opacity: 0.8;
        }
        
        /* Mobile responsive */
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }
            
            .email-header, .email-body, .email-footer {
                padding: 20px;
            }
            
            .quick-stats {
                flex-direction: column;
            }
            
            .user-info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .form-data-item {
                flex-direction: column;
            }
            
            .form-data-label {
                min-width: auto;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1>🚨 Nuova Sottomissione Form</h1>
            <div class="tenant-info">{{ $tenant->name }} - {{ $form->name }}</div>
        </div>
        
        <!-- Banner urgenza -->
        <div class="urgency-banner urgency-{{ strtolower($urgencyLevel) }}">
            🔥 PRIORITÀ {{ strtoupper($urgencyLevel) }} - {{ $triggerDescription }}
        </div>
        
        <!-- Corpo email -->
        <div class="email-body">
            <!-- Quick Stats -->
            <div class="quick-stats">
                <div class="stat-item">
                    <div class="stat-label">ID Sottomissione</div>
                    <div class="stat-value">#{{ $submission->id }}</div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Data e Ora</div>
                    <div class="stat-value">{{ $submissionDate }}</div>
                </div>
                <div class="stat-item">
                    <div class="stat-label">Trigger</div>
                    <div class="stat-value">{{ $triggerDescription }}</div>
                </div>
            </div>
            
            <!-- Azioni Rapide -->
            <div class="action-buttons">
                <a href="{{ $respondUrl }}" class="action-button btn-primary">
                    💬 Rispondi Subito
                </a>
                <a href="{{ $adminDashboardUrl }}" class="action-button btn-secondary">
                    📊 Dashboard
                </a>
                @if($submission->user_email)
                    <a href="mailto:{{ $submission->user_email }}" class="action-button btn-success">
                        📧 Email Diretta
                    </a>
                @endif
            </div>
            
            <!-- Dati Form -->
            @if($formattedData && count($formattedData) > 0)
                <div class="section">
                    <h3 class="section-title">
                        📝 Dati Inviati dall'Utente
                    </h3>
                    <div class="form-data-grid">
                        @foreach($formattedData as $field)
                            <div class="form-data-item">
                                <div class="form-data-label">{{ $field['label'] }}:</div>
                                <div class="form-data-value">
                                    @if($field['field_type'] === 'email')
                                        <a href="mailto:{{ $field['value'] }}" style="color: #4299e1;">{{ $field['value'] }}</a>
                                    @elseif($field['field_type'] === 'phone')
                                        <a href="tel:{{ $field['value'] }}" style="color: #4299e1;">{{ $field['value'] }}</a>
                                    @else
                                        {{ $field['value'] }}
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
            
            <!-- Informazioni Utente -->
            <div class="section">
                <h3 class="section-title">
                    👤 Informazioni Utente
                </h3>
                <div class="user-info-grid">
                    <div class="user-info-item">
                        <div class="user-info-label">Nome</div>
                        <div class="user-info-value">{{ $userInfo['name'] }}</div>
                    </div>
                    <div class="user-info-item">
                        <div class="user-info-label">Email</div>
                        <div class="user-info-value">
                            @if($userInfo['email'] !== 'Non fornito')
                                <a href="mailto:{{ $userInfo['email'] }}" style="color: #4299e1;">{{ $userInfo['email'] }}</a>
                            @else
                                {{ $userInfo['email'] }}
                            @endif
                        </div>
                    </div>
                    <div class="user-info-item">
                        <div class="user-info-label">IP Address</div>
                        <div class="user-info-value">{{ $userInfo['ip'] }}</div>
                    </div>
                    <div class="user-info-item">
                        <div class="user-info-label">Sessione</div>
                        <div class="user-info-value">{{ Str::limit($userInfo['session_id'], 20) }}</div>
                    </div>
                </div>
            </div>
            
            <!-- Contesto Chat -->
            @if($chatContext)
                <div class="section">
                    <h3 class="section-title">
                        💬 Contesto Conversazione
                    </h3>
                    <div class="chat-context">{{ $chatContext }}</div>
                </div>
            @endif
            
            <!-- User Agent -->
            @if($userInfo['user_agent'] !== 'Non disponibile')
                <div class="section">
                    <h3 class="section-title">
                        🖥️ Browser & Dispositivo
                    </h3>
                    <div style="background: white; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; word-break: break-all;">
                        {{ $userInfo['user_agent'] }}
                    </div>
                </div>
            @endif
            
            <!-- Call to Action -->
            <div style="background: #ebf4ff; border: 2px solid #4299e1; border-radius: 8px; padding: 20px; margin: 25px 0; text-align: center;">
                <h4 style="color: #2b6cb0; margin: 0 0 10px 0;">⚡ Azione Richiesta</h4>
                <p style="margin: 0 0 15px 0; color: #2c5282;">
                    Un utente ha inviato una nuova richiesta e attende una risposta. 
                    Ti consigliamo di rispondere entro <strong>24 ore</strong> per mantenere un buon livello di servizio.
                </p>
                <a href="{{ $respondUrl }}" class="action-button btn-primary" style="margin: 0;">
                    🚀 Gestisci Richiesta
                </a>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            <div class="footer-text">
                Questa notifica è stata generata automaticamente dal sistema ChatBot Platform.<br>
                Form: {{ $form->name }} | Tenant: {{ $tenant->name }} | ID: #{{ $submission->id }}
            </div>
        </div>
    </div>
</body>
</html>







































