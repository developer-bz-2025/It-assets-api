<?php

namespace Api\Controllers;

use Illuminate\Database\Capsule\Manager as DB;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\locationChangeRequests;
use Carbon\Carbon;
use Api\Models\Admin;
use Api\Models\Device;
use Api\Models\Notification;
use Api\Models\NotificationRecipient;


class LocationChangeController
{

    public function getMyPendingRequests(Request $request, Response $response) {
        try {
            // Get admin_id from JWT token
            $decodedToken = $request->getAttribute('admin');
            $adminId = $decodedToken->admin_id;
    
    
            // Get admin's location through relationships
            $admin = Admin::with(['employee.location'])
                ->findOrFail($adminId);
    
            if (!$admin->employee || !$admin->employee->emp_locationId) {
             
            $response->getBody()->write(json_encode(['error' => 'Admin location not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
    
            $myLocationId = $admin->employee->emp_locationId;
    
            // Get pending requests for this location
            $requests = LocationChangeRequests::with([
                    'device',
                    'current_location',
                    'requested_location',
                    'requested_by.employee'
                ])
                ->where('requested_location_id', $myLocationId)
                ->where('status', 'pending')
                ->get();
    
            // return $response->withJson([
            //     'status' => 'success',
            //     'data' => $requests
            // ]);
    
            $response->getBody()->write(json_encode(["status"=> "success",
            'data' => $requests]));
            return $response->withHeader('Content-Type', 'application/json');
    
            
    
        } catch (\Exception $e) {
          
    
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    
           
        }
    }

    public function approveLocationChange(Request $request, Response $response, array $args) {
        $requestId = $args['request_id'];
        
        try {
            // Start transaction
            DB::beginTransaction();
    
            // 1. Find the request with relationships
            $locationRequest = LocationChangeRequests::with(['device', 'requested_by'])
                ->findOrFail($requestId);
    
            // 2. Get admin from token
            $decodedToken = $request->getAttribute('admin');
            $adminId = $decodedToken->admin_id;
    
            // 3. Update request status
            $locationRequest->update([
                'status' => 'approved',
                'approved_by_admin_id' => $adminId,
                'approval_date' => Carbon::now()
            ]);
    
            // 4. Update device location
            Device::where('device_id', $locationRequest->device_id)
                ->update(['location_id' => $locationRequest->requested_location_id]);
    
            // 5. Create notification for the requester
            $notification = Notification::create([
                'notification_type' => 'location_change',
                'title' => 'Location Change Approved',
                'content' => sprintf(
                    'Your location change request for %s (SN: %s) has been approved',
                    $locationRequest->device->device_model,
                    $locationRequest->device->device_sn
                )
            ]);
    
            // 6. Create notification recipient
            NotificationRecipient::create([
                'notification_id' => $notification->notification_id,
                'sender_admin_id' => $adminId, // Approving admin
                'recipient_admin_id' => $locationRequest->requested_by_admin_id, // Original requester
                'is_read' => false
            ]);
    
      
    
            DB::commit();
    
                $response->getBody()->write(json_encode(['status' => 'success','message'=>'Location change approved!']));
                 return $response->withStatus(200)->withHeader('Content-Type', 'application/json'); 
    
        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
// public function approveLocationChange(Request $request, Response $response, array $args) {
//     $requestId = $args['request_id'];
//     $data = $request->getParsedBody();

//     // Fetch the request
//     $locationRequest = LocationChangeRequests::find($requestId);
//     if (!$locationRequest) {
//         $response->getBody()->write(json_encode(['error' => 'Request not found']));
//         return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
//     }

//     // $adminId = $this->getAdminIdFromToken($request);
//     $decodedToken = $request->getAttribute('admin');
//     $adminId = $decodedToken->admin_id;

//     // Update request status
//     $locationRequest->update([
//         'status' => 'approved',
//         'approved_by_admin_id' => $adminId ,
//         'approval_date' => Carbon::now()
//     ]);

//     // Update device location
//     Device::where('device_id', $locationRequest->device_id)
//         ->update(['location_id' => $locationRequest->requested_location_id]);

//     // Log the action
//     // DeviceManagment::create([
//     //     'dm_data' => now(),
//     //     'device_id' => $locationRequest->device_id,
//     //     'admin_id' => $data['admin_id'],
//     //     'location_request_id' => $requestId
//     // ]);

//     $response->getBody()->write(json_encode(['status' => 'success','message'=>'Location change approved!']));
//     return $response->withStatus(200)->withHeader('Content-Type', 'application/json'); 
// }

// public function rejectLocationChange(Request $request, Response $response, array $args) {
//     $requestId = $args['request_id'];
//     $data = $request->getParsedBody();

//     $decodedToken = $request->getAttribute('admin');
//     $adminId = $decodedToken->admin_id;

//     $locationRequest = LocationChangeRequests::find($requestId);
//     if (!$locationRequest) {
//         $response->getBody()->write(json_encode(['error' => 'Request not found']));
//         return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
//     }

//     $locationRequest->update([
//         'status' => 'rejected',
//         'approved_by_admin_id' => $adminId,
//         'approval_date' => Carbon::now()
//     ]);


//     $response->getBody()->write(json_encode(['status' => 'success','message'=>'Location change rejected!']));
//     return $response->withStatus(200)->withHeader('Content-Type', 'application/json'); 
// }


public function rejectLocationChange(Request $request, Response $response, array $args) {
    $requestId = $args['request_id'];
    
    try {
        // Start transaction
        DB::beginTransaction();

        // 1. Find the request with relationships
        $locationRequest = LocationChangeRequests::with(['device', 'requested_by'])
            ->findOrFail($requestId);

        // 2. Get admin from token
        $decodedToken = $request->getAttribute('admin');
        $adminId = $decodedToken->admin_id;

        // 3. Update request status
        $locationRequest->update([
            'status' => 'rejected',
            'approved_by_admin_id' => $adminId,
            'approval_date' => Carbon::now()
        ]);

    

        // 4. Create notification for the requester
        $notification = Notification::create([
            'notification_type' => 'location_change',
            'title' => 'Location Change Rejected',
            'content' => sprintf(
                'Your location change request for %s (SN: %s) has been rejected',
                $locationRequest->device->device_model,
                $locationRequest->device->device_sn
            )
        ]);

        // 6. Create notification recipient
        NotificationRecipient::create([
            'notification_id' => $notification->notification_id,
            'sender_admin_id' => $adminId, // Approving admin
            'recipient_admin_id' => $locationRequest->requested_by_admin_id, // Original requester
            'is_read' => false
        ]);

  

        DB::commit();

            $response->getBody()->write(json_encode(['status' => 'success','message'=>'Location change rejected!']));
             return $response->withStatus(200)->withHeader('Content-Type', 'application/json'); 

    } catch (\Exception $e) {
        DB::rollBack();
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

}