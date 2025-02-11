<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceProcurement extends Model {
    protected $table = 'device_procurement';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'sn', 'acquisition_date', 'pr_id'
    ];

    // Relationship with PR table
    public function pr()
    {
        return $this->belongsTo(Pr::class, 'pr_id', 'pr_id');
    }
}
