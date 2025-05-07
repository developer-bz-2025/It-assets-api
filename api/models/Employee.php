<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model {
    protected $table = 'employee';
    protected $primaryKey = 'emp_id';
    public $timestamps = false;

    protected $fillable = [
        'emp_name','emp_no', 'emp_email', 'title_id', 'department_id', 'emp_project', 'emp_locationId'
    ];

    public function location()
    {
        return $this->belongsTo(Location::class, 'emp_locationId', 'location_id');
    }
}
