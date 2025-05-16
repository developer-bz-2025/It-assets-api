<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\Employee;
use Api\Controllers\Exception;
use Illuminate\Database\Capsule\Manager as DB;
use Api\Models\Location;
use Api\Models\Position;
use Api\Services\ActivityLoggerService;


class EmployeeController
{

    private ActivityLoggerService $logger;

    public function __construct(ActivityLoggerService $logger)
    {
        $this->logger = $logger;
    }
    // Add new employee
    public function addEmployee(Request $request, Response $response)
    {
        $data = $request->getParsedBody();

        // Validate required fields
        if (
            !isset($data['emp_name']) || !isset($data['emp_email']) ||
            !isset($data['title_id']) || !isset($data['department_id']) ||
            !isset($data['emp_project']) || !isset($data['emp_locationId'])
        ) {

            $response->getBody()->write(json_encode(['error' => 'Missing required fields']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if email already exists
        $existingEmployee = Employee::where('emp_email', $data['emp_email'])->first();
        if ($existingEmployee) {
            $response->getBody()->write(json_encode(['error' => 'Email already exists']));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        // Create employee record
        $employee = Employee::create([
            'emp_name' => $data['emp_name'],
            'emp_email' => $data['emp_email'],
            'title_id' => $data['title_id'],
            'department_id' => $data['department_id'],
            'emp_project' => $data['emp_project'],
            'emp_locationId' => $data['emp_locationId']
        ]);

        $response->getBody()->write(json_encode([
            'message' => 'Employee added successfully',
            'employee' => $employee
        ]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    // Get all employees
    public function getAllEmployees(Request $request, Response $response)
    {
        $employees = Employee::all();

        $response->getBody()->write(json_encode($employees));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    public function syncEmployees(Request $request, Response $response)
    {

        try {

            // === Step 1: Fetch from external API ===


            $appid = '3906765749';
            $secretKey = 'sl4g480ulbuiows9akkhyfg0l247qmtv9m7dq7lk1ymrq';
            $userId = 'USER06';
            $apiUrl = 'http://basmeh-zeitooneh.com/BZ/api/rest_api.php';

            $timestamp = time();
            $strToSign = "timestamp=$timestamp,appid=$appid";
            $hash = hash_hmac('sha256', $strToSign, $secretKey, true);
            $ATMITSignature = base64_encode($hash);

            // Get Token
            $postData = [
                'timestamp' => $timestamp,
                'appid' => $appid,
                'ATMITSignature' => $ATMITSignature,
                'userId' => $userId,
                'operation_type' => 'get_token'
            ];

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            $tokenResponse = curl_exec($ch);
            curl_close($ch);

            $tokenResponse = preg_replace('/^\xEF\xBB\xBF/', '', $tokenResponse);
            $tokenData = json_decode($tokenResponse, true);

            if (!isset($tokenData['returned_data'])) {
                throw new \Exception('Failed to retrieve token from external API');
            }

            $tokenuser = $tokenData['returned_data'];

            // Get Employees
            $postData = [
                'timestamp' => $timestamp,
                'appid' => $appid,
                'ATMITSignature' => $ATMITSignature,
                'userId' => $userId,
                'tokenuser' => $tokenuser,
                'operation_type' => 'get_employee_data'
            ];

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            $employeeResponse = curl_exec($ch);
            curl_close($ch);

            $employeeResponse = preg_replace('/^\xEF\xBB\xBF/', '', $employeeResponse);
            $employeeData = json_decode($employeeResponse, true);

            if (!isset($employeeData['returned_data'])) {
                throw new \Exception('Failed to retrieve employee data from external API');
            }

            $employees = $employeeData['returned_data'];

            // === Step 2: Sync with Database ===

            foreach ($employees as $emp) {
                // Prepare data
                $emp_no = $emp['ID'] ?? null;
                $emp_name = $emp['VAL'] ?? null;
                $emp_email = $emp['E_MAIL'] ?? null;
                $emp_location = $emp['BRANCH'] ?? null;
                $position_name = trim($emp['EMPLOYEE_POSITION'] ?? '');

                $title_id = null;
                if (!empty($position_name)) {
                    $title = Position::firstOrCreate(['position_name' => $position_name]);
                    $title_id = $title->position_id;
                }

                $location_id = null;
                if (!empty($emp_location)) {
                    $location = Location::firstOrCreate(['location_name' => $emp_location]);
                    $location_id = $location->location_id;
                }

                // Ensure emp_name is unique
                $existingEmployee = Employee::where('emp_name', $emp_name)->first();

                if ($existingEmployee) {
                    // Employee with the same emp_name already exists, update the existing record
                    $existingEmployee->update([
                        'emp_no' => $emp_no,
                        'emp_email' => $emp_email,
                        'title_id' => $title_id,
                        'emp_locationId' => $location_id,
                    ]);
                } else {
                    // Employee doesn't exist, create a new record
                    Employee::create([
                        'emp_no' => $emp_no,
                        'emp_name' => $emp_name,
                        'emp_email' => $emp_email,
                        'title_id' => $title_id,
                        'emp_locationId' => $location_id,
                    ]);
                }
            }

            $response->getBody()->write(json_encode(['message' => 'Employees synced successfully', 'synced_count' => count($employees)]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }

}
