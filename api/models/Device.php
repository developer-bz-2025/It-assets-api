<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model {
    protected $table = 'device';
    protected $primaryKey = 'device_id';
    public $timestamps = false;

    protected $fillable = [
        'device_sn', 'device_acquisition_date', 'device_model', 'device_notes', 
        'brand_id', 'status_id', 'location_id', 'pr_id', 'emp_id'
    ];

      /**
     * Get the brand associated with the device.
     */
    public function brand() {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * Get the status associated with the device.
     */
    public function status() {
        return $this->belongsTo(Status::class, 'status_id');
    }

    /**
     * Get the location associated with the device.
     */
    public function location() {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * Get the PR associated with the device.
     */
    public function pr() {
        return $this->belongsTo(Pr::class, 'pr_id');
    }

    /**
     * Get the employee associated with the device.
     */
    public function employee() {
        return $this->belongsTo(Employee::class, 'emp_id');
    }
}
