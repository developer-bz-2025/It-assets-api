<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\Device;
use Api\Models\DeviceEditRequest;
use Api\Models\DeviceManagment;
use Api\Models\Admin;
use Illuminate\Support\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Api\Services\ActivityLoggerService;
use Api\Models\Notification;
use Api\Models\NotificationRecipient;


class DeviceEditRequestController
{

    private ActivityLoggerService $logger;
    private ActivityLoggerService $lifecycleService;

    public function __construct(ActivityLoggerService $logger, ActivityLoggerService $lifecycleService)
    {
        $this->logger = $logger;
        $this->lifecycleService = $lifecycleService;

    }

    public function createDeviceEditRequest(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        $deviceId = $data['device_id'] ?? null;
        $requestedChanges = $data['requested_changes'] ?? [];

        // Get admin from JWT
        $admin = $request->getAttribute('admin');
        $adminId = $admin->admin_id ?? null;

        if (!$deviceId || empty($requestedChanges)) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Missing device ID or requested changes.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $requestRecord = DeviceEditRequest::create([
                'device_id' => $deviceId,
                'requested_by_admin_id' => $adminId,
                'requested_changes' => json_encode($requestedChanges),
                'status' => 'pending'
            ]);
            $this->logger->log($adminId, 'request_device_edit');


            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => 'Edit request submitted successfully.',
                'data' => $requestRecord
            ]));

            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Failed to create edit request.',
                'details' => $e->getMessage()
            ]));

            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    public function approve(Request $request, Response $response, array $args): Response
    {
        $requestId = $args['request_id'];
        $admin = $request->getAttribute('admin'); // Super admin from token
        $adminId = $admin->admin_id;

        $editRequest = DeviceEditRequest::with('device')->find($requestId);

        if (!$editRequest || $editRequest->status !== 'pending') {
            $response->getBody()->write(json_encode([
                'error' => 'Request not found or already processed'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');


        }

        DB::beginTransaction();
        try {
            $device = $editRequest->device;
            $changes = json_decode($editRequest->requested_changes, true);
            $notes = [];

            $oldStatusId = $device->status_id;
            $oldLocationId = $device->location_id;
            $oldEmpId = $device->emp_id;

            foreach ($changes as $field => $change) {
                $old = $change['old'] ?? null;
                $new = $change['new'] ?? null;

                // Update main device or child tables
                if (in_array($field, ['device_sn', 'device_model', 'device_acquisition_date', 'brand_id'])) {
                    if ($field === 'brand_id') {
                        $oldBrandName = \Api\Models\Brand::find($old)?->brand_name ?? 'Unknown';
                        $newBrandName = \Api\Models\Brand::find($new)?->brand_name ?? 'Unknown';
                        $notes[] = "Brand changed from '$oldBrandName' to '$newBrandName'";
                    } else {
                        $notes[] = ucfirst(str_replace('_', ' ', $field)) . " changed from '$old' to '$new'";
                    }

                    $device->$field = $new;
                } elseif (in_array($field, ['laptop_ram', 'laptop_storageSize', 'laptop_storageType', 'laptop_processor', 'laptop_gth'])) {
                    $device->laptop()->update([$field => $new]);
                } elseif (in_array($field, ['screen_size', 'screen_resolution'])) {
                    $device->screen()->update([$field => $new]);
                } elseif (in_array($field, ['sim_number', 'sim_type', 'sim_carrier'])) {
                    $device->sim()->update([$field => $new]);
                }

                // Add change log
                // $notes[] = ucfirst(str_replace('_', ' ', $field)) . " changed from '$old' to '$new'";

            }

            $device->save();

            // Mark edit request as approved
            $editRequest->update([
                'status' => 'approved',
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => Carbon::now()
            ]);

            // Log in device management table
            DeviceManagment::create([
                'device_id' => $device->device_id,
                'dm_date' => Carbon::now(),
                'admin_id' => $adminId,
                'old_status_id' => $oldStatusId,
                'new_status_id' => $device->status_id,
                'old_location_id' => $oldLocationId,
                'new_location_id' => $device->location_id,
                'old_emp_id' => $oldEmpId,
                'new_emp_id' => $device->emp_id,
                'pr_id' => $device->pr_id ?? null,
                'notes' => implode('; ', $notes)
            ]);

            $this->logger->log($adminId, 'approve_request_device_edit');

            
            // 5. Create notification for the requester
            $notification = Notification::create([
                'notification_type' => 'request_edit_device_approve',
                'title' => 'Device Edit request Approved',
                'content' => sprintf(
                    'Super Admin approve edit device request for %s (SN: %s)',
                    $device->device_model,
                    $device->device_sn
                )
            ]);

            // 6. Create notification recipient
            NotificationRecipient::create([
                'notification_id' => $notification->notification_id,
                'sender_admin_id' => $adminId, // Approving admin
                'recipient_admin_id' => $editRequest->requested_by_admin_id, // Original requester
                'is_read' => false
            ]);


            DB::commit();
            $response->getBody()->write(json_encode(['status' => 'success']));
            return $response->withHeader('Content-Type', 'application/json');



        } catch (\Exception $e) {
            DB::rollBack();

            $response->getBody()->write(json_encode([
                'error' => 'Failed to approve request',
                'details' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');


        }
    }


    public function reject(Request $request, Response $response, array $args): Response
    {
        $requestId = $args['request_id'];
        $body = $request->getParsedBody();
        $reason = trim($body['reason'] ?? '');
        $admin = $request->getAttribute('admin');
        $adminId = $admin->admin_id;

        // if (empty($reason)) {
        //     $response->getBody()->write(json_encode([
        //         'error' => 'Rejection reason is required'
        //     ]));
        //     return $response->withStatus(400)->withHeader('Content-Type', 'application/json');

        // }

        $editRequest = DeviceEditRequest::find($requestId);

        if (!$editRequest || $editRequest->status !== 'pending') {
            $response->getBody()->write(json_encode([
                'error' => 'Request not found or already processed'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');

        }

        $editRequest->update([
            'status' => 'rejected',
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => Carbon::now(),
            'rejection_reason' => $reason
        ]);

        $this->logger->log($adminId, 'reject_request_device_edit');

          // 5. Create notification for the requester
            $notification = Notification::create([
                'notification_type' => 'request_edit_device_reject',
                'title' => 'Device Edit request Reject',
                'content' => sprintf(
                    'Super Admin reject edit device request for %s (SN: %s)',
                    $editRequest->device->device_model,
                    $editRequest->device->device_sn
                )
            ]);

            // 6. Create notification recipient
            NotificationRecipient::create([
                'notification_id' => $notification->notification_id,
                'sender_admin_id' => $adminId, // Approving admin
                'recipient_admin_id' => $editRequest->requested_by_admin_id, // Original requester
                'is_read' => false
            ]);

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'message' => 'Request rejected'
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

    }

    public function getPendingDeviceEditRequests(Request $request, Response $response): Response
    {
        try {
            // Load pending requests with related device and admin info
            $requests = DeviceEditRequest::with(['device', 'requestedBy'])
                ->where('status', 'pending')
                ->orderByDesc('submitted_at')
                ->get();

            $requests->transform(function ($req) {
                $changes = json_decode($req->requested_changes, true);

                // Detect device type based on related models
                $device = $req->device;
                $type = null;

                if ($device?->laptop)
                    $type = 'laptop';
                elseif ($device?->mobile)
                    $type = 'mobile';
                elseif ($device?->sim)
                    $type = 'sim';
                elseif ($device?->tablet)
                    $type = 'tablet';
                elseif ($device?->screen)
                    $type = 'screen';
                else
                    $type = 'other';

                // If brand_id is being changed, convert old/new values to brand names
                if (isset($changes['brand_id'])) {
                    $oldBrand = \Api\Models\Brand::find($changes['brand_id']['old']);
                    $newBrand = \Api\Models\Brand::find($changes['brand_id']['new']);

                    // Add brand names alongside IDs
                    $changes['brand_id']['old_label'] = $oldBrand->brand_name ?? 'Unknown';
                    $changes['brand_id']['new_label'] = $newBrand->brand_name ?? 'Unknown';
                }
                return [
                    'id' => $req->id,
                    'device_id' => $req->device_id,
                    'device_type' => $type,
                    'requested_by_admin_id' => $req->requested_by_admin_id,
                    'requested_by_username' => optional($req->requestedBy)->admin_username,
                    'submitted_at' => $req->submitted_at,
                    'requested_changes' => $changes,
                    'device_sn' => optional($req->device)->device_sn,
                    'device_model' => optional($req->device)->device_model,
                ];
            });

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $requests
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch edit requests',
                'details' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}
