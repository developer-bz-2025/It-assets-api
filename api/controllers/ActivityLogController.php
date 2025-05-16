<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\ActivityLog;
use Api\Models\Action;
use Api\Models\Admin;

class ActivityLogController
{
    public function getLatest(Request $request, Response $response): Response
    {
        try {
            $logs = ActivityLog::with(['action', 'admin.employee'])
                ->orderBy('created_at', 'desc')
                ->limit(30)
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
}
