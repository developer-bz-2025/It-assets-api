<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model {
    protected $table = 'status';
    protected $primaryKey = 'status_id';
    public $timestamps = false;

    protected $fillable = [
        'status_name'
    ];
}
