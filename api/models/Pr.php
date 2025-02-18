<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Pr extends Model {
    protected $table = 'pr';
    protected $primaryKey = 'pr_id';
    public $timestamps = false;

    protected $fillable = [
        'pr_code','pr_path','pr_date','items_count'
    ];

    
}
