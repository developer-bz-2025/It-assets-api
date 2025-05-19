<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceManagment extends Model {
    protected $table = 'device_managment';
    protected $primaryKey = 'dm_id';
    public $timestamps = false;

    protected $fillable = [
        'dm_date',
        'old_status_id',
        'new_status_id',
        'device_id',
        'admin_id',
        'old_location_id',
        'new_location_id',
        'old_emp_id',
        'new_emp_id',
        'pr_id',
        'notes'
    ];

     // Relationships

     public function device()
     {
         return $this->belongsTo(Device::class, 'device_id');
     }
 
     public function admin()
     {
         return $this->belongsTo(Admin::class, 'admin_id');
     }
 
     public function oldStatus()
     {
         return $this->belongsTo(Status::class, 'old_status_id');
     }
 
     public function newStatus()
     {
         return $this->belongsTo(Status::class, 'new_status_id');
     }
 
     public function oldLocation()
     {
         return $this->belongsTo(Location::class, 'old_location_id');
     }
 
     public function newLocation()
     {
         return $this->belongsTo(Location::class, 'new_location_id');
     }
 
     public function oldEmployee()
     {
         return $this->belongsTo(Employee::class, 'old_emp_id');
     }
 
     public function newEmployee()
     {
         return $this->belongsTo(Employee::class, 'new_emp_id');
     }
 
     public function pr()
     {
         return $this->belongsTo(Pr::class, 'pr_id');
     }
}
