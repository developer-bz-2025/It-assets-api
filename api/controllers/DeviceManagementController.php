<?php

namespace Api\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Carbon\Carbon;
use Api\Models\Device;
use Api\Models\Laptop;
use Api\Models\Mobile;
use Api\Models\Screen;
use Api\Models\Tablets;
use Api\Models\Sim;
use Api\Models\Brand;
use Api\Models\Status;
use Api\Models\Location;
use Api\Models\Pr;
use Api\Models\Employee;
use Api\Models\locationChangeRequests;
use Api\Models\Admin;
use Api\Models\MaintenanceStatus;
use Api\Models\Maintenance;
use Api\Services\ActivityLoggerService;

class DeviceManagementController
{
    private ActivityLoggerService $logger;

    public function __construct(ActivityLoggerService $logger)
    {
        $this->logger = $logger;
    }

    // Add a new device
    public function addDevice(Request $request, Response $response)
    {
        $data = $request->getParsedBody();
        
        // Get admin_id from JWT token
        $decodedToken = $request->getAttribute('admin');
        $adminId = $decodedToken->admin_id;

        // Validate required fields
        $requiredFields = [
            'device_sn',
            'device_acquisition_date',
            'device_model',
            'brand_id',
            'status_id',
            'location_id',
            'pr_id'
        ];

        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }

