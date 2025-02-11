<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Mobile extends Model
{
    protected $table = 'mobile';
    protected $primaryKey = 'mobile_id';
    public $timestamps = false;

    protected $fillable = ['device_id'];

    public function device()
    {
        return $this->belongsTo(Device::class,'device_id'); 
    }
}
