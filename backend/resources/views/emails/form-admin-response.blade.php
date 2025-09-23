<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Risposta alla tua richiesta - {{ $tenant->name }}</title>
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
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .email-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin: 0 0 8px 0;
        }
        
        .tenant-info {
            font-size: 16px;
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
        
        .response-message {
            background: #f0fff4;
            border: 2px solid #68d391;
            border-radius: 8px;
            padding: 25px;
            margin: 25px 0;
        }
        
        .response-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #c6f6d5;
        }
        
        .response-from {
            font-weight: 600;
            color: #22543d;
        }
        
        .response-date {
            font-size: 14px;
            color: #2f855a;
        }
        
        .response-content {
            color: #1a202c;
            line-height: 1.7;
            font-size: 16px;
        }
        
        .original-request {
            background: #f7fafc;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            border-left: 4px solid #4299e1;
        }
        
        .original-request-title {
            color: #2c5282;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .original-request-content {
            color: #4a5568;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .original-data {
            background: white;
            border-radius: 6px;
            padding: 15px;
            border: 1px solid #e2e8f0;
        }
        
        .original-data-item {
            display: flex;
            padding: 5px 0;
        }
        
        .original-data-label {
            font-weight: 600;
            color: #4a5568;
            min-width: 100px;
            margin-right: 10px;
        }
        
        .original-data-value {
            color: #2d3748;
            flex: 1;
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
        
        .need-help {
            background: #ebf8ff;
            border: 2px solid #90cdf4;
            border-radius: 8px;
            padding: 20px;
            margin: 25px 0;
            text-align: center;
        }
        
        .need-help h4 {
            color: #2c5282;
            margin: 0 0 10px 0;
            font-size: 18px;
        }
        
        .need-help p {
            color: #2c5282;
            margin: 0 0 15px 0;
        }
        
        .contact-button {
            display: inline-block;
            background: #4299e1;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
        }
        
        .email-footer {
            background: #2d3748;
            color: white;
            padding: 25px 30px;
            text-align: center;
            font-size: 14px;
        }
        
        .footer-text {
            opacity: 0.8;
            font-size: 12px;
            margin-top: 10px;
        }
        
        /* Mobile responsive */
        @media (max-width: 600px) {
            body {
                padding: 10px;
            }
            
            .email-header, .email-body, .email-footer {
                padding: 20px;
            }
            
            .response-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .original-data-item {
                flex-direction: column;
            }
            
            .original-data-label {
                min-width: auto;
                margin-bottom: 3px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1>💬 Risposta alla tua richiesta</h1>
            <div class="tenant-info">{{ $tenant->name }}</div>
        </div>
        
        <!-- Corpo email -->
        <div class="email-body">
            <div class="greeting">
                Ciao {{ $userName }},
            </div>
            
            <p style="margin-bottom: 25px; color: #4a5568;">
                Abbiamo ricevuto la tua richiesta e siamo felici di poterti fornire una risposta.
            </p>
            
            <!-- Risposta dell'admin -->
            <div class="response-message">
                <div class="response-header">
                    <div class="response-from">
                        📝 Risposta da {{ $adminName }}
                    </div>
                    <div class="response-date">
                        {{ $responseDate }}
                    </div>
                </div>
                <div class="response-content">
                    {!! nl2br(e($response->response_content)) !!}
                </div>
            </div>
            
            <!-- Richiesta originale -->
            <div class="original-request">
                <div class="original-request-title">
                    📋 La tua richiesta originale ({{ $form->name }})
                </div>
                <div class="original-request-content">
                    Inviata il {{ $submission->submitted_at->format('d/m/Y H:i') }}
                </div>
                
                @if($submission->getFormattedDataAttribute() && count($submission->getFormattedDataAttribute()) > 0)
                    <div class="original-data">
                        @foreach($submission->getFormattedDataAttribute() as $field)
                            <div class="original-data-item">
                                <div class="original-data-label">{{ $field['label'] }}:</div>
                                <div class="original-data-value">{{ $field['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            
            <!-- Informazioni submission -->
            <div class="submission-info">
                <div class="submission-info-item">
                    <strong>🆔 ID Richiesta:</strong> #{{ $submissionId }}
                </div>
                <div class="submission-info-item">
                    <strong>📅 Data Risposta:</strong> {{ $responseDate }}
                </div>
            </div>
            
            <!-- Serve altro aiuto? -->
            @if($supportEmail)
                <div class="need-help">
                    <h4>🤝 Serve altro aiuto?</h4>
                    <p>
                        Se hai altre domande o hai bisogno di ulteriori chiarimenti, 
                        non esitare a contattarci.
                    </p>
                    <a href="mailto:{{ $supportEmail }}" class="contact-button">
                        📧 Contattaci
                    </a>
                </div>
            @endif
            
            <!-- Chiusura -->
            <div style="margin-top: 30px; padding: 20px; background: #f0fff4; border-radius: 8px; text-align: center;">
                <p style="margin: 0; color: #22543d; font-weight: 500;">
                    ✅ Grazie per aver utilizzato il nostro servizio!
                </p>
                <p style="margin: 10px 0 0 0; color: #2f855a; font-size: 14px;">
                    Speriamo che la nostra risposta sia stata utile. Il nostro team è sempre a disposizione per assisterti.
                </p>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="email-footer">
            @if($supportEmail)
                <div style="margin-bottom: 15px;">
                    Per ulteriori informazioni: 
                    <a href="mailto:{{ $supportEmail }}" style="color: #90cdf4;">{{ $supportEmail }}</a>
                </div>
            @endif
            
            <div class="footer-text">
                Questa email è stata inviata in risposta alla tua richiesta #{{ $submissionId }}.<br>
                {{ $tenant->name }} - Assistenza Clienti
            </div>
        </div>
    </div>
</body>
</html>






















