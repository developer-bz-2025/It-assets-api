<?php

use Slim\Factory\AppFactory;
use Api\Controllers\DeviceManagementController;
use Api\Controllers\EmployeeController;
use Api\Controllers\DeviceProcurementController;
use Api\Controllers\AuthController;
use Api\Controllers\LocationChangeController;
use Api\Controllers\NotificationController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;


error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

chdir(dirname(__DIR__));

// Load the Composer autoloader
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../api/config/database.php';




// Load environment variables
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
if (!file_exists(__DIR__ . '/../.env')) {
    die(" .env file is missing in " . realpath(__DIR__ . '/../'));
}

$dotenv->load();

// Create Slim App
$app = AppFactory::create();

// Secret key for JWT
$secretKey = $_ENV['JWT_SECRET_KEY'];

// CORS Middleware
$app->add(function (Request $request, RequestHandlerInterface $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*') // Allow all origins
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS') // Allowed methods
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization'); // Allowed headers
});

// Handle OPTIONS request (Preflight request)
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

// JWT Middleware
$jwtMiddleware = function (Request $request, RequestHandlerInterface $handler) use ($secretKey): Response {
    $token = $request->getHeaderLine('Authorization');

    if (empty($token)) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => "Token not provided"]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    try {
        // Extract the token from the "Bearer" prefix
        $token = str_replace('Bearer ', '', $token);

        // Decode the token
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));

        // Attach the decoded admin data to the request
        $request = $request->withAttribute('admin', $decoded);
    } catch (\Exception $e) {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode(['error' => "Invalid token", 'message' => $e->getMessage()]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    // ✅ Only call handler if the token is valid
    return $handler->handle($request);
};



// Authentication routes
$app->post('/api/login', [AuthController::class, 'login']);
$app->post('/api/logout', [AuthController::class, 'logout']);
$app->post('/api/create-admin', [AuthController::class, 'createAdmin'])->add($jwtMiddleware);

$app->get('/api/devices', [DeviceManagementController::class, 'getAllDevices'])->add($jwtMiddleware);
$app->get('/api/device/{id}', [DeviceManagementController::class, 'getDevice'])->add($jwtMiddleware);
$app->put('/api/devices/{id}/status', [DeviceManagementController::class, 'updateDeviceStatus'])->add($jwtMiddleware);
$app->post('/api/addDevice', [DeviceManagementController::class, 'addDevice'])->add($jwtMiddleware);  // Add a new device
$app->post('/api/addEmployee', [EmployeeController::class, 'addEmployee'])->add($jwtMiddleware);

$app->get('/api/employees', EmployeeController::class . ':getAllEmployees')->add($jwtMiddleware);

$app->post('/api/procurement', [DeviceProcurementController::class, 'addProcurement'])->add($jwtMiddleware);
$app->post('/api/procurement/{pr_id}/upload', [DeviceProcurementController::class, 'uploadPrDocument'])->add($jwtMiddleware);
$app->get('/api/procurement', [DeviceProcurementController::class, 'getAllProcurements'])->add($jwtMiddleware);

$app->get('/api/devices/type/{type}', [DeviceManagementController::class, 'getDevicesByType'])->add($jwtMiddleware);
$app->put('/api/device/{id}', [DeviceManagementController::class, 'editDevice'])->add($jwtMiddleware);
$app->get('/api/device-data/{type}', [DeviceManagementController::class, 'getDeviceData'])->add($jwtMiddleware);
$app->get('/api/my-pending', [LocationChangeController::class, 'getMyPendingRequests'])->add($jwtMiddleware);

$app->get('/api/admin/info', [AuthController::class, 'adminInfo'])
    ->add($jwtMiddleware);

$app->get('/api/admin/notifications', [NotificationController::class, 'getAdminNotifications'])
    ->add($jwtMiddleware);

// Approve/Reject Location Change
$app->post('/api/location-change-requests/{request_id}/approve', [LocationChangeController::class, 'approveLocationChange'])
    ->add($jwtMiddleware);

$app->post('/api/location-change-requests/{request_id}/reject', [LocationChangeController::class, 'rejectLocationChange'])
    ->add($jwtMiddleware);

$app->post('/api/notifications/{notification_id}/read', [NotificationController::class, 'markAsRead'])
    ->add($jwtMiddleware);

$app->post('/api/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])
    ->add($jwtMiddleware);

$app->get('/api/procurements/{pr_id}/details', [DeviceProcurementController::class, 'getPrDetails'])
    ->add($jwtMiddleware);

$app->post('/api/procurements/edit-request', [DeviceProcurementController::class, 'submitEditRequest'])
    ->add($jwtMiddleware);


$app->get('/api/procurements/edit-requests/pending', [DeviceProcurementController::class, 'getPendingEditRequests'])
    ->add($jwtMiddleware);

$app->get('/api/procurements/edit-requests/{requestId}/device-changes', [DeviceProcurementController::class, 'getDeviceChanges'])
    ->add($jwtMiddleware);

$app->post('/api/procurements/edit-requests/{requestId}/process', [DeviceProcurementController::class, 'processEditRequest'])
    ->add($jwtMiddleware);


$app->get('/', function ($request, $response, $args) {
    // Path to the static folder and the index.html file
    $filePath = '/home/nf2yur09vpps/it-assets.bzassets.org/public/index.html';

    // Debugging: Log the file path to ensure it is correct
    error_log("Looking for file at: " . $filePath);

    if (file_exists($filePath)) {
        $response->getBody()->write(file_get_contents($filePath));
        return $response->withHeader('Content-Type', 'text/html');
    }

    // If index.html is not found, log and return error
    echo "Index file not found at: " . $filePath;

    // Return an error if index.html is not found
    $response->getBody()->write("Index file not found.");
    return $response->withStatus(404)->withHeader('Content-Type', 'text/html');
});

$app->get('/api/controllersuploads/pr_docs/{filename}', function (Request $request, Response $response, array $args) {
    // Use the full absolute path to your PDF directory
    $basePath = '/home/nf2yur09vpps/it-assets.bzassets.org/api/it-assets-system/api/controllersuploads/pr_docs/';
    $filePath = $basePath . $args['filename'];

    // Debug output - check if this shows the correct path
    error_log("Looking for file at: " . $filePath);

    if (!file_exists($filePath)) {
        error_log("File not found: " . $filePath);
        $response->getBody()->write('File not found at: ' . $filePath);
        return $response->withStatus(404);
    }

    $fileContents = file_get_contents($filePath);
    $response->getBody()->write($fileContents);
    return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', 'inline; filename="' . $args['filename'] . '"');
});

$app->addBodyParsingMiddleware();

$app->run();
