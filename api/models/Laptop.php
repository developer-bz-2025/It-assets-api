<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Laptop extends Model
{
    protected $table = 'laptop';
    protected $primaryKey = 'laptop_id';
    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'laptop_ram',
        'laptop_storageType',
        'laptop_storageSize',
        'laptop_processor',
        'laptop_gth'
    ];

    public function device()
    {
        return $this->belongsTo(Device::class,'device_id'); 
    }
}
