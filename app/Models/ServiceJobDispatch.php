<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceJobDispatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'action',
        'expected_status',
        'dispatch_token',
        'send_notification',
        'dispatch_attempts',
        'available_at',
        'last_dispatched_at',
        'last_error',
    ];

    protected $casts = [
        'send_notification' => 'boolean',
        'dispatch_attempts' => 'integer',
        'available_at' => 'datetime',
        'last_dispatched_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
