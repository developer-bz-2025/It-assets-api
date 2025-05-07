<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Maintenance extends Model
{
    protected $table = 'maintenance';

    protected $primaryKey = 'maintenance_id';

    public $timestamps = false;

    protected $fillable = [
        'maintenance_dateIn',
        'maintenance_dateOut',
        'status_id',
        'device_id',
        'submitted_by'
    ];

    // Relationships
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function status()
    {
        return $this->belongsTo(MaintenanceStatus::class, 'status_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(Admin::class, 'submitted_by', 'admin_id');
    }
}
