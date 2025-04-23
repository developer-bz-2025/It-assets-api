<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementAdmin extends Model {
    protected $table = 'procurement_admin';
    protected $primaryKey = 'pa_id';
    public $timestamps = false;

    protected $fillable = [
        'emp_id'
    ];
}
