<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Tablets extends Model
{
    protected $table = 'tablets';
    protected $primaryKey = 'tablet_id';
    public $timestamps = false;

    protected $fillable = ['device_id'];

    public function device()
    {
        return $this->belongsTo(Device::class,'device_id'); 
    }
}
