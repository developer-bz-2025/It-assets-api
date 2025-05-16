<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class Action extends Model
{
    protected $table = 'action';
    protected $primaryKey = 'action_id';

    public $timestamps = false;
    protected $fillable = ['action_name', 'description'];
}
