<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VonageMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'message_id',
        'from',
        'to',
        'message',
        'direction', // 'inbound' o 'outbound'
        'channel', // 'whatsapp', 'messenger', 'sms'
        'status',
        'status_updated_at',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
        'status_updated_at' => 'datetime'
    ];

    /**
     * Relazione con Tenant
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
