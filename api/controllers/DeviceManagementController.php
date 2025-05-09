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


class DeviceManagementController
{

    // Add a new device
    public function addDevice(Request $request, Response $response)
    {
        $data = $request->getParsedBody();

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
            // return $response->withJson(['message' => 'Device added successfully', 'device' => $device], 201);
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
                    }
                    break;

                case 'mobile':
                    // No additional attributes to update for mobile
                    break;

                case 'screen':
                    $screen = Screen::where('device_id', $deviceId)->first();
                    if ($screen) {
                        $screen->update([
                            'screen_resolution' => $data['screen_resolution'] ?? $screen->screen_resolution,
                            'screen_size' => $data['screen_size'] ?? $screen->screen_size,
                        ]);
                    }
                    break;

                case 'tablet':
                    // No additional attributes to update for tablet
                    break;

                case 'sim':
                    $sim = Sim::where('device_id', $deviceId)->first();
                    if ($sim) {
                        $sim->update([
                            'sim_number' => $data['sim_number'] ?? $sim->sim_number,
                            'sim_type' => $data['sim_type'] ?? $sim->sim_type,
                        ]);
                    }
                    break;

                case 'other':
                    // No additional action needed
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

        $response->getBody()->write(json_encode(['message' => 'Device status updated']));
        return $response->withHeader('Content-Type', 'application/json');
    }



