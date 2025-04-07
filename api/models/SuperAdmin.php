<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class SuperAdmin extends Model {
    protected $table = 'super_admin';
    protected $primaryKey = 'sa_id';
    public $timestamps = false;

    protected $fillable = [
        'emp_id'
    ];
}
