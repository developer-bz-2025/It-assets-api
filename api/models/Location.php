<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model {
    protected $table = 'location';
    protected $primaryKey = 'location_id';
    public $timestamps = false;

    protected $fillable = [
        'location_name','country_id','admin_id'
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
