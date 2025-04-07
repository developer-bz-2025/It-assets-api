<?php

namespace Api\Controllers;



use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Api\Models\NotificationRecipient;
use Carbon\Carbon;


class NotificationController{

    public function getAdminNotifications(Request $request, Response $response)
{
     // Get admin_id from JWT token
     $decodedToken = $request->getAttribute('admin');
     $adminId = $decodedToken->admin_id;

    $notifications = NotificationRecipient::with(['notification', 'sender'])
        ->where('recipient_admin_id', $adminId)
        ->orderBy('id', 'desc')
        ->get();


    $response->getBody()->write(json_encode(["status"=> "success",
            'data' => $notifications]));
            return $response->withHeader('Content-Type', 'application/json');
}

public function markAsRead(Request $request, Response $response, array $args)
{
    try {
        $notificationId = $args['notification_id'];
        $decodedToken = $request->getAttribute('admin');
        $adminId = $decodedToken->admin_id;

        // Find the notification recipient record
        $notificationRecipient = NotificationRecipient::where([
            ['notification_id', $notificationId],
            ['recipient_admin_id', $adminId] // Ensure admin only marks their own notifications
        ])->first();

        if (!$notificationRecipient) {
      

            $response->getBody()->write(json_encode(['status' => 'error','message' => 'Notification not found or unauthorized']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Update only if not already read
        if (!$notificationRecipient->is_read) {
            $notificationRecipient->update([
                'is_read' => true,
                'read_at' => Carbon::now()
            ]);
        }



        $response->getBody()->write(json_encode(['status' => 'success','message' => 'Notification marked as read']));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
    

        $response->getBody()->write(json_encode(['status' => 'error','error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function markAllAsRead(Request $request, Response $response)
{
    try {
        $decodedToken = $request->getAttribute('admin');
        $adminId = $decodedToken->admin_id;

        $updated = NotificationRecipient::where([
            ['recipient_admin_id', $adminId],
            ['is_read', false]
        ])->update([
            'is_read' => true,
            'read_at' => Carbon::now()
        ]);

        $response->getBody()->write(json_encode(['status' => 'success','message' => "Marked $updated notifications as read"]));
        return $response->withHeader('Content-Type', 'application/json');


    } catch (\Exception $e) {
        $response->getBody()->write(json_encode(['status' => 'error','error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    
    }
}

}