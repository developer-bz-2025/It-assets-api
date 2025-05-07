<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceStatus extends Model
{
    protected $table = 'maintenance_status';

    protected $primaryKey = 'id';


    public $timestamps = false;

    protected $fillable = ['name'];

    // Relationships
    public function maintenances()
    {
        return $this->hasMany(Maintenance::class, 'status_id');
    }
}
