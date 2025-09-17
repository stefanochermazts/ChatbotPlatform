<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conferma Ricezione Richiesta - {{ $tenant->name }}</title>
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
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .email-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .tenant-logo {
            max-width: 150px;
            max-height: 60px;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        
        .email-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }
        
        .email-header p {
            font-size: 16px;
            margin: 10px 0 0 0;
            opacity: 0.9;
        }
        
        .email-body {
            padding: 40px 30px;
        }
        
        .greeting {
            font-size: 18px;
            font-weight: 500;
            color: #2d3748;
            margin-bottom: 20px;
        }
        
        .confirmation-message {
            background: #f0fff4;
            border: 2px solid #68d391;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        
        .confirmation-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        
        .confirmation-title {
            font-size: 20px;
            font-weight: 600;
            color: #22543d;
            margin-bottom: 8px;
        }
        
        .confirmation-text {
            color: #2f855a;
            font-size: 16px;
        }
        
        .form-details {
            background: #f7fafc;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }
        
        .form-details h3 {
            color: #2d3748;
            font-size: 18px;
            margin-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 8px;
        }
        
        .form-data-item {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .form-data-item:last-child {
            border-bottom: none;
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
        
        .submission-info {
            background: #edf2f7;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            font-size: 14px;
            color: #4a5568;
        }
        
        .submission-info-item {
            margin-bottom: 5px;
        }
        
        .submission-info-item:last-child {
            margin-bottom: 0;
        }
        
        .custom-message {
            background: #ebf8ff;
            border-left: 4px solid #3182ce;
            padding: 20px;
            margin: 25px 0;
            border-radius: 0 8px 8px 0;
        }
        
        .custom-message p {
            margin: 0 0 10px 0;
            color: #2c5282;
            line-height: 1.6;
        }
        
        .custom-message p:last-child {
            margin-bottom: 0;
        }
        
        .email-footer {
            background: #2d3748;
            color: white;
            padding: 25px 30px;
            text-align: center;
            font-size: 14px;
        }
        
        .support-info {
            margin-bottom: 15px;
        }
        
        .support-email {
            color: #90cdf4;
            text-decoration: none;
        }
        
        .footer-text {
            opacity: 0.8;
            font-size: 12px;
        }
        
        /* Mobile responsive */
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }
            
            .email-header, .email-body, .email-footer {
                padding: 20px;
            }
            
            .form-data-item {
                flex-direction: column;
            }
            
            .form-data-label {
                min-width: auto;
                margin-right: 0;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header con logo tenant -->
        <div class="email-header">
            @if($tenantLogo)
                <img src="{{ $tenantLogo }}" alt="{{ $tenant->name }}" class="tenant-logo">
            @endif
            <h1>✅ Richiesta Ricevuta</h1>
            <p>{{ $tenant->name ?? 'ChatBot Platform' }}</p>
        </div>
        
        <!-- Corpo email -->
        <div class="email-body">
            <div class="greeting">
                Ciao {{ $userName }},
            </div>
            
            <!-- Messaggio di conferma -->
            <div class="confirmation-message">
                <div class="confirmation-icon">📋</div>
                <div class="confirmation-title">Richiesta Ricevuta con Successo!</div>
                <div class="confirmation-text">
                    Abbiamo ricevuto la tua richiesta per <strong>{{ $form->name }}</strong>
                </div>
            </div>
            
            <!-- Messaggio personalizzato se configurato -->
            @if($emailBody && trim($emailBody) !== '')
                <div class="custom-message">
                    {!! nl2br(e($emailBody)) !!}
                </div>
            @endif
            
            <!-- Dettagli form inviato -->
            @if($formattedData && count($formattedData) > 0)
                <div class="form-details">
                    <h3>📝 Dettagli della tua richiesta:</h3>
                    @foreach($formattedData as $field)
                        <div class="form-data-item">
                            <div class="form-data-label">{{ $field['label'] }}:</div>
                            <div class="form-data-value">
                                @if($field['field_type'] === 'email')
                                    <a href="mailto:{{ $field['value'] }}">{{ $field['value'] }}</a>
                                @elseif($field['field_type'] === 'phone')
                                    <a href="tel:{{ $field['value'] }}">{{ $field['value'] }}</a>
                                @else
                                    {{ $field['value'] }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            
            <!-- Informazioni submission -->
            <div class="submission-info">
                <div class="submission-info-item">
                    <strong>🆔 ID Richiesta:</strong> #{{ $submissionId }}
                </div>
                <div class="submission-info-item">
                    <strong>📅 Data e Ora:</strong> {{ $submissionDate }}
                </div>
                @if($form->description)
                    <div class="submission-info-item">
                        <strong>📋 Tipo:</strong> {{ $form->description }}
                    </div>
                @endif
            </div>
            
            <!-- Cosa succede dopo -->
            <div style="margin-top: 30px; padding: 20px; background: #fff5f5; border-radius: 8px; border-left: 4px solid #fc8181;">
                <h4 style="color: #c53030; margin: 0 0 10px 0;">🔄 Cosa succede ora?</h4>
                <p style="margin: 0; color: #742a2a;">
                    Il nostro team esaminerà la tua richiesta e ti contatterà al più presto. 
                    Se hai fornito un indirizzo email, riceverai aggiornamenti sullo stato della pratica.
                </p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            @if($supportEmail)
                <div class="support-info">
                    Hai domande? Contattaci: 
                    <a href="mailto:{{ $supportEmail }}" class="support-email">{{ $supportEmail }}</a>
                </div>
            @endif
            
            <div class="footer-text">
                Questa email è stata generata automaticamente dal sistema {{ $tenant->name ?? 'ChatBot Platform' }}.<br>
                Per favore non rispondere direttamente a questa email.
            </div>
        </div>
    </div>
</body>
</html>




















