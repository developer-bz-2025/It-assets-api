<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Admin extends Model {
    protected $table = 'admin';
    protected $primaryKey = 'admin_id';
    public $timestamps = false;

    protected $fillable = [
        'admin_username','admin_password','emp_id'
    ];


    public function employee()
    {
        return $this->belongsTo(Employee::class, 'emp_id', 'emp_id');
    }
}
