<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Sim extends Model
{
    protected $table = 'sim';
    protected $primaryKey = 'sim_id';
    public $timestamps = false;

    protected $fillable = ['device_id', 'sim_number', 'sim_type', 'sim_carrier'];

    public function device()
    {
        return $this->belongsTo(Device::class,'device_id'); 
    }
}
