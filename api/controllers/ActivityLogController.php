<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\ActivityLog;
use Api\Models\DeviceManagment;
use Api\Models\Action;
use Api\Models\Admin;

class ActivityLogController
{
    public function getLatest(Request $request, Response $response): Response
    {
        try {
            $logs = ActivityLog::with(['action', 'admin.employee'])
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();

            $data = $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'admin' => [
                        'id' => $log->admin_id,
                        'username' => $log->admin->admin_username ?? 'Unknown',
                        'employee_name' => $log->admin->employee->emp_name ?? 'Unknown'
                    ],
                    'action' => [
                        'id' => $log->action_id,
                        'name' => $log->action->action_name ?? 'Unknown',
                        'description' => $log->action->description ?? null
                    ],
                    'created_at' => $log->created_at,
                    'updated_at' => $log->updated_at
                ];
            });

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $data
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

    public function getLifecycle(Request $request, Response $response, array $args): Response
{
    $deviceId = (int)$args['deviceId'];

    try {
        $entries = DeviceManagment::with([
                'oldStatus', 'newStatus',
                'oldLocation', 'newLocation',
                'oldEmployee', 'newEmployee',
                'admin'
            ])
            ->where('device_id', $deviceId)
            ->orderByDesc('dm_date')
            ->get()
            ->map(function ($entry) {
                return [
                    'dm_date' => $entry->dm_date,
                    'old_status_id' => $entry->old_status_id,
                    'new_status_id' => $entry->new_status_id,
                    'old_status_name' => $entry->oldStatus->status_name ?? null,
                    'new_status_name' => $entry->newStatus->status_name ?? null,
                    'old_location_id' => $entry->old_location_id,
                    'new_location_id' => $entry->new_location_id,
                    'old_location_name' => $entry->oldLocation->location_name ?? null,
                    'new_location_name' => $entry->newLocation->location_name ?? null,
                    'old_emp_id' => $entry->old_emp_id,
                    'new_emp_id' => $entry->new_emp_id,
                    'old_emp_name' => optional($entry->oldEmployee)->emp_name,
                    'new_emp_name' => optional($entry->newEmployee)->emp_name,
                    'admin_id' => $entry->admin_id,
                    'admin_name' => optional($entry->admin)->admin_username,
                    'notes' => $entry->notes
                ];
            });

        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $entries
        ]));

        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $response->getBody()->write(json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch lifecycle data',
            'details' => $e->getMessage()
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}


}
