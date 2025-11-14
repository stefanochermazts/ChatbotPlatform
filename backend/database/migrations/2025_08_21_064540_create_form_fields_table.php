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
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_form_id')->constrained()->cascadeOnDelete();

            // Field configuration
            $table->string('name'); // Nome campo (es. 'email', 'nome', 'telefono')
            $table->string('label'); // Label visibile (es. 'Indirizzo Email')
            $table->enum('type', [
                'text',        // Input testo
                'email',       // Input email
                'phone',       // Input telefono
                'textarea',    // Textarea
                'select',      // Select dropdown
                'checkbox',    // Checkbox
                'radio',       // Radio buttons
                'date',        // Input data
                'number',       // Input numerico
            ])->default('text');

            // Field properties
            $table->string('placeholder')->nullable(); // Placeholder text
            $table->boolean('required')->default(false); // Campo obbligatorio
            $table->json('validation_rules')->nullable(); // Regole di validazione Laravel
            $table->json('options')->nullable(); // Opzioni per select/radio/checkbox
            $table->text('help_text')->nullable(); // Testo di aiuto

            // Display order
            $table->integer('order')->default(0); // Ordine di visualizzazione
            $table->boolean('active')->default(true); // Campo attivo

            $table->timestamps();

            // Indexes
            $table->index(['tenant_form_id', 'order']);
            $table->index(['tenant_form_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
