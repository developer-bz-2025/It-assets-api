<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Api\Models\Admin;
use Api\Models\Location;
use Api\Models\SuperAdmin;
use Api\Models\ProcurementAdmin;
use Api\Services\ActivityLoggerService;

class AuthController
{
    private $secretKey;
    private ActivityLoggerService $logger;

    public function __construct(ActivityLoggerService $logger)
    {
        // Load the secret key from the environment
        $this->secretKey = $_ENV['JWT_SECRET_KEY'];
        $this->logger = $logger;
    }

    // Login method
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        // Validate input
        if (empty($username) || empty($password)) {
            $response->getBody()->write(json_encode(['error' => "user name and password are required"]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Find admin by username
        $admin = Admin::where('admin_username', $username)->first();

        // Verify username
        if (!$admin) {
            // Log failed login attempt - invalid username
            $this->logger->log(0, 'login_failed');

            $response->getBody()->write(json_encode(['error' => "user name is not correct!"]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // Verify password
        if (!password_verify($password, $admin->admin_password)) {
            // Log failed login attempt - invalid password
            $this->logger->log($admin->admin_id, 'login_failed');

            $response->getBody()->write(json_encode(['error' => "Invalid password"]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // Determine role by checking which table contains the emp_id
        $adminType = 'admin'; // Default role
        if (SuperAdmin::where('emp_id', $admin->emp_id)->exists()) {
            $adminType = 'super_admin';
        } elseif (ProcurementAdmin::where('emp_id', $admin->emp_id)->exists()) {
            $adminType = 'procurement_admin';
        }

        // Check if the admin is also a super admin
        $isSuperAdmin = SuperAdmin::where('emp_id', $admin->emp_id)->exists();

        // Generate JWT token
        $payload = [
            'admin_id' => $admin->admin_id,
            'id' => $admin->id,
            'username' => $admin->username,
            // 'email' => $admin->email,
            'role' => $adminType, // 'admin', 'super_admin', or 'procurement_admin'
            'is_super_admin' => ($adminType === 'super_admin'),
            'is_procurement_admin' => ($adminType === 'procurement_admin'),
            'emp_id' => $admin->emp_id,
            'exp' => time() + 86400, // Token expires in 24 hour
        ];
        $token = JWT::encode($payload, $this->secretKey, 'HS256');

        // Log successful login
        $this->logger->log($admin->admin_id, 'login_success');

        // Return token
        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'login success', 'token' => $token]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Create new admin method
    public function createAdmin(Request $request, Response $response): Response
    {
        // Get the decoded token (JWT)
        $decodedToken = $request->getAttribute('admin');

        // Check if the user is a Super Admin
        if (!$decodedToken->is_super_admin) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized: Only Super Admins can create Admins']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $emp_id = $data['emp_id'] ?? null;

        // Validate input
        if (empty($username) || empty($password) || empty($emp_id)) {
            $response->getBody()->write(json_encode(['error' => 'All fields are required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if admin with the same username already exists
        if (Admin::where('admin_username', $username)->exists()) {
            $response->getBody()->write(json_encode(['error' => 'Admin with this username already exists']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // ✅ Check if admin with the same emp_id already exists
        if (Admin::where('emp_id', $emp_id)->exists()) {
            $response->getBody()->write(json_encode(['error' => 'An admin is already assigned to this employee ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // Create new admin
        $admin = Admin::create([
            'admin_username' => $username,
            'admin_password' => $hashedPassword,
            'emp_id' => $emp_id,
        ]);

        // Log the admin creation
        $this->logger->log($decodedToken->admin_id, 'create_admin');

        $response->getBody()->write(json_encode(['message' => 'Admin created successfully', 'admin' => $admin]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    public function adminInfo(Request $request, Response $response): Response
    {
        try {
            // Get admin_id from JWT token
            $decodedToken = $request->getAttribute('admin');
            $adminId = $decodedToken->admin_id;

            $admin = Admin::findOrFail($adminId);


            // Fetch all locations assigned to this admin
            $locations = Location::where('admin_id', $adminId)->get(['location_id', 'location_name']);


            // Format response data
            // $responseData = [
            //     'admin_id' => $admin->admin_id,
            //     'username' => $admin->admin_username,
            //     'employee' => [
            //         'emp_id' => $admin->employee->emp_id,
            //         'name' => $admin->employee->emp_name,
            //         'email' => $admin->employee->emp_email,
            //         'location' => [
            //             'location_id' => $admin->employee->location->location_id,
            //             'name' => $admin->employee->location->location_name
            //         ]
            //     ]
            // ];

            // $response->getBody()->write(json_encode(['status' => 'success', 'data' => $responseData]));
            // return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            // Format response
            $responseData = [
                'admin_id' => $admin->admin_id,
                'username' => $admin->admin_username,
                'locations' => $locations
            ];

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $responseData
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Failed to fetch admin info', 'error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}