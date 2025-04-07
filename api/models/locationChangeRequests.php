<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class locationChangeRequests extends Model {
    protected $table = 'location_change_requests';
    protected $primaryKey = 'request_id';
    public $timestamps = false;

    protected $fillable = [
        'device_id','current_location_id','requested_location_id','requested_by_admin_id','approved_by_admin_id','status','request_date','approval_date'
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id', 'device_id');
    }

    // Add other relationships you're trying to use
    public function current_location()
    {
        return $this->belongsTo(Location::class, 'current_location_id', 'location_id');
    }

    public function requested_location()
    {
        return $this->belongsTo(Location::class, 'requested_location_id', 'location_id');
    }

    public function requested_by()
    {
        return $this->belongsTo(Admin::class, 'requested_by_admin_id', 'admin_id');
    }

    public function approved_by()
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id', 'admin_id');
    }

}
