<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model {
    protected $table = 'brand';
    protected $primaryKey = 'brand_id';
    public $timestamps = false;

    protected $fillable = [
        'brand_name'
    ];
}
