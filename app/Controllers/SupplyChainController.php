<?php

namespace App\Controllers;

use App\Models\HealthcareFacilityModel;
use App\Models\MedicineBatchModel;
use App\Models\MedicineModel;
use App\Models\StockMovementModel;
use App\Services\FefoStockAllocator;
use App\Services\RoleAccess;
use RuntimeException;

class SupplyChainController extends BaseController
{
    /**
     * Helper to return responses consistently for both API (Postman) and Web (Browser)
     */
    private function respond(int $status, string $message, array $data = [], bool $isError = false)
    {
        if ($this->request->is('json') || strpos($this->request->getHeaderLine('Content-Type'), 'application/json') !== false) {
            return $this->response->setJSON(['status' => $status, 'message' => $message, 'data' => $data])->setStatusCode($status);
        }
        
        $key = $isError ? 'error' : 'success';
        return redirect()->back()->with($key, $message)->with('data', $data)->withInput();
    }

    // ==============================================================================
    // WEB UI ROUTE METHODS (These load your Dashboard pages)
    // ==============================================================================

    public function index()
    {
        if (!RoleAccess::canOperateSupplyChain(session('user')['role'] ?? null)) {
            return redirect()->to('/unauthorized');
        }

        $batchModel = new MedicineBatchModel();

        return view('supply/index', [
            'title'      => 'Supply Operations',
            'facilities' => (new HealthcareFacilityModel())->orderBy('name', 'ASC')->findAll(),
            'medicines'  => (new MedicineModel())->orderBy('generic_name', 'ASC')->findAll(),
            'batches'    => $batchModel
                ->select('medicine_batches.*, medicines.generic_name, medicines.sku, healthcare_facilities.name AS facility_name')
                ->join('medicines', 'medicines.id = medicine_batches.medicine_id')
                ->join('healthcare_facilities', 'healthcare_facilities.id = medicines.facility_id', 'left')
                ->orderBy('expiry_date', 'ASC')
                ->findAll(25),
            'expired'    => $batchModel->expiredActiveBatches(25),
            'movements'  => $this->recentMovements(),
        ]);
    }

    public function intake()
    {
        if (!RoleAccess::canOperateSupplyChain(session('user')['role'] ?? null)) {
            return redirect()->to('/unauthorized');
        }

        return view('supply/intake', [
            'title'     => 'Supply Intake',
            'medicines' => (new MedicineModel())->orderBy('generic_name', 'ASC')->findAll(),
        ]);
    }

    public function requisition()
    {
        return view('supply/requisition', [
            'title'      => 'Clinic Requisition',
            'facilities' => (new HealthcareFacilityModel())->orderBy('name', 'ASC')->findAll(),
            'medicines'  => (new MedicineModel())->orderBy('generic_name', 'ASC')->findAll(),
        ]);
    }

    public function disposal()
    {
        if (!RoleAccess::canOperateSupplyChain(session('user')['role'] ?? null)) {
            return redirect()->to('/unauthorized');
        }

        $batchModel = new MedicineBatchModel();

        return view('supply/disposal', [
            'title'   => 'Expiry Disposal',
            'expired' => $batchModel->expiredActiveBatches(100),
        ]);
    }

    // ==============================================================================
    // HYBRID DATA PROCESSING METHODS (Handles Form Submits AND Postman API)
    // ==============================================================================

