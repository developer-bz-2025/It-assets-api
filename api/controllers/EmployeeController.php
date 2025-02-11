<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\Employee;

class EmployeeController {
    
    // Add new employee
    public function addEmployee(Request $request, Response $response) {
        $data = $request->getParsedBody();
       
        // Validate required fields
        if (!isset($data['emp_name']) || !isset($data['emp_email']) || 
            !isset($data['title_id']) || !isset($data['department_id']) || 
            !isset($data['emp_project']) || !isset($data['emp_locationId'])) {

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
     public function getAllEmployees(Request $request, Response $response) {
        $employees = Employee::all();
        
        $response->getBody()->write(json_encode($employees));
        
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
    
}
