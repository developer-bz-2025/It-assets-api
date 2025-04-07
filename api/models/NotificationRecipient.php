<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

use Api\Models\Notification;

class NotificationRecipient extends Model {


    protected $table = 'notification_recipients';
    protected $primaryKey = 'id';
    public $timestamps = false;
    
    protected $fillable = [
        'notification_id',
        'sender_admin_id',
        'recipient_admin_id',
        'is_read',
        'read_at'
    ];

    public function sender()
    {
        return $this->belongsTo(Admin::class, 'sender_admin_id');
    }

    public function recipient()
    {
        return $this->belongsTo(Admin::class, 'recipient_admin_id');
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class, 'notification_id');
    }
}