<?php
namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_log';
    protected $primaryKey = 'id';

    public $timestamps = true;
    protected $fillable = ['admin_id', 'action_id'];

    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function action()
    {
        return $this->belongsTo(Action::class, 'action_id');
    }
}
