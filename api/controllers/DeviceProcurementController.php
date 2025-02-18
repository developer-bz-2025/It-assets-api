<?php

namespace Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Api\Models\DeviceProcurement;
use Api\Models\Pr;
use Illuminate\Database\Capsule\Manager as DB;

class DeviceProcurementController {

    // Add a new procurement record
    public function addProcurement(Request $request, Response $response) {
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        // echo $data['sns'];
         // Debug the received data
    // var_dump($data['sns']);  // Use var_dump to check the data
    // die(); // Stop execution to check the output
    
        // Validate required fields
        $requiredFields = ['sns', 'acquisition_date', 'pr_code'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $response->getBody()->write(json_encode(['error' => "Missing required field: $field"]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
    
        // Validate serial numbers as an array
        if (!is_array($data['sns'])) {
            $response->getBody()->write(json_encode(['error' => 'Serial numbers must be an array with at least one entry']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

         // Count serial numbers for items_count
         $itemsCount = count($data['sns']);
    
        // Check if any serial number already exists
        $existingSNs = DeviceProcurement::whereIn('sn', $data['sns'])->pluck('sn')->toArray();
        if (!empty($existingSNs)) {
            $response->getBody()->write(json_encode([
                'error' => 'Some Serial Numbers already exist',
                'existing_sn' => $existingSNs
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if PR code already exists
        if (PR::where('pr_code', $data['pr_code'])->exists()) {
            $response->getBody()->write(json_encode(['error' => 'PR code already exists']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    
        // Handle file upload (PDF)
        $pdfPath = null;
        if (isset($uploadedFiles['pr_document'])) {
            $pdf = $uploadedFiles['pr_document'];
            if ($pdf->getError() === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . "uploads/pr_docs/"; // Adjust the directory path
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
    
                $fileExtension = pathinfo($pdf->getClientFilename(), PATHINFO_EXTENSION);
                $fileName = "{$data['pr_code']}_{$data['acquisition_date']}." . $fileExtension;
                $pdfPath = $uploadDir . $fileName;
                $pdf->moveTo($pdfPath);
            } else {
                $response->getBody()->write(json_encode(['error' => 'Error uploading PDF file']));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
        }
    
        DB::beginTransaction();
        try {
            // Insert into PR table
            $pr = Pr::create([
                'pr_code' => $data['pr_code'],
                'pr_date' => $data['acquisition_date'],
                'items_count' => $itemsCount,
                'pr_path' => $pdfPath ? basename($pdfPath) : null // Store only the filename
            ]);
    
            // Insert multiple serial numbers into Device Procurement table
            $procurements = [];
            foreach ($data['sns'] as $sn) {
                $procurements[] = [
                    'sn' => $sn,
                    'acquisition_date' => $data['acquisition_date'],
                    'pr_id' => $pr->pr_id,
                ];
            }
            DeviceProcurement::insert($procurements); // Bulk insert for efficiency
    
            DB::commit();
            $response->getBody()->write(json_encode([
                "status" => "success",
                'message' => 'Procurement record added',
                'pr' => $pr,
                'procurements' => $procurements
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    
        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    // public function getAllProcurements(Request $request, Response $response) {
    //     // Fetch all procurement records along with their related serial numbers and document paths
    //     $procurements = DB::table('pr')
    //         ->leftJoin('device_procurement', 'pr.pr_id', '=', 'device_procurement.pr_id')
    //         ->select('pr.*', 'device_procurement.sn')
    //         ->get();
    
    //     // Return data as JSON
    //     return $response->withJson($procurements);
    // }
    
    
    

    // Upload PR document
    public function uploadPrDocument(Request $request, Response $response, $args) {
        $pr_id = $args['pr_id'];
        $uploadedFiles = $request->getUploadedFiles();

        if (empty($uploadedFiles['pr_document'])) {

            $response->getBody()->write(json_encode(['error' => 'No file uploaded']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $file = $uploadedFiles['pr_document'];
        $directory = __DIR__ . '/../../uploads/';
        $filename = $pr_id . '-' . time() . '-' . $file->getClientFilename();
        $file->moveTo($directory . $filename);

        // Update PR record with file path
        $pr = Pr::find($pr_id);
        if ($pr) {
            $pr->pr_path = 'uploads/' . $filename;
            $pr->save();
        }

        $response->getBody()->write(json_encode(['message' => 'File uploaded successfully', 'path' => $pr->pr_path]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // Get all procurement records
    public function getAllProcurements(Request $request, Response $response) {
        $procurements = DeviceProcurement::with('pr')->get();

        // $procurements = DB::table('pr')
        // ->leftJoin('device_procurement', 'pr.pr_id', '=', 'device_procurement.pr_id')
        // ->select('pr.*', 'device_procurement.sn')
        // ->get();

        $response->getBody()->write(json_encode($procurements));
        return $response->withHeader('Content-Type', 'application/json');

    }
}
