<?php

namespace App\Models;

use CodeIgniter\Model;

class MedicineBatchModel extends Model
{
    protected $table            = 'medicine_batches';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useTimestamps    = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'medicine_id', 'batch_number', 'supplier', 'warehouse_location',
        'received_quantity', 'available_quantity', 'unit_cost',
        'manufactured_date', 'expiry_date', 'received_at', 'status',
    ];

    /**
     * Logic for FEFO: Fetches available batches that are NOT yet expired.
     */
    public function availableFefoBatches(int $medicineId): array
    {
        return $this->where('medicine_id', $medicineId)
            ->where('available_quantity >', 0)
            ->where('expiry_date >', date('Y-m-d')) // Must be strictly in the future
            ->where('status', 'available')
            ->orderBy('expiry_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * Fetches batches that are TODAY or EARLIER (<=) for disposal.
     */
    public function expiredActiveBatches(int $limit = 100): array
    {
        return $this->db->table($this->table . ' b')
            ->select('b.*, m.sku, m.generic_name, m.facility_id, f.name AS facility_name')
            ->join('medicines m', 'm.id = b.medicine_id')
            ->join('healthcare_facilities f', 'f.id = m.facility_id', 'left')
            // Using <= includes batches that expire TODAY, which is critical for safety
            ->where('b.expiry_date <=', date('Y-m-d')) 
            ->where('b.status', 'available') 
            ->orderBy('b.expiry_date', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * Flags batches as 'expired'.
     */
    public function flagExpiredActiveBatches(): int
    {
        $this->builder()
            ->where('expiry_date <=', date('Y-m-d'))
            ->where('status', 'available')
            ->set(['status' => 'expired'])
            ->update();

        return $this->db->affectedRows();
    }

    /**
     * Helper to show all batches with medicine details for the main index page
     */
    public function getAvailableBatchesWithDetails(int $limit = 25): array
    {
        return $this->select('medicine_batches.*, medicines.generic_name, medicines.sku, healthcare_facilities.name AS facility_name')
            ->join('medicines', 'medicines.id = medicine_batches.medicine_id')
            ->join('healthcare_facilities', 'healthcare_facilities.id = medicines.facility_id', 'left')
            ->orderBy('expiry_date', 'ASC')
            ->findAll($limit);
    }
}