<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\DeviceProcurement;
use Api\Models\Pr;
use Illuminate\Database\Capsule\Manager as DB;
use Api\Models\Notification;
use Api\Models\NotificationRecipient;

class DeviceProcurementController
{

    // Add a new procurement record
    public function addProcurement(Request $request, Response $response)
    {
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        // echo $data['sns'];
        // Debug the received data
        // var_dump($data['sns']);  // Use var_dump to check the data
        // die(); // Stop execution to check the output

        // Validate required fields
        $requiredFields = ['sns', 'acquisition_date', 'pr_code'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }

        // Validate serial numbers as an array
        if (!is_array($data['sns'])) {
            $response->getBody()->write(json_encode(['error' => 'Serial numbers must be an array with at least one entry']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Validate each SN entry has required fields
        foreach ($data['sns'] as $entry) {
            if (!isset($entry['sn']) || !isset($entry['device_type'])) {
                $response->getBody()->write(json_encode(['error' => 'Each SN entry must have sn and device_type']));
                return $response->withStatus(400);
            }
        }

        // Extract SNs for existing check
        $sns = array_column($data['sns'], 'sn');
        $existingSNs = DeviceProcurement::whereIn('sn', $sns)->pluck('sn')->toArray();


        // Count serial numbers for items_count
        $itemsCount = count($data['sns']);

        // Check if any serial number already exists
        // $existingSNs = DeviceProcurement::whereIn('sn', $data['sns'])->pluck('sn')->toArray();
        if (!empty($existingSNs)) {
            $response->getBody()->write(json_encode([
                'error' => 'Some Serial Numbers already exist',
                'existing_sn' => $existingSNs
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if PR code already exists
        if (PR::where('pr_code', $data['pr_code'])->exists()) {
            $response->getBody()->write(json_encode(['error' => 'PR code already exists']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Handle file upload (PDF)
        $pdfPath = null;
        if (isset($uploadedFiles['pr_document'])) {
            $pdf = $uploadedFiles['pr_document'];
            if ($pdf->getError() === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . "/../controllersuploads/pr_docs/";
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $fileExtension = pathinfo($pdf->getClientFilename(), PATHINFO_EXTENSION);
                $fileName = "{$data['pr_code']}_{$data['acquisition_date']}." . $fileExtension;
                $pdfPath = $uploadDir . $fileName;
                $pdf->moveTo($pdfPath);
            } else {
                $response->getBody()->write(json_encode(['error' => 'Error uploading PDF file']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }

        DB::beginTransaction();
        try {
            // Insert into PR table
            $pr = Pr::create([
                'pr_code' => $data['pr_code'],
                'pr_date' => $data['acquisition_date'],
                'items_count' => $itemsCount,
                'pr_path' => $pdfPath ? basename($pdfPath) : null // Store only the filename
            ]);

            // Insert multiple serial numbers into Device Procurement table
            $procurements = [];
            foreach ($data['sns'] as $entry) {
                $procurements[] = [
                    'sn' => $entry['sn'],
                    'device_type' => $entry['device_type'],
                    'acquisition_date' => $data['acquisition_date'],
                    'pr_id' => $pr->pr_id,
                ];
            }
            DeviceProcurement::insert($procurements); // Bulk insert for efficiency

            DB::commit();
            $response->getBody()->write(json_encode([
                "status" => "success",
                'message' => 'Procurement record added',
                'pr' => $pr,
                'procurements' => $procurements
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    // public function getAllProcurements(Request $request, Response $response) {
    //     // Fetch all procurement records along with their related serial numbers and document paths
    //     $procurements = DB::table('pr')
    //         ->leftJoin('device_procurement', 'pr.pr_id', '=', 'device_procurement.pr_id')
    //         ->select('pr.*', 'device_procurement.sn')
    //         ->get();

    //     // Return data as JSON
    //     return $response->withJson($procurements);
    // }




    // Upload PR document
    public function uploadPrDocument(Request $request, Response $response, $args)
    {
        $pr_id = $args['pr_id'];
        $uploadedFiles = $request->getUploadedFiles();

        if (empty($uploadedFiles['pr_document'])) {

            $response->getBody()->write(json_encode(['error' => 'No file uploaded']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $file = $uploadedFiles['pr_document'];
        $directory = __DIR__ . '/../../uploads/';
        $filename = $pr_id . '-' . time() . '-' . $file->getClientFilename();
        $file->moveTo($directory . $filename);

        // Update PR record with file path
        $pr = Pr::find($pr_id);
        if ($pr) {
            $pr->pr_path = 'uploads/' . $filename;
            $pr->save();
        }

        $response->getBody()->write(json_encode(['message' => 'File uploaded successfully', 'path' => $pr->pr_path]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // Get all procurement records
    // public function getAllProcurements(Request $request, Response $response) {
    //     // $procurements = DeviceProcurement::with('pr')->get();

    //     $procurements = DB::table('pr')
    //     ->leftJoin('device_procurement', 'pr.pr_id', '=', 'device_procurement.pr_id')
    //     ->select('pr.*', 'device_procurement.sn')
    //     ->get();

    //     $response->getBody()->write(json_encode($procurements));
    //     return $response->withHeader('Content-Type', 'application/json');

    // }

    public function getAllProcurements(Request $request, Response $response)
    {
        // Retrieve procurements and group serial numbers by PR
        $procurements = DB::table('pr')
            ->leftJoin('device_procurement', 'pr.pr_id', '=', 'device_procurement.pr_id')
            ->select('pr.pr_id', 'pr.pr_code', 'pr.pr_date', 'pr.pr_path', 'device_procurement.sn', 'device_procurement.device_type', 'device_procurement.acquisition_date')
            ->orderByDesc('pr.pr_id')
            ->get()
            ->groupBy('pr_id'); // Group by pr_id to get SNs as an array

        // Format data: combine PR data with the SNs array
        $result = $procurements->map(function ($items) {
            $pr = $items->first(); // Get the first item for PR info
            // $snList = $items->pluck('sn', ); // Extract SNs into an array
            // Extract SNs and device_types into an array of associative arrays
            $snList = $items->map(function ($item) {
                return [
                    'sn' => $item->sn,
                    'device_type' => $item->device_type,
                ];
            });
            $acquisitionDates = $items->pluck('acquisition_date');

            return [
                'pr_id' => $pr->pr_id,
                'pr_code' => $pr->pr_code,
                'pr_date' => $pr->pr_date,
                'pr_path' => $pr->pr_path,
                'acquisition_dates' => $acquisitionDates->first(),
                'serial_numbers' => $snList->toArray(),
            ];
        });

        // Return the result as JSON
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getPrDetails(Request $request, Response $response, $args)
    {
        $pr_id = $args['pr_id'];
        try {
            $prDetails = DB::table('pr')
                ->leftJoin('device_procurement', 'pr.pr_id', '=', 'device_procurement.pr_id')
                ->select('pr.pr_id', 'pr.pr_code', 'pr.pr_date', 'pr.pr_path', 'device_procurement.sn', 'device_procurement.id', 'device_procurement.device_type', 'device_procurement.acquisition_date')
                ->where('pr.pr_id', $pr_id)
                ->get();
            $response->getBody()->write(json_encode(["status" => "success", "prDetails" => $prDetails]));
            return $response->withHeader('Content-Type', 'application/json');


        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');

        }
    }

    public function submitEditRequest(Request $request, Response $response)
    {
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $decodedToken = $request->getAttribute('admin');
        $adminId = $decodedToken->admin_id;



        try {
            // Validate required fields
            if (empty($data['pr_id'])) {
                throw new \Exception('PR ID is required');
            }

            // check if the or has already edit request
            $prEditRequest = DB::table('pr_edit_requests')
                ->where('pr_id', $data['pr_id'])
                ->where('status', 'pending')
                ->first();

            if ($prEditRequest) {
                $response->getBody()->write(json_encode([
                    'status' => 'failure',
                    'message' => 'Already has Edit request'
                ]));
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }

            // Begin database transaction
            DB::beginTransaction();

            // Handle file upload if exists
            $newPrPath = null;
            if (!empty($uploadedFiles['new_pr_document'])) {
                $file = $uploadedFiles['new_pr_document'];
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../controllersuploads/pr_docs/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.\-]/', '_', $file->getClientFilename());
                    $file->moveTo($uploadDir . $filename);
                    $newPrPath = $filename;
                }
            }

            // Get current PR details for comparison
            $currentPr = DB::table('pr')
                ->where('pr_id', $data['pr_id'])
                ->first();

            if (!$currentPr) {
                throw new \Exception('PR not found');
            }

            // Insert into pr_edit_requests
            $requestId = DB::table('pr_edit_requests')->insertGetId([
                'pr_id' => $data['pr_id'],
                'requested_by' => $adminId,
                'old_pr_code' => $currentPr->pr_code,
                'new_pr_code' => $data['new_pr_code'] ?? $currentPr->pr_code,
                'old_acquisition_date' => $currentPr->pr_date,
                'new_acquisition_date' => $data['new_acquisition_date'] ?? $currentPr->pr_date,
                'old_pr_path' => $currentPr->pr_path,
                'new_pr_path' => $newPrPath ?? $currentPr->pr_path,
                'request_date' => date('Y-m-d H:i:s'),
                'status' => 'pending'
            ]);

            // Process device changes
            // $deviceChanges = json_decode($data['device_changes'], true);
            $deviceChanges = [];

            if (!empty($data['device_changes'])) {
                $deviceChanges = json_decode($data['device_changes'], true);
            }

            if (!empty($deviceChanges)) {
                $deviceRecords = [];

                foreach ($deviceChanges as $change) {
                    $deviceRecords[] = [
                        'request_id' => $requestId,
                        'device_id' => $change['device_id'] ?? null,
                        'old_sn' => $change['old_sn'] ?? null,
                        'new_sn' => $change['new_sn'] ?? null,
                        'old_device_type' => $change['old_device_type'] ?? null,
                        'new_device_type' => $change['new_device_type'] ?? null,
                        'action' => $change['action']
                    ];
                }

                DB::table('pr_edit_devices')->insert($deviceRecords);
            }

            // Commit transaction
            DB::commit();

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => 'Edit request submitted for approval',
                'request_id' => $requestId
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getPendingEditRequests(Request $request, Response $response)
    {
        try {

            // Get the decoded token (JWT)
            // $decodedToken = $request->getAttribute('admin');

            // // Check if the user is a Super Admin
            // if (!$decodedToken->is_super_admin) {
            //     $response->getBody()->write(json_encode(['error' => 'Unauthorized: Only Super Admins can get these pending requests']));
            //     return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            // }

            $requests = DB::table('pr_edit_requests as r')
                ->join('admin as a', 'r.requested_by', '=', 'a.admin_id')
                ->join('pr as p', 'r.pr_id', '=', 'p.pr_id')
                ->select(
                    'r.*',
                    'a.admin_username as requested_by_name',
                    'p.pr_code as current_pr_code'
                )
                ->where('r.status', 'pending')
                ->orderBy('r.request_date', 'desc')
                ->get();

            // Get device changes for each request
            $requestsWithChanges = [];
            foreach ($requests as $request) {
                $deviceChanges = DB::table('pr_edit_devices')
                    ->where('request_id', $request->request_id)
                    ->get();

                $request->device_changes = $deviceChanges;
                $requestsWithChanges[] = $request;
            }



            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $requestsWithChanges
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getDeviceChanges(Request $request, Response $response, $args)
    {
        $requestId = $args['request_id'];

        try {
            // Get device changes for this request
            $deviceChanges = DB::table('pr_edit_devices')
                ->where('request_id', $requestId)
                ->get();

            // Get additional device info for context
            $changesWithDetails = [];
            foreach ($deviceChanges as $change) {
                $deviceDetails = [];

                // If this is an update or delete, get current device info
                if ($change->device_id && in_array($change->action, ['update', 'delete'])) {
                    $deviceDetails = DB::table('device_procurement')
                        ->where('id', $change->device_id)
                        ->first();
                }

                $changesWithDetails[] = [
                    'id' => $change->id,
                    'device_id' => $change->device_id,
                    'action' => $change->action,
                    'old_sn' => $change->old_sn,
                    'new_sn' => $change->new_sn,
                    'old_device_type' => $change->old_device_type,
                    'new_device_type' => $change->new_device_type,
                    'current_sn' => $deviceDetails->sn ?? null,
                    'current_device_type' => $deviceDetails->device_type ?? null
                ];
            }

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $changesWithDetails
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function processEditRequest(Request $request, Response $response, $args)
    {
        $requestId = $args['requestId'];
        $data = $request->getParsedBody();
        $action = $data['action'] ?? ''; // 'approve' or 'reject'
        $decodedToken = $request->getAttribute('admin');
        $adminId = $decodedToken->admin_id;

        try {
            if (!in_array($action, ['approve', 'reject'])) {
                throw new \Exception('Invalid action');
            }

            // Begin transaction
            DB::beginTransaction();

            // Update request status
            DB::table('pr_edit_requests')
                ->where('request_id', $requestId)
                ->update(['status' => $action === 'approve' ? 'approved' : 'rejected']);

            // Get the request details
            $editRequest = DB::table('pr_edit_requests')
                ->where('request_id', $requestId)
                ->first();

            $pr = DB::table('pr')
                ->where('pr_id', $editRequest->pr_id)
                ->first();

            if ($action === 'approve') {

                if (!$editRequest) {
                    throw new \Exception('Edit request not found');
                }



                // Update PR if fields changed
                $updates = [];
                if ($editRequest->new_pr_code !== $editRequest->old_pr_code) {
                    $updates['pr_code'] = $editRequest->new_pr_code;
                }
                if ($editRequest->new_acquisition_date !== $editRequest->old_acquisition_date) {
                    $updates['pr_date'] = $editRequest->new_acquisition_date;
                }
                if ($editRequest->new_pr_path !== $editRequest->old_pr_path) {
                    $updates['pr_path'] = $editRequest->new_pr_path;
                }

                if (!empty($updates)) {
                    DB::table('pr')
                        ->where('pr_id', $editRequest->pr_id)
                        ->update($updates);
                }

                // Process device changes
                $deviceChanges = DB::table('pr_edit_devices')
                    ->where('request_id', $requestId)
                    ->get();

                foreach ($deviceChanges as $change) {
                    switch ($change->action) {
                        case 'update':
                            DB::table('device_procurement')
                                ->where('id', $change->device_id)
                                ->update([
                                    'sn' => $change->new_sn,
                                    'device_type' => $change->new_device_type
                                ]);
                            break;

                        case 'delete':
                            DB::table('device_procurement')
                                ->where('id', $change->device_id)
                                ->delete();
                            break;

                        case 'add':
                            DB::table('device_procurement')->insert([
                                'pr_id' => $editRequest->pr_id,
                                'sn' => $change->new_sn,
                                'device_type' => $change->new_device_type,
                                'acquisition_date' => $editRequest->new_acquisition_date ?? $editRequest->old_acquisition_date
                            ]);
                            break;
                    }
                }
            }

            $notificationTitle = $action === 'approve'
                ? 'PR Edit Request Approved'
                : 'PR Edit Request Rejected';

            $notificationContent = $action === 'approve'
                ? sprintf('Your PR edit request for PR ID %s has been approved', $editRequest->pr_id)
                : sprintf('Your PR edit request for PR ID %s has been rejected', $editRequest->pr_id);

            // include PR code if available
            if ($pr && isset($pr->pr_code)) {
                $notificationContent = $action === 'approve'
                    ? sprintf('Your PR edit request for %s (ID: %s) has been approved', $pr->pr_code, $editRequest->pr_id)
                    : sprintf('Your PR edit request for %s (ID: %s) has been rejected', $pr->pr_code, $editRequest->pr_id);
            }

            $notification = Notification::create([
                'title' => $notificationTitle,
                'content' => $notificationContent
            ]);

            // Create notification recipient (assuming requested_by_admin_id exists in pr_edit_requests)
            NotificationRecipient::create([
                'notification_id' => $notification->notification_id,
                'sender_admin_id' => $adminId,
                'recipient_admin_id' => $editRequest->requested_by,
                'is_read' => false
            ]);

            // Commit transaction
            DB::commit();

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'message' => "Request {$action}d"
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            // Rollback transaction on error
            DB::rollBack();

            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

}
