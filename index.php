<?php

use Slim\Factory\AppFactory;
use Api\Controllers\DeviceManagementController;
use Api\Controllers\EmployeeController;
use Api\Controllers\DeviceProcurementController;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;


require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/api/config/database.php';

$app = AppFactory::create();

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

$app->get('/devices', [DeviceManagementController::class, 'getAllDevices']);
$app->get('/device/{id}', [DeviceManagementController::class, 'getDevice']);
$app->put('/devices/{id}/status', [DeviceManagementController::class, 'updateDeviceStatus']);
$app->post('/addDevice', [DeviceManagementController::class, 'addDevice']);  // Add a new device
$app->post('/addEmployee', [EmployeeController::class, 'addEmployee']);

$app->get('/employees', EmployeeController::class . ':getAllEmployees');

$app->post('/procurement', [DeviceProcurementController::class, 'addProcurement']);
$app->post('/procurement/{pr_id}/upload', [DeviceProcurementController::class, 'uploadPrDocument']);
$app->get('/procurement', [DeviceProcurementController::class, 'getAllProcurements']);

$app->get('/devices/type/{type}', [DeviceManagementController::class, 'getDevicesByType']);
$app->put('/device/{id}', [DeviceManagementController::class, 'editDevice']);
$app->get('/device-data', [DeviceManagementController::class, 'getDeviceData']);



$app->addBodyParsingMiddleware();

$app->run();