public function importLaptopsFromExcel(Request $request, Response $response): Response
{
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
            throw new \Exception('Sheet "laptop" not found');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];

        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // Skip header row

            $brandName   = trim($row['A']);
            $model       = trim($row['B']);
            $serial      = trim($row['C']);
            $locationName = trim($row['D']);
            $acqDate     = trim($row['E']);
            $ram         = trim($row['F']);
            $storageType     = trim($row['G']);
            $storageSize     = trim($row['H']);
            $cpu         = trim($row['I']);
            $gth         = trim($row['J']);
            $notes         = trim($row['K']);

            if (empty($serial)) continue; // Skip if serial number is empty

            // Check for duplicate serial number
            if (Device::where('device_sn', $serial)->exists()) {
                $duplicates[] = $serial;
                continue;
            }

            // Find or create brand and location
            $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
            $location = Location::firstOrCreate(['location_name' => $locationName]);

            // Insert into device table
            $device = new Device();
            $device->device_model = $model;
            $device->device_sn = $serial;
            $device->device_acquisition_date = $acqDate;
            $device->brand_id = $brand->brand_id;
            $device->location_id = $location->location_id;
            $device->emp_id = null;       // default
            $device->status_id = 1;    // default
            $device->pr_id = 1;        // default
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
        }

        $response->getBody()->write(json_encode([
            'message' => 'Laptops imported successfully',
            'duplicates_skipped' => $duplicates
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'error' => 'Import failed: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importMobilesFromExcel(Request $request, Response $response): Response
{
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
            throw new \Exception('Sheet "mobile" not found');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];

        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // Skip header row

            $brandName   = trim($row['A']);
            $model       = trim($row['B']);
            $serial      = trim($row['C']);
            $locationName = trim($row['D']);
            $acqDate     = trim($row['E']);
            $notes         = trim($row['F']);

            if (empty($serial)) continue; // Skip if serial number is empty

            // Check for duplicate serial number
            if (Device::where('device_sn', $serial)->exists()) {
                $duplicates[] = $serial;
                continue;
            }

            // Find or create brand and location
            $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
            $location = Location::firstOrCreate(['location_name' => $locationName]);

            // Insert into device table
            $device = new Device();
            $device->device_model = $model;
            $device->device_sn = $serial;
            $device->device_acquisition_date = $acqDate;
            $device->brand_id = $brand->brand_id;
            $device->location_id = $location->location_id;
            $device->emp_id = null;       // default
            $device->status_id = 1;    // default
            $device->pr_id = 1;        // default
            $device->device_notes = $notes;       
            $device->save();

            // Insert into laptop table
            $mobile = new Mobile();
            $mobile->device_id = $device->device_id;
 
            $mobile->save();
        }

        $response->getBody()->write(json_encode([
            'message' => 'Mobiles imported successfully',
            'duplicates_skipped' => $duplicates
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'error' => 'Import failed: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importSimsFromExcel(Request $request, Response $response): Response
{
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
            throw new \Exception('Sheet "sim" not found');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicateSNs = [];
        $duplicateSIMs = [];

        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // Skip header row

            $brandName   = trim($row['A']);
            $model       = trim($row['B']);
            $serial      = trim($row['C']);
            $locationName = trim($row['D']);
            $acqDate     = trim($row['E']);
            $sim_number     = trim($row['F']);
            $sim_type     = trim($row['G']);
            $sim_carrier     = trim($row['H']);
            $notes         = trim($row['I']);

            if (empty($serial) || empty($sim_number)) continue; // Skip if serial number is empty

            // Check for duplicate serial number
            if (Device::where('device_sn', $serial)->exists()) {
                $duplicateSNs[] = $serial;
                continue;
            }
             // Check for duplicate SIM number
             if (Sim::where('sim_number', $sim_number)->exists()) {
                $duplicateSIMs[] = $sim_number;
                continue;
            }
            

            // Find or create brand and location
            $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
            $location = Location::firstOrCreate(['location_name' => $locationName]);

            // Insert into device table
            $device = new Device();
            $device->device_model = $model;
            $device->device_sn = $serial;
            $device->device_acquisition_date = $acqDate;
            $device->brand_id = $brand->brand_id;
            $device->location_id = $location->location_id;
            $device->emp_id = null;       // default
            $device->status_id = 1;    // default
            $device->pr_id = 1;        // default
            $device->device_notes = $notes;       
            $device->save();

            // Insert into Sim table
            $sim = new Sim();
            $sim->device_id = $device->device_id;
            $sim->sim_number = $sim_number;
            $sim->sim_type = $sim_type;
            $sim->sim_carrier = $sim_carrier;
            $sim->save();
        }

        $response->getBody()->write(json_encode([
            'message' => 'Sims imported successfully',
            'duplicates_skipped' => [
                'device_sns' => $duplicateSNs,
                'sim_numbers' => $duplicateSIMs
            ]
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'error' => 'Import failed: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importScreensFromExcel(Request $request, Response $response): Response
{
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
            throw new \Exception('Sheet "screen" not found');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicateSNs = [];

        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // Skip header row

            $brandName   = trim($row['A']);
            $model       = trim($row['B']);
            $serial      = trim($row['C']);
            $locationName = trim($row['D']);
            $acqDate     = trim($row['E']);
            $screen_resolution     = trim($row['F']);
            $screen_size     = trim($row['G']);
            $notes         = trim($row['H']);

            if (empty($serial)) continue; // Skip if serial number is empty

            // Check for duplicate serial number
            if (Device::where('device_sn', $serial)->exists()) {
                $duplicateSNs[] = $serial;
                continue;
            }
          
            

            // Find or create brand and location
            $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
            $location = Location::firstOrCreate(['location_name' => $locationName]);

            // Insert into device table
            $device = new Device();
            $device->device_model = $model;
            $device->device_sn = $serial;
            $device->device_acquisition_date = $acqDate;
            $device->brand_id = $brand->brand_id;
            $device->location_id = $location->location_id;
            $device->emp_id = null;       // default
            $device->status_id = 1;    // default
            $device->pr_id = 1;        // default
            $device->device_notes = $notes;       
            $device->save();

            // Insert into Sim table
            $screen = new Screen();
            $screen->device_id = $device->device_id;
            $screen->screen_resolution = $screen_resolution;
            $screen->screen_size = $screen_size;
            $screen->save();
        }

        $response->getBody()->write(json_encode([
            'message' => 'Screens imported successfully',
            'duplicates_skipped' => $duplicateSNs
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'error' => 'Import failed: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}

public function importTabletsFromExcel(Request $request, Response $response): Response
{
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
            throw new \Exception('Sheet "tablet" not found');
        }

        $rows = $sheet->toArray(null, true, true, true); // A, B, C... columns
        $duplicates = [];

        foreach ($rows as $i => $row) {
            if ($i === 1) continue; // Skip header row

            $brandName   = trim($row['A']);
            $model       = trim($row['B']);
            $serial      = trim($row['C']);
            $locationName = trim($row['D']);
            $acqDate     = trim($row['E']);
            $notes         = trim($row['F']);

            if (empty($serial)) continue; // Skip if serial number is empty

            // Check for duplicate serial number
            if (Device::where('device_sn', $serial)->exists()) {
                $duplicates[] = $serial;
                continue;
            }

            // Find or create brand and location
            $brand = Brand::firstOrCreate(['brand_name' => $brandName]);
            $location = Location::firstOrCreate(['location_name' => $locationName]);

            // Insert into device table
            $device = new Device();
            $device->device_model = $model;
            $device->device_sn = $serial;
            $device->device_acquisition_date = $acqDate;
            $device->brand_id = $brand->brand_id;
            $device->location_id = $location->location_id;
            $device->emp_id = null;       // default
            $device->status_id = 1;    // default
            $device->pr_id = 1;        // default
            $device->device_notes = $notes;       
            $device->save();

            // Insert into laptop table
            $tablet = new Tablets();
            $tablet->device_id = $device->device_id;
 
            $tablet->save();
        }

        $response->getBody()->write(json_encode([
            'message' => 'Tablets imported successfully',
            'duplicates_skipped' => $duplicates
        ]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'error' => 'Import failed: ' . $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}


}
