<?php

// Debug autoloader
spl_autoload_register(function ($class) {
    error_log("Trying to load class: " . $class);
});

use Slim\Factory\AppFactory;
use Api\Controllers\DeviceManagementController;
use Api\Controllers\EmployeeController;
use Api\Controllers\DeviceProcurementController;
use Api\Controllers\AuthController;
use Api\Controllers\LocationChangeController;
use Api\Controllers\NotificationController;
use Api\Controllers\ActivityLogController;
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

// Set up dependencies
$container = new \DI\Container();
AppFactory::setContainer($container);

// Load dependencies
$dependencies = require __DIR__ . '/../api/config/dependencies.php';
$dependencies($container);

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
$app->post('/api/login', function (Request $request, Response $response) use ($container) {
    return $container->get(AuthController::class)->login($request, $response);
});

$app->post('/api/logout', function (Request $request, Response $response) use ($container) {
    return $container->get(AuthController::class)->logout($request, $response);
});

$app->post('/api/create-admin', function (Request $request, Response $response) use ($container) {
    return $container->get(AuthController::class)->createAdmin($request, $response);
})->add($jwtMiddleware);

$app->get('/api/devices', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->getAllDevices($request, $response);
})->add($jwtMiddleware);

$app->get('/api/device/{id}', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceManagementController::class)->getDevice($request, $response, $args);
})->add($jwtMiddleware);

$app->put('/api/devices/{id}/status', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceManagementController::class)->updateDeviceStatus($request, $response, $args);
})->add($jwtMiddleware);

$app->post('/api/addDevice', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->addDevice($request, $response);
})->add($jwtMiddleware);

$app->post('/api/addEmployee', function (Request $request, Response $response) use ($container) {
    return $container->get(EmployeeController::class)->addEmployee($request, $response);
})->add($jwtMiddleware);

$app->get('/api/employees', function (Request $request, Response $response) use ($container) {
    return $container->get(EmployeeController::class)->getAllEmployees($request, $response);
})->add($jwtMiddleware);

$app->post('/api/procurement', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceProcurementController::class)->addProcurement($request, $response);
})->add($jwtMiddleware);

$app->post('/api/procurement/{pr_id}/upload', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceProcurementController::class)->uploadPrDocument($request, $response, $args);
})->add($jwtMiddleware);

$app->get('/api/procurement', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceProcurementController::class)->getAllProcurements($request, $response);
})->add($jwtMiddleware);

$app->get('/api/devices/type/{type}', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceManagementController::class)->getDevicesByType($request, $response, $args);
})->add($jwtMiddleware);

$app->put('/api/device/{id}', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceManagementController::class)->editDevice($request, $response, $args);
})->add($jwtMiddleware);

$app->put('/api/update-maintenance/{id}', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceManagementController::class)->updateMaintenanceStatus($request, $response, $args);
})->add($jwtMiddleware);

$app->get('/api/get-maintenance-devices', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->getDevicesUnderMaintenance($request, $response);
})->add($jwtMiddleware);

$app->get('/api/device-data/{type}', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceManagementController::class)->getDeviceData($request, $response, $args);
})->add($jwtMiddleware);

$app->get('/api/my-pending', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(LocationChangeController::class)->getMyPendingRequests($request, $response);
})->add($jwtMiddleware);

// import device via excel
$app->post('/api/import-laptop-devices', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->importLaptopsFromExcel($request, $response);
})->add($jwtMiddleware);

$app->post('/api/import-mobile-devices', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->importMobilesFromExcel($request, $response);
})->add($jwtMiddleware);

$app->post('/api/import-sim-devices', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->importSimsFromExcel($request, $response);
})->add($jwtMiddleware);

$app->post('/api/import-screen-devices', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->importScreensFromExcel($request, $response);
})->add($jwtMiddleware);

$app->post('/api/import-tablet-devices', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->importTabletsFromExcel($request, $response);
})->add($jwtMiddleware);


$app->get('/api/admin/info', function (Request $request, Response $response) use ($container) {
    return $container->get(AuthController::class)->adminInfo($request, $response);
})->add($jwtMiddleware);

$app->get('/api/admin/notifications', function (Request $request, Response $response) use ($container) {
    return $container->get(NotificationController::class)->getAdminNotifications($request, $response);
})->add($jwtMiddleware);

// Approve/Reject Location Change
$app->post('/api/location-change-requests/{request_id}/approve', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(LocationChangeController::class)->approveLocationChange($request, $response, $args);
})->add($jwtMiddleware);

$app->post('/api/location-change-requests/{request_id}/reject', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(LocationChangeController::class)->rejectLocationChange($request, $response, $args);
})->add($jwtMiddleware);

$app->post('/api/notifications/{notification_id}/read', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(NotificationController::class)->markAsRead($request, $response, $args);
})->add($jwtMiddleware);

$app->post('/api/notifications/mark-all-read', function (Request $request, Response $response) use ($container) {
    return $container->get(NotificationController::class)->markAllAsRead($request, $response);
})->add($jwtMiddleware);

$app->get('/api/procurements/{pr_id}/details', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceProcurementController::class)->getPrDetails($request, $response, $args);
})->add($jwtMiddleware);

$app->post('/api/procurements/edit-request', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceProcurementController::class)->submitEditRequest($request, $response);
})->add($jwtMiddleware);

$app->get('/api/procurements/edit-requests/pending', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceProcurementController::class)->getPendingEditRequests($request, $response);
})->add($jwtMiddleware);

$app->get('/api/procurements/edit-requests/{requestId}/device-changes', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceProcurementController::class)->getDeviceChanges($request, $response, $args);
})->add($jwtMiddleware);

$app->post('/api/procurements/edit-requests/{requestId}/process', function (Request $request, Response $response, array $args) use ($container) {
    return $container->get(DeviceProcurementController::class)->processEditRequest($request, $response, $args);
})->add($jwtMiddleware);

$app->get('/api/requests-count', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->getRequestsCount($request, $response);
})->add($jwtMiddleware);

$app->post('/api/sync-employees', function (Request $request, Response $response) use ($container) {
    return $container->get(EmployeeController::class)->syncEmployees($request, $response);
});

$app->get('/api/device-counts', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->getDeviceCounts($request, $response);
})->add($jwtMiddleware);

$app->get('/api/statuses-counts', function (Request $request, Response $response) use ($container) {
    return $container->get(DeviceManagementController::class)->getDeviceStatusCountsByLocation($request, $response);
})->add($jwtMiddleware);

$app->get('/api/activity-logs', function (Request $request, Response $response) use ($container) {
    return $container->get(ActivityLogController::class)->getLatest($request, $response);
})->add($jwtMiddleware);

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
