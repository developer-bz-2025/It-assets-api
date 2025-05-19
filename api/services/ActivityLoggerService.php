<?php

namespace Api\services;

use Api\Models\Action;
use Api\Models\ActivityLog;
use Api\Models\DeviceManagment;

class ActivityLoggerService
{
    /**
     * Logs an admin activity based on action name.
     *
     * @param int $adminId
     * @param string $actionKey
     * @return bool
     */
    public function log(int $adminId, string $actionKey): bool
    {
        try {
            error_log("Attempting to log action: " . $actionKey . " for admin: " . $adminId);
            
            // Debug: Print all actions first
            $allActions = Action::all();
            error_log("Total actions in database: " . $allActions->count());
            foreach ($allActions as $act) {
                error_log("Action in DB: {$act->action_id} - {$act->action_name}");
            }
            
            // Check if action exists with exact name match
            $action = Action::where('action_name', $actionKey)->first();
            error_log("Looking for exact match of action name: " . $actionKey);
            
            if (!$action) {
                error_log("Action not found: " . $actionKey);
                // Try case-insensitive search to debug potential case issues
                $actionCaseInsensitive = Action::whereRaw('LOWER(action_name) = ?', [strtolower($actionKey)])->first();
                if ($actionCaseInsensitive) {
                    error_log("Found action with different case: " . $actionCaseInsensitive->action_name);
                }
                return false;
            }
            
            error_log("Found action with ID: " . $action->action_id);

            try {
                $log = ActivityLog::create([
                    'admin_id'  => $adminId,
                    'action_id' => $action->action_id
                ]);
                error_log("Activity log created with ID: " . ($log->id ?? 'unknown'));
            } catch (\Exception $e) {
                error_log("Failed to create activity log: " . $e->getMessage());
                error_log("admin_id: " . $adminId);
                error_log("action_id: " . $action->action_id);
                throw $e;
            }

            return true;
        } catch (\Exception $e) {
            error_log("Activity logging failed: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }

    public function logLifecycle(array $data): bool
{
    try {
        $record = DeviceManagment::create([
            'device_id' => $data['device_id'],
            'dm_data' => date('Y-m-d H:i:s'),
            'old_status_id' => $data['old_status_id'] ?? null,
            'new_status_id' => $data['new_status_id'] ?? null,
            'old_location_id' => $data['old_location_id'] ?? null,
            'new_location_id' => $data['new_location_id'] ?? null,
            'old_emp_id' => $data['old_emp_id'] ?? null,
            'new_emp_id' => $data['new_emp_id'] ?? null,
            'pr_id' => $data['pr_id'] ?? null,
            'admin_id' => $data['admin_id'],
            'notes' => $data['notes'] ?? null
        ]);
        return $record !== null; // or: return (bool) $record;

    } catch (\Exception $e) {
        error_log("Failed to log device lifecycle: " . $e->getMessage());
        return false;
    }
}

}
