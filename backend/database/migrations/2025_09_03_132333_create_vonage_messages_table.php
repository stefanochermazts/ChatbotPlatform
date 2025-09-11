<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vonage_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('message_id')->nullable()->index(); // UUID da Vonage
            $table->string('from'); // Numero/ID mittente
            $table->string('to'); // Numero/ID destinatario
            $table->text('message'); // Contenuto del messaggio
            $table->enum('direction', ['inbound', 'outbound']); // Direzione
            $table->enum('channel', ['whatsapp', 'messenger', 'sms']); // Canale
            $table->string('status')->default('sent'); // delivered, read, failed, etc.
            $table->timestamp('status_updated_at')->nullable();
            $table->json('metadata')->nullable(); // Dati aggiuntivi
            $table->timestamps();

            // Indici per performance
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'channel']);
            // message_id già indicizzato con ->index() alla riga 17
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vonage_messages');
    }
};