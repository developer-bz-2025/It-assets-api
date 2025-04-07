<?php

namespace Api\Models;

use Illuminate\Database\Eloquent\Model;

use Api\Models\NotificationRecipient;

class Notification extends Model {

    protected $primaryKey = 'notification_id';
    public $timestamps = false;
    
    protected $fillable = [
        'title',
        'content',
        'created_at'
    ];

    public function recipients()
    {
        return $this->hasMany(NotificationRecipient::class, 'notification_id');
    }
}