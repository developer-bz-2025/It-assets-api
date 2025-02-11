<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Screen extends Model
{
    protected $table = 'screen';
    protected $primaryKey = 'screen_id';
    public $timestamps = false;

    protected $fillable = ['device_id','screen_resolution','screen_size'];

    public function device()
    {
        return $this->belongsTo(Device::class,'device_id'); 
    }
}

