<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEditRequest extends Model
{
    protected $table = 'device_edit_requests';
    protected $primaryKey = 'id';
    public $timestamps = false; 

    protected $fillable = [
        'device_id',
        'requested_by_admin_id',
        'status',
        'requested_changes',
        'submitted_at',
        'reviewed_by_admin_id',
        'reviewed_at',
        'rejection_reason'
    ];

    protected $casts = [
        'requested_changes' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime'
    ];

    // Optional: relationships
    public function device() {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function requestedBy() {
        return $this->belongsTo(Admin::class, 'requested_by_admin_id');
    }

    public function reviewedBy() {
        return $this->belongsTo(Admin::class, 'reviewed_by_admin_id');
    }
}
