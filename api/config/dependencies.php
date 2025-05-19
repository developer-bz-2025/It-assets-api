<?php

use DI\Container;
use Api\services\ActivityLoggerService;
use Api\Controllers\LocationChangeController;
use Api\Controllers\DeviceManagementController;
use Api\Controllers\AuthController;
use Api\Controllers\DeviceProcurementController;
use Api\Controllers\EmployeeController;
use Api\Controllers\NotificationController;
use Api\Controllers\ActivityLogController;

return function (Container $container) {
    // Register ActivityLoggerService
    $container->set(ActivityLoggerService::class, function (Container $container) {
        return new ActivityLoggerService();
    });

    // Register LocationChangeController with its dependencies
    $container->set(LocationChangeController::class, function (Container $container) {
        return new LocationChangeController(
            $container->get(ActivityLoggerService::class),
            $container->get(ActivityLoggerService::class)

        );
    });

    // Register DeviceManagementController with its dependencies
    $container->set(DeviceManagementController::class, function (Container $container) {
        return new DeviceManagementController(
            $container->get(ActivityLoggerService::class),
            $container->get(ActivityLoggerService::class)
        );
    });

    // Register AuthController with its dependencies
    $container->set(AuthController::class, function (Container $container) {
        return new AuthController(
            $container->get(ActivityLoggerService::class)
        );
    });

    // Register DeviceProcurementController with its dependencies
    $container->set(DeviceProcurementController::class, function (Container $container) {
        return new DeviceProcurementController(
            $container->get(ActivityLoggerService::class)
        );
    });

    // Register EmployeeController with its dependencies
    $container->set(EmployeeController::class, function (Container $container) {
        return new EmployeeController(
            $container->get(ActivityLoggerService::class)
        );
    });

    // Register NotificationController with its dependencies
    $container->set(NotificationController::class, function (Container $container) {
        return new NotificationController(
            $container->get(ActivityLoggerService::class)
        );
    });

    // Register ActivityLogController with its dependencies
    $container->set(ActivityLogController::class, function (Container $container) {
        return new ActivityLogController();
    });
};
