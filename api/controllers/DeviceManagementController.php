<?php

namespace Api\Controllers;

use Illuminate\Database\Capsule\Manager as DB;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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


class DeviceManagementController {

    // Add a new device
    public function addDevice(Request $request, Response $response) {
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
        if (Device::where('device_sn', $data['device_sn'])->exists()){
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
        if (!Employee::find($data['emp_id'])) {
            $response->getBody()->write(json_encode(['error' => 'Invalid emp_id']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

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
                'emp_id' => $data['emp_id'],
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
            $response->getBody()->write(json_encode(['status'=>'success','message' => 'Device added successfully', 'device' => $device]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');

            
        }

    }


    /**
 * Edit a device's attributes.
 */
public function editDevice(Request $request, Response $response, array $args) {
    $data = $request->getParsedBody();
    $deviceId = $args['id'];

    // Find the device
    $device = Device::find($deviceId);
    if (!$device) {
        $response->getBody()->write(json_encode(['error' => 'Device not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // Validate required fields (if any)
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

            // Notify target location's admin (via email or in-app)
            // $this->notifyAdminForApproval($locationRequest); // TO DO
        }

        
        // Update general device attributes
        $device->update([
            'device_sn' => $data['device_sn'] ?? $device->device_sn,
            'device_acquisition_date' => $data['device_acquisition_date'] ?? $device->device_acquisition_date,
            'device_model' => $data['device_model'] ?? $device->device_model,
            'device_notes' => $data['device_notes'] ?? $device->device_notes,
            'brand_id' => $data['brand_id'] ?? $device->brand_id,
            'status_id' => $data['status_id'] ?? $device->status_id,
            //'location_id' => $data['location_id'] ?? $device->location_id,
            'pr_id' => $data['pr_id'] ?? $device->pr_id,
            'emp_id' => $data['emp_id'] ?? $device->emp_id,
        ]);

       

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

        DB::commit();
        $response->getBody()->write(json_encode(["status"=> "success", 'message' => $isLocationChange ? 
        'Device updated. Location change pending approval.' : 
        'Device updated successfully.',
        'requires_approval' => $isLocationChange,
        'device' => $device]));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        DB::rollBack();
        $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }
}


    public function getDevicesByType(Request $request, Response $response, array $args) {
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

    public function getDeviceData(Request $request, Response $response, array $args) {
        $deviceType = $args['type'];

        $brands = Brand::all();
        $employees = Employee::where('emp_id','!=',0)->get();
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
    public function getAllDevices(Request $request, Response $response) {
        $devices = Device::all();
        $response->getBody()->write(json_encode($devices));
        return $response->withHeader('Content-Type', 'application/json');
    }

    

    public function getRequestsCount(Request $request, Response $response) {
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


    public function getDevice(Request $request, Response $response, $args) {
        $deviceId = $args['id'];
    
        // Fetch the device with related data
        $device = Device::with(['brand', 'status', 'location', 'pr', 'employee'])
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
    
        $response->getBody()->write(json_encode($device));
        return $response->withHeader('Content-Type', 'application/json');
    }
    

    // Update device status
    public function updateDeviceStatus(Request $request, Response $response, $args) {
        $data = $request->getParsedBody();
        $device = Device::find($args['id']);

        if (!$device) {
            $response->getBody()->write(json_encode(['error' => 'Device not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');        }

        $device->status_id = $data['status_id'];
        $device->save();

        $response->getBody()->write(json_encode(['message' => 'Device status updated']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