        //check if the serial number is exist
        if (Device::where('device_sn', $data['device_sn'])->exists()) {
            $response->getBody()->write(json_encode(['error' => 'Device Serial Number already exists']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if brand exists
        if (!Brand::find($data['brand_id'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid brand_id']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if status exists
        if (!Status::find($data['status_id'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid status_id']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if location exists
        if (!Location::find($data['location_id'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid location_id']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if PR exists
        if (!Pr::find($data['pr_id'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid pr_id']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if employee exists
        // if (!Employee::find($data['emp_id'])) {
        //     $response->getBody()->write(json_encode(['error' => 'Invalid emp_id']));
        //     return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        // }

        // Start a transaction
        DB::beginTransaction();
        try {
            // Insert into devices table
            $device = Device::create([
                'device_sn' => $data['device_sn'],
                'device_acquisition_date' => $data['device_acquisition_date'],
                'device_model' => $data['device_model'],
                'device_notes' => $data['device_notes'],
                'brand_id' => $data['brand_id'],
                'status_id' => $data['status_id'],
                'location_id' => $data['location_id'],
                'pr_id' => $data['pr_id'],
                // 'emp_id' => $data['emp_id'],
                'emp_id' => !empty($data['emp_id']) ? $data['emp_id'] : null,
            ]);

            // Insert into specific table based on device type
            switch ($data['device_type']) {
                case 'laptop':
                    if (!isset($data['laptop_ram'], $data['laptop_storageType'], $data['laptop_storageSize'], $data['laptop_processor'])) {
                        throw new \Exception("Missing laptop specifications");
                    }
                    Laptop::create([
                        'device_id' => $device->device_id,
                        'laptop_ram' => $data['laptop_ram'],
                        'laptop_storageType' => $data['laptop_storageType'],
                        'laptop_storageSize' => $data['laptop_storageSize'],
                        'laptop_processor' => $data['laptop_processor'],
                        'laptop_gth' => $data['laptop_gth'] ?? null
                    ]);
                    break;

                case 'mobile':
                    Mobile::create(['device_id' => $device->device_id]);
                    break;

                case 'screen':
                    // Screen::create(['device_id' => $device->device_id]);
                    // break;

                    if (!isset($data['screen_resolution'], $data['screen_size'])) {
                        throw new \Exception("Missing screen specifications");
                    }
                    Screen::create([
                        'device_id' => $device->device_id,
                        'screen_resolution' => $data['screen_resolution'],
                        'screen_size' => $data['screen_size'],
                    ]);
                    break;

                case 'tablet':
                    Tablets::create(['device_id' => $device->device_id]);
                    break;

                case 'sim':
                    if (!isset($data['sim_number'], $data['sim_type'], $data['sim_carrier'])) {
                        throw new \Exception("Missing SIM specifications");
                    }
                    Sim::create([
                        'device_id' => $device->device_id,
                        'sim_number' => $data['sim_number'],
                        'sim_type' => $data['sim_type'],
                        'sim_carrier' => $data['sim_carrier'],
                    ]);
                    break;

                case 'other':
                    // No additional action needed
                    break;

                default:
                    throw new \Exception("Invalid device type");
            }

            DB::commit();
            
            // Log the activity
            $this->logger->log($adminId, 'add_single_device');
            
            $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Device added successfully', 'device' => $device]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }


    public function getDevicesUnderMaintenance(Request $request, Response $response): Response
    {
        $pendingMaintenanceStatuses = MaintenanceStatus::whereIn('name', ['Pending', 'Under maintenance'])->pluck('id');

        $maintenanceRecords = Maintenance::whereIn('status_id', $pendingMaintenanceStatuses)
            ->with([
                'device.brand',
                'device.location',
                'device.employee',
                'device.laptop',
                'device.mobile',
                'device.screen',
                'device.tablets',
                'device.sim',
                'status'
            ])
            ->get()
            ->map(function ($maintenance) {
                $device = $maintenance->device;

                // Determine device type
                $deviceType = null;
                $typeName = null;

                if ($device->laptop) {
                    $deviceType = $device->laptop;
                    $typeName = 'laptop';
                } elseif ($device->mobile) {
                    $deviceType = $device->mobile;
                    $typeName = 'mobile';
                } elseif ($device->screen) {
                    $deviceType = $device->screen;
                    $typeName = 'screen';
                } elseif ($device->tablets) {
                    $deviceType = $device->tablets;
                    $typeName = 'tablet';
                } elseif ($device->sim) {
                    $deviceType = $device->sim;
                    $typeName = 'sim';
                }

                $device->device_type = $typeName;
                $device->device_details = $deviceType;

                return $maintenance;
            });

        $statuses = MaintenanceStatus::all();

        $response->getBody()->write(json_encode([
            'maintenances' => $maintenanceRecords,
            'statuses' => $statuses
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }


    public function updateMaintenanceStatus(Request $request, Response $response, array $args): Response
    {
        $maintenanceId = $args['id'];
        $data = $request->getParsedBody();

        $maintenance = Maintenance::find($maintenanceId);
        if (!$maintenance) {
            $response->getBody()->write(json_encode(['error' => 'Maintenance record not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }


        $newStatus = MaintenanceStatus::find($data['newStatusId']);
        if (!$newStatus) {
            // echo "AAA".$data['newStatusId'];
            $response->getBody()->write(json_encode(['error' => 'Invalid maintenance status', 'data' => $data['newStatusId']]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');

        }

        DB::beginTransaction();
        try {
            // Update maintenance record
            $maintenance->status_id = $data['newStatusId'];
            if ($newStatus->name === 'Finished') {
                $maintenance->maintenance_dateOut = Carbon::now();
            }
            $maintenance->save();

            // If finished, set device status to "stock"
            if ($newStatus->name === 'Finished') {
                $stockStatusId = Status::where('status_name', 'stock')->value('status_id');
                $maintenance->device->update(['status_id' => $stockStatusId]);
            }

            DB::commit();
            
            // Get admin_id from JWT token
            $decodedToken = $request->getAttribute('admin');
            $adminId = $decodedToken->admin_id;
            
            // Log the maintenance status update
            $this->logger->log($adminId, 'update_maintenance_status');
            
            $response->getBody()->write(json_encode(['status' => 'success', "status name" => $newStatus->name]));
            return $response->withHeader('Content-Type', 'application/json');


        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');

        }
    }




    /**
     * Edit a device's attributes.
     */
    public function editDevice(Request $request, Response $response, array $args)
    {
        $data = $request->getParsedBody();
        $deviceId = $args['id'];

        // Find the device
        $device = Device::find($deviceId);
        if (!$device) {
            $response->getBody()->write(json_encode(['error' => 'Device not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Validate Serial Number
        if (isset($data['device_sn']) && Device::where('device_sn', $data['device_sn'])->where('device_id', '!=', $deviceId)->exists()) {
            $response->getBody()->write(json_encode(['error' => 'Device Serial Number already exists']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if location is being changed
        $isLocationChange = isset($data['location_id']) && $data['location_id'] != $device->location_id;

        // Start a transaction
        DB::beginTransaction();
        try {

            $decodedToken = $request->getAttribute('admin');
            $adminId = $decodedToken->admin_id;

            // Handle location change separately
            if ($isLocationChange) {

                $existingRequest = LocationChangeRequests::where('device_id', $deviceId)
                    ->where('status', 'pending')
                    ->first();

                if ($existingRequest) {
                    // Return error response if pending request exists
                    DB::rollBack();
                    $response->getBody()->write(json_encode([
                        'status' => 'error',
                        'error' => 'This device already has a pending location change request',
                        'existing_request_id' => $existingRequest->request_id
                    ]));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
                }

                // Create approval request instead of updating location directly
                $locationRequest = LocationChangeRequests::create([
                    'device_id' => $deviceId,
                    'current_location_id' => $device->location_id,
                    'requested_location_id' => $data['location_id'],
                    'requested_by_admin_id' => $adminId,
                    'status' => 'pending'
                ]);

                // Log the location change request
                $this->logger->log($adminId, 'request_location_change');

                $status = Status::find($data['status_id']);
                $shouldClearEmp = $status && strtolower($status->status_name) !== 'in_use';

                $device->update([
                    'device_sn' => $data['device_sn'] ?? $device->device_sn,
                    'device_acquisition_date' => $data['device_acquisition_date'] ?? $device->device_acquisition_date,
                    'device_model' => $data['device_model'] ?? $device->device_model,
                    'device_notes' => $data['device_notes'] ?? $device->device_notes,
                    'brand_id' => $data['brand_id'] ?? $device->brand_id,
                    // 'status_id' => $data['status_id'] ?? $device->status_id,
                    'pr_id' => $data['pr_id'] ?? $device->pr_id,
                    // 'emp_id' => $shouldClearEmp ? null : (isset($data['emp_id']) && $data['emp_id'] != 0 ? $data['emp_id'] : null),
                ]);


            }

            if (!$isLocationChange) {
                // Determine if emp_id should be kept or nullified
                $empId = null;
                if (isset($data['status_id'])) {
                    $status = Status::find($data['status_id']);
                    if ($status && strtolower($status->status_name) === 'in_use') {
                        if (empty($data['emp_id']) || !Employee::find($data['emp_id'])) {
                            throw new \Exception("Employee is required and must be valid for status 'in_use'");
                        }
                        $empId = $data['emp_id'];
                    }
                } else {
                    // If status_id is not being updated, fall back to current device status
                    $status = $device->status;
                    if ($status && strtolower($status->status_name) === 'in_use') {
                        if (empty($data['emp_id']) || !Employee::find($data['emp_id'])) {
                            throw new \Exception("Employee is required and must be valid for status 'in_use'");
                        }
                        $empId = $data['emp_id'];
                    }
                }
                // Update general device attributes
                $device->update([
                    'device_sn' => $data['device_sn'] ?? $device->device_sn,
                    'device_acquisition_date' => $data['device_acquisition_date'] ?? $device->device_acquisition_date,
                    'device_model' => $data['device_model'] ?? $device->device_model,
                    'device_notes' => $data['device_notes'] ?? $device->device_notes,
                    'brand_id' => $data['brand_id'] ?? $device->brand_id,
                    'status_id' => $data['status_id'] ?? $device->status_id,
                    'pr_id' => $data['pr_id'] ?? $device->pr_id,
                    'emp_id' => $empId,
                    // 'emp_id' => isset($data['emp_id']) && $data['emp_id'] != 0 ? $data['emp_id'] : null,
                ]);
            }




            // Update type-specific attributes
            switch ($data['device_type'] ?? null) {
                case 'laptop':
                    $laptop = Laptop::where('device_id', $deviceId)->first();
                    if ($laptop) {
                        $laptop->update([
                            'laptop_ram' => $data['laptop_ram'] ?? $laptop->laptop_ram,
                            'laptop_storageType' => $data['laptop_storageType'] ?? $laptop->laptop_storageType,
                            'laptop_storageSize' => $data['laptop_storageSize'] ?? $laptop->laptop_storageSize,
                            'laptop_processor' => $data['laptop_processor'] ?? $laptop->laptop_processor,
                            'laptop_gth' => $data['laptop_gth'] ?? $laptop->laptop_gth,
                        ]);
                        $this->logger->log($adminId, 'edit_laptop');
                    }
                    break;

                case 'mobile':
                    // No additional attributes to update for mobile
                    $this->logger->log($adminId, 'edit_mobile');
                    break;

                case 'screen':
                    $screen = Screen::where('device_id', $deviceId)->first();
                    if ($screen) {
                        $screen->update([
                            'screen_resolution' => $data['screen_resolution'] ?? $screen->screen_resolution,
                            'screen_size' => $data['screen_size'] ?? $screen->screen_size,
                        ]);
                        $this->logger->log($adminId, 'edit_screen');
                    }
                    break;

                case 'tablet':
                    // No additional attributes to update for tablet
                    $this->logger->log($adminId, 'edit_tablet');
                    break;

                case 'sim':
                    $sim = Sim::where('device_id', $deviceId)->first();
                    if ($sim) {
                        $sim->update([
                            'sim_number' => $data['sim_number'] ?? $sim->sim_number,
                            'sim_type' => $data['sim_type'] ?? $sim->sim_type,
                        ]);
                        $this->logger->log($adminId, 'edit_sim');
                    }
                    break;

                case 'other':
                    // No additional action needed
                    $this->logger->log($adminId, 'edit_other');
                    break;

                default:
                    throw new \Exception("Invalid device type");
            }

            // Check if status_id is set and corresponds to 'maintenance'
            if (isset($data['status_id'])) {
                $newStatus = Status::find($data['status_id']);

                if ($newStatus && strtolower($newStatus->status_name) === 'maintenance') {

                    // Avoid duplicate open maintenance records
                    $alreadyInMaintenance = Maintenance::where('device_id', $deviceId)
                        ->whereNull('maintenance_dateOut')
                        ->exists();

                    if (!$alreadyInMaintenance) {
                        $pendingMaintenanceStatusId = MaintenanceStatus::where('name', 'pending')->value('id');

                        Maintenance::create([
                            'maintenance_dateIn' => Carbon::now(),
                            'status_id' => $pendingMaintenanceStatusId,
                            'device_id' => $deviceId,
                            'submitted_by' => $adminId
                        ]);

                        // Set device status to "stock"
                        // $stockStatusId = Status::whereRaw('LOWER(status_name) = ?', ['stock'])->value('status_id');

                        // if ($stockStatusId) {
                        //     Device::where('device_id', $deviceId)->update(['status_id' => $stockStatusId]);
                        // }

                    }
                }
            }

            DB::commit();
            
            // Log the device edit
            $this->logger->log($adminId, 'update_device');

            $response->getBody()->write(json_encode([
                "status" => "success",
                'message' => $isLocationChange ?
                    'Device updated. Location change pending approval.' :
                    'Device updated successfully.',
                'requires_approval' => $isLocationChange,
                'device' => $device
            ]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }


    public function getDevicesByType(Request $request, Response $response, array $args)
    {
        try {
            $deviceType = $args['type'];

            switch ($deviceType) {
                case 'laptop':
                    $devices = Laptop::with([
                        'device.brand:brand_id,brand_name',
                        'device.status:status_id,status_name',
                        'device.location:location_id,location_name',
                        'device.pr:pr_id,pr_code',
                        'device.employee:emp_id,emp_name'
                    ])->get();
                    break;
                case 'mobile':

                    $devices = Mobile::with([
                        'device.brand:brand_id,brand_name',
                        'device.status:status_id,status_name',
                        'device.location:location_id,location_name',
                        'device.pr:pr_id,pr_code',
                        'device.employee:emp_id,emp_name'
                    ])->get();
                    break;

                case 'screen':
                    $devices = Screen::with([
                        'device.brand:brand_id,brand_name',
                        'device.status:status_id,status_name',
                        'device.location:location_id,location_name',
                        'device.pr:pr_id,pr_code',
                        'device.employee:emp_id,emp_name'
                    ])->get();
                    break;
                case 'tablet':
                    $devices = Tablets::with([
                        'device.brand:brand_id,brand_name',
                        'device.status:status_id,status_name',
                        'device.location:location_id,location_name',
                        'device.pr:pr_id,pr_code',
                        'device.employee:emp_id,emp_name'
                    ])->get();
                    break;
                case 'sim':
                    $devices = Sim::with([
                        'device.brand:brand_id,brand_name',
                        'device.status:status_id,status_name',
                        'device.location:location_id,location_name',
                        'device.pr:pr_id,pr_code',
                        'device.employee:emp_id,emp_name'
                    ])->get();
                    break;
                case 'other':
                    $devices = Device::doesntHave('laptop')
                        ->doesntHave('mobile')
                        ->doesntHave('screen')
                        ->doesntHave('tablets')
                        ->doesntHave('sim')
                        ->get();
                    break;
                default:
                    $response->getBody()->write(json_encode(['error' => 'Invalid device type']));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode($devices));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function getDeviceData(Request $request, Response $response, array $args)
    {
        $deviceType = $args['type'];

        $brands = Brand::all();
        $employees = Employee::where('emp_id', '!=', 0)->get();
        $locations = Location::all();
        // $prs = Pr::all();
        $prs = Pr::join('device_procurement', 'pr.pr_id', '=', 'device_procurement.pr_id')
            ->when($deviceType, function ($query, $deviceType) {
                return $query->where('device_procurement.device_type', $deviceType);
            })
            ->get();
        $statuses = Status::all();

        $data = [
            'brands' => $brands,
            'employees' => $employees,
            'locations' => $locations,
            'prs' => $prs,
            'statuses' => $statuses,
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }



    // Fetch all devices
    public function getAllDevices(Request $request, Response $response)
    {
        $devices = Device::all();
        $response->getBody()->write(json_encode($devices));
        return $response->withHeader('Content-Type', 'application/json');
    }



    public function getRequestsCount(Request $request, Response $response)
    {
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
        $locationEditCount = LocationChangeRequests::with([
            'device',
            'current_location',
            'requested_location',
            'requested_by.employee'
        ])
            ->where('requested_location_id', $myLocationId)
            ->where('status', 'pending')
            ->count();

        $prEditCount = DB::table('pr_edit_requests')->where('status', 'pending')->count();

        $response->getBody()->write(json_encode([
            'prEditCount' => $prEditCount,
            'locationEditCount' => $locationEditCount
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Fetch single device
    // public function getDevice(Request $request, Response $response, $args) {
    //     $device = Device::find($args['id']);
    //     if (!$device) {
    //         $response->getBody()->write(json_encode(['error' => 'Device not found']));
    //         return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    //     }
    //     $response->getBody()->write(json_encode($device));
    //     return $response->withHeader('Content-Type', 'application/json');
    // }


    public function getDevice(Request $request, Response $response, $args)
    {
        $deviceId = $args['id'];

        $pendingRequest = LocationChangeRequests::where('device_id', $deviceId)
            ->whereIn('status', ['pending'])
            ->first();

        // Fetch the device with related data
        $device = Device::with(['brand', 'status', 'location', 'pr', 'employee', 'locationChangeRequests'])
            ->find($deviceId);

        if (!$device) {
            $response->getBody()->write(json_encode(['error' => 'Device not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Fetch type-specific data based on the device type
        switch (true) {
            case $device->laptop()->exists():
                $device->load('laptop');
                break;

            case $device->mobile()->exists():
                $device->load('mobile');
                break;

            case $device->screen()->exists():
                $device->load('screen');
                break;

            case $device->tablets()->exists():
                $device->load('tablets');
                break;

            case $device->sim()->exists():
                $device->load('sim');
                break;

            default:
                // No type-specific data
                break;
        }

        // Check for pending location change request
        $pendingRequest = $device->locationChangeRequests()
            ->whereIn('status', ['pending']) 
            ->first();

        $device->has_pending_location_request = $pendingRequest ? true : false;
        $device->pending_location_request_id = $pendingRequest->request_id ?? null;

        $response->getBody()->write(json_encode($device));
        return $response->withHeader('Content-Type', 'application/json');
    }


    // Update device status
    public function updateDeviceStatus(Request $request, Response $response, $args)
    {
        $data = $request->getParsedBody();
        $device = Device::find($args['id']);

        if (!$device) {
            $response->getBody()->write(json_encode(['error' => 'Device not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $device->status_id = $data['status_id'];
        $device->save();

        // Get admin_id from JWT token
        $decodedToken = $request->getAttribute('admin');
        $adminId = $decodedToken->admin_id;
        
        // Log the status update
        $this->logger->log($adminId, 'update_device_status');

        $response->getBody()->write(json_encode(['message' => 'Device status updated']));
        return $response->withHeader('Content-Type', 'application/json');
    }



public function importLaptopsFromExcel(Request $request, Response $response): Response
{
    // Get admin_id from JWT token
    $decodedToken = $request->getAttribute('admin');
    $adminId = $decodedToken->admin_id;

    $uploadedFiles = $request->getUploadedFiles();
    $file = $uploadedFiles['file'] ?? null;

    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $response->getBody()->write(json_encode(['error' => 'Invalid file']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $spreadsheet = IOFactory::load($file->getStream()->getMetadata('uri'));
        $sheet = $spreadsheet->getSheetByName('laptop');
        if (!$sheet) {
            throw new \Exception('Sheet "laptop" not found in the Excel file. Please make sure you have a sheet named "laptop".');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];
        $importedCount = 0;
        $errors = [];
        $requiredColumns = ['A' => 'Brand', 'B' => 'Model', 'C' => 'Serial Number', 'D' => 'Location'];

        // Validate header row
        foreach ($requiredColumns as $col => $name) {
            if (empty($rows[1][$col])) {
                throw new \Exception("Required column '{$name}' not found in column {$col} of the Excel sheet.");
            }
        }

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 1) continue; // Skip header row

            try {
                $brandName   = trim($row['A'] ?? '');
                $model      = trim($row['B'] ?? '');
                $serial     = trim($row['C'] ?? '');
                $locationName = trim($row['D'] ?? '');
                $acqDate    = trim($row['E'] ?? '');
                $ram        = trim($row['F'] ?? '');
                $storageType    = trim($row['G'] ?? '');
                $storageSize    = trim($row['H'] ?? '');
                $cpu        = trim($row['I'] ?? '');
                $gth        = trim($row['J'] ?? '');
                $notes      = trim($row['K'] ?? '');

                // Validate required fields
                $validationErrors = [];
                if (empty($brandName)) $validationErrors[] = "Brand name is required";
                if (empty($model)) $validationErrors[] = "Model is required";
                if (empty($serial)) $validationErrors[] = "Serial number is required";
                if (empty($locationName)) $validationErrors[] = "Location is required";
                if (empty($acqDate)) $validationErrors[] = "Acquisition date is required";
                if (empty($ram)) $validationErrors[] = "RAM is required";
                if (empty($storageType)) $validationErrors[] = "Storage type is required";
                if (empty($storageSize)) $validationErrors[] = "Storage size is required";
                if (empty($cpu)) $validationErrors[] = "CPU/Processor is required";
                if (empty($gth)) $validationErrors[] = "GTH is required";

                if (!empty($validationErrors)) {
                    throw new \Exception("Row {$rowIndex}: " . implode(", ", $validationErrors));
                }

                // Validate date format if provided
                $date = \DateTime::createFromFormat('Y-m-d', $acqDate);
                if (!$date || $date->format('Y-m-d') !== $acqDate) {
                    throw new \Exception("Row {$rowIndex}: Invalid date format for Acquisition Date. Use YYYY-MM-DD format.");
                }

                // Check for duplicate serial number
                if (Device::where('device_sn', $serial)->exists()) {
                    $duplicates[] = "Row {$rowIndex}: Serial number '{$serial}' already exists";
                    continue;
                }

                // Find or create brand and location
                try {
                    $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find brand '{$brandName}': " . $e->getMessage());
                }

                try {
                    $location = Location::firstOrCreate(['location_name' => $locationName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find location '{$locationName}': " . $e->getMessage());
                }

                // Insert into device table
                $device = new Device();
                $device->device_model = $model;
                $device->device_sn = $serial;
                $device->device_acquisition_date = $acqDate;
                $device->brand_id = $brand->brand_id;
                $device->location_id = $location->location_id;
                $device->emp_id = null;
                $device->status_id = 1;
                $device->pr_id = 1;
                $device->device_notes = $notes;
                $device->save();

                // Insert into laptop table
                $laptop = new Laptop();
                $laptop->device_id = $device->device_id;
                $laptop->laptop_ram = $ram;
                $laptop->laptop_storageType = $storageType;
                $laptop->laptop_storageSize = $storageSize;
                $laptop->laptop_processor = $cpu;
                $laptop->laptop_gth = $gth;
                $laptop->save();

                $importedCount++;

            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
                // Continue with next row
                continue;
            }
        }

        // Log the bulk import activity
        error_log("About to log import_bulk_laptops action for admin ID: " . $adminId);
        $logResult = $this->logger->log($adminId, 'import_bulk_laptops');
        error_log("Logging result: " . ($logResult ? 'success' : 'failed'));

        $response->getBody()->write(json_encode([
            'status' => 'completed',
            'message' => 'Import process completed',
            'imported_count' => $importedCount,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'log_result' => $logResult
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'imported_count' => $importedCount ?? 0,
            'duplicates' => $duplicates ?? [],
            'errors' => $errors ?? []
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importMobilesFromExcel(Request $request, Response $response): Response
{
    // Get admin_id from JWT token
    $decodedToken = $request->getAttribute('admin');
    $adminId = $decodedToken->admin_id;

    $uploadedFiles = $request->getUploadedFiles();
    $file = $uploadedFiles['file'] ?? null;

    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $response->getBody()->write(json_encode(['error' => 'Invalid file']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $spreadsheet = IOFactory::load($file->getStream()->getMetadata('uri'));
        $sheet = $spreadsheet->getSheetByName('mobile');
        if (!$sheet) {
            throw new \Exception('Sheet "mobile" not found in the Excel file. Please make sure you have a sheet named "mobile".');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];
        $importedCount = 0;
        $errors = [];
        $requiredColumns = ['A' => 'Brand', 'B' => 'Model', 'C' => 'Serial Number', 'D' => 'Location'];

        // Validate header row
        foreach ($requiredColumns as $col => $name) {
            if (empty($rows[1][$col])) {
                throw new \Exception("Required column '{$name}' not found in column {$col} of the Excel sheet.");
            }
        }

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 1) continue; // Skip header row

            try {
                $brandName   = trim($row['A'] ?? '');
                $model      = trim($row['B'] ?? '');
                $serial     = trim($row['C'] ?? '');
                $locationName = trim($row['D'] ?? '');
                $acqDate    = trim($row['E'] ?? '');
                $notes      = trim($row['F'] ?? '');

                // Validate required fields
                $validationErrors = [];
                if (empty($brandName)) $validationErrors[] = "Brand name is required";
                if (empty($model)) $validationErrors[] = "Model is required";
                if (empty($serial)) $validationErrors[] = "Serial number is required";
                if (empty($locationName)) $validationErrors[] = "Location is required";
                if (empty($acqDate)) $validationErrors[] = "Acquisition date is required";

                if (!empty($validationErrors)) {
                    throw new \Exception("Row {$rowIndex}: " . implode(", ", $validationErrors));
                }

                // Validate date format if provided
                $date = \DateTime::createFromFormat('Y-m-d', $acqDate);
                if (!$date || $date->format('Y-m-d') !== $acqDate) {
                    throw new \Exception("Row {$rowIndex}: Invalid date format for Acquisition Date. Use YYYY-MM-DD format.");
                }

                // Check for duplicate serial number
                if (Device::where('device_sn', $serial)->exists()) {
                    $duplicates[] = "Row {$rowIndex}: Serial number '{$serial}' already exists";
                    continue;
                }

                // Find or create brand and location
                try {
                    $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find brand '{$brandName}': " . $e->getMessage());
                }

                try {
                    $location = Location::firstOrCreate(['location_name' => $locationName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find location '{$locationName}': " . $e->getMessage());
                }

                // Insert into device table
                $device = new Device();
                $device->device_model = $model;
                $device->device_sn = $serial;
                $device->device_acquisition_date = $acqDate;
                $device->brand_id = $brand->brand_id;
                $device->location_id = $location->location_id;
                $device->emp_id = null;
                $device->status_id = 1;
                $device->pr_id = 1;
                $device->device_notes = $notes;
                $device->save();

            // Insert into mobile table
            $mobile = new Mobile();
            $mobile->device_id = $device->device_id;
            $mobile->save();

                $importedCount++;

            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
                // Continue with next row
                continue;
            }
        }

        // Log the bulk import activity
        error_log("About to log import_bulk_mobiles action for admin ID: " . $adminId);
        $logResult = $this->logger->log($adminId, 'import_bulk_mobiles');
        error_log("Logging result: " . ($logResult ? 'success' : 'failed'));

        $response->getBody()->write(json_encode([
            'status' => 'completed',
            'message' => 'Import process completed',
            'imported_count' => $importedCount,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'log_result' => $logResult
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'imported_count' => $importedCount ?? 0,
            'duplicates' => $duplicates ?? [],
            'errors' => $errors ?? []
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importSimsFromExcel(Request $request, Response $response): Response
{
    // Get admin_id from JWT token
    $decodedToken = $request->getAttribute('admin');
    $adminId = $decodedToken->admin_id;

    $uploadedFiles = $request->getUploadedFiles();
    $file = $uploadedFiles['file'] ?? null;

    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $response->getBody()->write(json_encode(['error' => 'Invalid file']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $spreadsheet = IOFactory::load($file->getStream()->getMetadata('uri'));
        $sheet = $spreadsheet->getSheetByName('sim');
        if (!$sheet) {
            throw new \Exception('Sheet "sim" not found in the Excel file. Please make sure you have a sheet named "sim".');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];
        $importedCount = 0;
        $errors = [];
        $requiredColumns = [
            'A' => 'Brand',
            'B' => 'Model',
            'C' => 'Serial Number',
            'D' => 'Location',
            'E' => 'Acquisition Date',
            'F' => 'SIM Number',
            'G' => 'SIM Type',
            'H' => 'SIM Carrier'
        ];

        // Validate header row
        foreach ($requiredColumns as $col => $name) {
            if (empty($rows[1][$col])) {
                throw new \Exception("Required column '{$name}' not found in column {$col} of the Excel sheet.");
            }
        }

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 1) continue; // Skip header row

            try {
                $brandName = trim($row['A'] ?? '');
                $model = trim($row['B'] ?? '');
                $serial = trim($row['C'] ?? '');
                $locationName = trim($row['D'] ?? '');
                $acqDate = trim($row['E'] ?? '');
                $sim_number = trim($row['F'] ?? '');
                $sim_type = trim($row['G'] ?? '');
                $sim_carrier = trim($row['H'] ?? '');
                $notes = trim($row['I'] ?? '');

                // Validate required fields
                $validationErrors = [];
                if (empty($brandName)) $validationErrors[] = "Brand name is required";
                if (empty($model)) $validationErrors[] = "Model is required";
                if (empty($serial)) $validationErrors[] = "Serial number is required";
                if (empty($locationName)) $validationErrors[] = "Location is required";
                if (empty($acqDate)) $validationErrors[] = "Acquisition date is required";
                if (empty($sim_number)) $validationErrors[] = "SIM number is required";
                if (empty($sim_type)) $validationErrors[] = "SIM type is required";
                if (empty($sim_carrier)) $validationErrors[] = "SIM carrier is required";

                if (!empty($validationErrors)) {
                    throw new \Exception("Row {$rowIndex}: " . implode(", ", $validationErrors));
                }

                // Validate date format if provided
                $date = \DateTime::createFromFormat('Y-m-d', $acqDate);
                if (!$date || $date->format('Y-m-d') !== $acqDate) {
                    throw new \Exception("Row {$rowIndex}: Invalid date format for Acquisition Date. Use YYYY-MM-DD format.");
                }

                // Check for duplicate serial number
                if (Device::where('device_sn', $serial)->exists()) {
                    $duplicates[] = "Row {$rowIndex}: Device Serial number '{$serial}' already exists";
                    continue;
                }

                // Check for duplicate SIM number
                if (Sim::where('sim_number', $sim_number)->exists()) {
                    $duplicates[] = "Row {$rowIndex}: SIM number '{$sim_number}' already exists";
                    continue;
                }

                // Find or create brand and location
                try {
                    $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find brand '{$brandName}': " . $e->getMessage());
                }

                try {
                    $location = Location::firstOrCreate(['location_name' => $locationName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find location '{$locationName}': " . $e->getMessage());
                }

                // Insert into device table
                $device = new Device();
                $device->device_model = $model;
                $device->device_sn = $serial;
                $device->device_acquisition_date = $acqDate;
                $device->brand_id = $brand->brand_id;
                $device->location_id = $location->location_id;
                $device->emp_id = null;
                $device->status_id = 1;
                $device->pr_id = 1;
                $device->device_notes = $notes;
                $device->save();

                // Insert into Sim table
                $sim = new Sim();
                $sim->device_id = $device->device_id;
                $sim->sim_number = $sim_number;
                $sim->sim_type = $sim_type;
                $sim->sim_carrier = $sim_carrier;
                $sim->save();

                $importedCount++;

            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
                continue;
            }
        }

        // Log the bulk import activity
        error_log("About to log import_bulk_sims action for admin ID: " . $adminId);
        $logResult = $this->logger->log($adminId, 'import_bulk_sims');
        error_log("Logging result: " . ($logResult ? 'success' : 'failed'));

        $response->getBody()->write(json_encode([
            'status' => 'completed',
            'message' => 'Import process completed',
            'imported_count' => $importedCount,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'log_result' => $logResult
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'imported_count' => $importedCount ?? 0,
            'duplicates' => $duplicates ?? [],
            'errors' => $errors ?? []
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importScreensFromExcel(Request $request, Response $response): Response
{
    // Get admin_id from JWT token
    $decodedToken = $request->getAttribute('admin');
    $adminId = $decodedToken->admin_id;

    $uploadedFiles = $request->getUploadedFiles();
    $file = $uploadedFiles['file'] ?? null;

    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $response->getBody()->write(json_encode(['error' => 'Invalid file']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $spreadsheet = IOFactory::load($file->getStream()->getMetadata('uri'));
        $sheet = $spreadsheet->getSheetByName('screen');
        if (!$sheet) {
            throw new \Exception('Sheet "screen" not found in the Excel file. Please make sure you have a sheet named "screen".');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];
        $importedCount = 0;
        $errors = [];
        $requiredColumns = [
            'A' => 'Brand',
            'B' => 'Model',
            'C' => 'Serial Number',
            'D' => 'Location',
            'E' => 'Acquisition Date',
            'F' => 'Screen Resolution',
            'G' => 'Screen Size'
        ];

        // Validate header row
        foreach ($requiredColumns as $col => $name) {
            if (empty($rows[1][$col])) {
                throw new \Exception("Required column '{$name}' not found in column {$col} of the Excel sheet.");
            }
        }

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 1) continue; // Skip header row

            try {
                $brandName = trim($row['A'] ?? '');
                $model = trim($row['B'] ?? '');
                $serial = trim($row['C'] ?? '');
                $locationName = trim($row['D'] ?? '');
                $acqDate = trim($row['E'] ?? '');
                $screen_resolution = trim($row['F'] ?? '');
                $screen_size = trim($row['G'] ?? '');
                $notes = trim($row['H'] ?? '');

                // Validate required fields
                $validationErrors = [];
                if (empty($brandName)) $validationErrors[] = "Brand name is required";
                if (empty($model)) $validationErrors[] = "Model is required";
                if (empty($serial)) $validationErrors[] = "Serial number is required";
                if (empty($locationName)) $validationErrors[] = "Location is required";
                if (empty($acqDate)) $validationErrors[] = "Acquisition date is required";
                if (empty($screen_resolution)) $validationErrors[] = "Screen resolution is required";
                if (empty($screen_size)) $validationErrors[] = "Screen size is required";

                if (!empty($validationErrors)) {
                    throw new \Exception("Row {$rowIndex}: " . implode(", ", $validationErrors));
                }

                // Validate date format if provided
                $date = \DateTime::createFromFormat('Y-m-d', $acqDate);
                if (!$date || $date->format('Y-m-d') !== $acqDate) {
                    throw new \Exception("Row {$rowIndex}: Invalid date format for Acquisition Date. Use YYYY-MM-DD format.");
                }

                // Check for duplicate serial number
                if (Device::where('device_sn', $serial)->exists()) {
                    $duplicates[] = "Row {$rowIndex}: Serial number '{$serial}' already exists";
                    continue;
                }

                // Find or create brand and location
                try {
                    $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find brand '{$brandName}': " . $e->getMessage());
                }

                try {
                    $location = Location::firstOrCreate(['location_name' => $locationName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find location '{$locationName}': " . $e->getMessage());
                }

                // Insert into device table
                $device = new Device();
                $device->device_model = $model;
                $device->device_sn = $serial;
                $device->device_acquisition_date = $acqDate;
                $device->brand_id = $brand->brand_id;
                $device->location_id = $location->location_id;
                $device->emp_id = null;
                $device->status_id = 1;
                $device->pr_id = 1;
                $device->device_notes = $notes;
                $device->save();

            // Insert into Screen table
            $screen = new Screen();
            $screen->device_id = $device->device_id;
            $screen->screen_resolution = $screen_resolution;
            $screen->screen_size = $screen_size;
            $screen->save();

                $importedCount++;

            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
                continue;
            }
        }

        // Log the bulk import activity
        error_log("About to log import_bulk_screens action for admin ID: " . $adminId);
        $logResult = $this->logger->log($adminId, 'import_bulk_screens');
        error_log("Logging result: " . ($logResult ? 'success' : 'failed'));

        $response->getBody()->write(json_encode([
            'status' => 'completed',
            'message' => 'Import process completed',
            'imported_count' => $importedCount,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'log_result' => $logResult
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'imported_count' => $importedCount ?? 0,
            'duplicates' => $duplicates ?? [],
            'errors' => $errors ?? []
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importTabletsFromExcel(Request $request, Response $response): Response
{
    // Get admin_id from JWT token
    $decodedToken = $request->getAttribute('admin');
    $adminId = $decodedToken->admin_id;

    $uploadedFiles = $request->getUploadedFiles();
    $file = $uploadedFiles['file'] ?? null;

    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $response->getBody()->write(json_encode(['error' => 'Invalid file']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    try {
        $spreadsheet = IOFactory::load($file->getStream()->getMetadata('uri'));
        $sheet = $spreadsheet->getSheetByName('tablet');
        if (!$sheet) {
            throw new \Exception('Sheet "tablet" not found in the Excel file. Please make sure you have a sheet named "tablet".');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];
        $importedCount = 0;
        $errors = [];
        $requiredColumns = [
            'A' => 'Brand',
            'B' => 'Model',
            'C' => 'Serial Number',
            'D' => 'Location',
            'E' => 'Acquisition Date'
        ];

        // Validate header row
        foreach ($requiredColumns as $col => $name) {
            if (empty($rows[1][$col])) {
                throw new \Exception("Required column '{$name}' not found in column {$col} of the Excel sheet.");
            }
        }

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 1) continue; // Skip header row

            try {
                $brandName = trim($row['A'] ?? '');
                $model = trim($row['B'] ?? '');
                $serial = trim($row['C'] ?? '');
                $locationName = trim($row['D'] ?? '');
                $acqDate = trim($row['E'] ?? '');
                $notes = trim($row['F'] ?? '');

                // Validate required fields
                $validationErrors = [];
                if (empty($brandName)) $validationErrors[] = "Brand name is required";
                if (empty($model)) $validationErrors[] = "Model is required";
                if (empty($serial)) $validationErrors[] = "Serial number is required";
                if (empty($locationName)) $validationErrors[] = "Location is required";
                if (empty($acqDate)) $validationErrors[] = "Acquisition date is required";

                if (!empty($validationErrors)) {
                    throw new \Exception("Row {$rowIndex}: " . implode(", ", $validationErrors));
                }

                // Validate date format if provided
                $date = \DateTime::createFromFormat('Y-m-d', $acqDate);
                if (!$date || $date->format('Y-m-d') !== $acqDate) {
                    throw new \Exception("Row {$rowIndex}: Invalid date format for Acquisition Date. Use YYYY-MM-DD format.");
                }

                // Check for duplicate serial number
                if (Device::where('device_sn', $serial)->exists()) {
                    $duplicates[] = "Row {$rowIndex}: Serial number '{$serial}' already exists";
                    continue;
                }

                // Find or create brand and location
                try {
                    $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find brand '{$brandName}': " . $e->getMessage());
                }

                try {
                    $location = Location::firstOrCreate(['location_name' => $locationName]);
                } catch (\Exception $e) {
                    throw new \Exception("Row {$rowIndex}: Failed to create/find location '{$locationName}': " . $e->getMessage());
                }

                // Insert into device table
                $device = new Device();
                $device->device_model = $model;
                $device->device_sn = $serial;
                $device->device_acquisition_date = $acqDate;
                $device->brand_id = $brand->brand_id;
                $device->location_id = $location->location_id;
                $device->emp_id = null;
                $device->status_id = 1;
                $device->pr_id = 1;
                $device->device_notes = $notes;
                $device->save();

            // Insert into tablet table
            $tablet = new Tablets();
            $tablet->device_id = $device->device_id;
            $tablet->save();

                $importedCount++;

            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
                continue;
            }
        }

        // Log the bulk import activity
        error_log("About to log import_bulk_tablets action for admin ID: " . $adminId);
        $logResult = $this->logger->log($adminId, 'import_bulk_tablets');
        error_log("Logging result: " . ($logResult ? 'success' : 'failed'));

        $response->getBody()->write(json_encode([
            'status' => 'completed',
            'message' => 'Import process completed',
            'imported_count' => $importedCount,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'log_result' => $logResult
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'imported_count' => $importedCount ?? 0,
            'duplicates' => $duplicates ?? [],
            'errors' => $errors ?? []
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function getDeviceCounts(Request $request, Response $response): Response
{
    try {
        // Get total counts by device type
        $totalCounts = DB::table('device')
            ->select(DB::raw('
                SUM(CASE WHEN device_id IN (SELECT device_id FROM laptop) THEN 1 ELSE 0 END) as laptop_count,
                SUM(CASE WHEN device_id IN (SELECT device_id FROM mobile) THEN 1 ELSE 0 END) as mobile_count,
                SUM(CASE WHEN device_id IN (SELECT device_id FROM screen) THEN 1 ELSE 0 END) as screen_count,
                SUM(CASE WHEN device_id IN (SELECT device_id FROM tablets) THEN 1 ELSE 0 END) as tablet_count,
                SUM(CASE WHEN device_id IN (SELECT device_id FROM sim) THEN 1 ELSE 0 END) as sim_count
            '))
            ->first();

        // Get counts by location and device type
        $locationCounts = DB::table('location')
            ->select([
                'location.location_id',
                'location.location_name',
                DB::raw('COUNT(DISTINCT CASE WHEN device_id IN (SELECT device_id FROM laptop) THEN device.device_id END) as laptop_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN device_id IN (SELECT device_id FROM mobile) THEN device.device_id END) as mobile_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN device_id IN (SELECT device_id FROM screen) THEN device.device_id END) as screen_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN device_id IN (SELECT device_id FROM tablets) THEN device.device_id END) as tablet_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN device_id IN (SELECT device_id FROM sim) THEN device.device_id END) as sim_count'),
                DB::raw('COUNT(DISTINCT device.device_id) as total_devices')
            ])
            ->leftJoin('device', 'location.location_id', '=', 'device.location_id')
            ->groupBy('location.location_id', 'location.location_name')
            ->get()
            ->map(function ($location) {
                return [
                    'location_name' => $location->location_name,
                    'device_counts' => [
                        'laptop' => (int)$location->laptop_count,
                        'mobile' => (int)$location->mobile_count,
                        'screen' => (int)$location->screen_count,
                        'tablet' => (int)$location->tablet_count,
                        'sim' => (int)$location->sim_count
                    ],
                    'total_devices' => (int)$location->total_devices
                ];
            });

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => [
                'total_counts' => [
                    'laptop' => (int)$totalCounts->laptop_count,
                    'mobile' => (int)$totalCounts->mobile_count,
                    'screen' => (int)$totalCounts->screen_count,
                    'tablet' => (int)$totalCounts->tablet_count,
                    'sim' => (int)$totalCounts->sim_count,
                    'total' => (int)($totalCounts->laptop_count + $totalCounts->mobile_count + 
                            $totalCounts->screen_count + $totalCounts->tablet_count + 
                            $totalCounts->sim_count)
                ],
                'locations' => $locationCounts
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

}

