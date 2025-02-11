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

        if (empty($data['sn']) || empty($data['acquisition_date']) || empty($data['pr_code'])) {
                $response->getBody()->write(json_encode(['error' => "Missing required field:"]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        DB::beginTransaction();
        try {
            // Insert into PR table
            $pr = Pr::create([
                'pr_code' => $data['pr_code'],
                'pr_date' => $data['acquisition_date']
            ]);

            // Insert into Device Procurement table
            $procurement = DeviceProcurement::create([
                'sn' => $data['sn'],
                'acquisition_date' => $data['acquisition_date'],
                'pr_id' => $pr->pr_id
            ]);

            DB::commit();
                $response->getBody()->write(json_encode(['message' => 'Procurement record added', 'procurement' => $procurement]));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            DB::rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

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

        $response->getBody()->write(json_encode($procurements));
        return $response->withHeader('Content-Type', 'application/json');

    }
}