    public function storeIntake()
    {
        if (!RoleAccess::canOperateSupplyChain(session('user')['role'] ?? null)) {
            return $this->respond(403, 'Unauthorized access.', [], true);
        }

        // Use getVar() to support both JSON and Form Data
        $data = [
            'medicine_id'        => $this->request->getVar('medicine_id'),
            'batch_number'       => $this->request->getVar('batch_number'),
            'warehouse_location' => $this->request->getVar('warehouse_location'),
            'received_quantity'  => $this->request->getVar('received_quantity'),
            'expiry_date'        => $this->request->getVar('expiry_date'),
            'supplier'           => $this->request->getVar('supplier'),
            'unit_cost'          => $this->request->getVar('unit_cost'),
        ];

        $rules = [
            'medicine_id'        => 'required|is_natural_no_zero',
            'batch_number'       => 'required|min_length[2]|max_length[80]',
            'warehouse_location' => 'required|min_length[2]|max_length[120]',
            'received_quantity'  => 'required|is_natural_no_zero',
            'expiry_date'        => 'required|valid_date[Y-m-d]',
        ];

        // validateData is safe for array inputs regardless of whether it's JSON or POST
        if (!$this->validateData($data, $rules)) {
            return $this->respond(400, 'Validation failed.', $this->validator->getErrors(), true);
        }

        // Database validations
        $medicine = (new MedicineModel())->find((int) $data['medicine_id']);
        if (!$medicine) {
            return $this->respond(404, 'Medicine does not exist.', [], true);
        }

        $batchModel = new MedicineBatchModel();
        $batchNumber = strtoupper(trim($data['batch_number']));

        if ($batchModel->where('batch_number', $batchNumber)->first()) {
            return $this->respond(409, 'Batch ID must be unique.', [], true);
        }

        if (strtotime($data['expiry_date']) <= strtotime(date('Y-m-d'))) {
            return $this->respond(422, 'Expiry date must be in the future.', [], true);
        }

        // Save to Database using Transactions
        $db = db_connect();
        $db->transStart();

        $quantity = (int) $data['received_quantity'];
        
        $batchId = $batchModel->insert([
            'medicine_id'        => (int) $data['medicine_id'],
            'batch_number'       => $batchNumber,
            'supplier'           => $data['supplier'] ?: null,
            'warehouse_location' => trim($data['warehouse_location']),
            'received_quantity'  => $quantity,
            'available_quantity' => $quantity,
            'unit_cost'          => $data['unit_cost'] ?: 0,
            'expiry_date'        => $data['expiry_date'],
            'received_at'        => date('Y-m-d H:i:s'),
            'status'             => 'available',
        ]);

        (new StockMovementModel())->insert([
            'facility_id'    => $medicine['facility_id'],
            'medicine_id'    => $medicine['id'],
            'batch_id'       => (int) $batchId,
            'movement_type'  => 'receive',
            'quantity'       => $quantity,
            'reference_type' => 'batch_receipt',
            'reference_id'   => $batchNumber,
            'remarks'        => 'Stock locked to ' . trim($data['warehouse_location']),
            'performed_by'   => session('user')['id'] ?? 0,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
             return $this->respond(500, 'Database error. Intake failed.', [], true);
        }

        return $this->respond(201, 'Stock intake successfully recorded.');
    }

    public function fulfillRequisition()
    {
        $data = [
            'facility_id' => $this->request->getVar('facility_id'),
            'medicine_id' => $this->request->getVar('medicine_id'),
            'quantity'    => $this->request->getVar('quantity'),
            'remarks'     => $this->request->getVar('remarks'),
        ];

        $rules = [
            'facility_id' => 'required|is_natural_no_zero',
            'medicine_id' => 'required|is_natural_no_zero',
            'quantity'    => 'required|is_natural_no_zero',
        ];

        if (!$this->validateData($data, $rules)) {
            return $this->respond(400, 'Invalid request.', $this->validator->getErrors(), true);
        }

        try {
            $result = (new FefoStockAllocator())->commitConsumption(
                (int)$data['facility_id'],
                (int)$data['medicine_id'],
                (int)$data['quantity'],
                (int)(session('user')['id'] ?? 0),
                'clinic_requisition',
                'REQ-' . date('Ymd-His'),
                $data['remarks'] ?: null
            );
            return $this->respond(200, 'Requisition fulfilled using FEFO.', $result);
        } catch (RuntimeException $e) {
            return $this->respond(422, $e->getMessage(), [], true);
        }
    }

    public function flagExpired()
    {
        if (!RoleAccess::canOperateSupplyChain(session('user')['role'] ?? null)) {
            return $this->respond(403, 'Unauthorized access.', [], true);
        }

        $updated = (new MedicineBatchModel())->flagExpiredActiveBatches();
        return $this->respond(200, $updated . ' expired batch(es) removed from active stock.');
    }

    // ==============================================================================
    // PRIVATE HELPERS
    // ==============================================================================

    private function recentMovements(): array
    {
        return db_connect()->table('stock_movements sm')
            ->select('sm.*, medicines.generic_name, medicine_batches.batch_number, healthcare_facilities.name AS facility_name')
            ->join('medicines', 'medicines.id = sm.medicine_id')
            ->join('medicine_batches', 'medicine_batches.id = sm.batch_id')
            ->join('healthcare_facilities', 'healthcare_facilities.id = sm.facility_id', 'left')
            ->orderBy('sm.created_at', 'DESC')
            ->limit(15)
            ->get()
            ->getResultArray();
    }
}
