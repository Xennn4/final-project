<?php

namespace App\Models;

use CodeIgniter\Model;
/**
 * StockMovementModel
 *
 * @property int $id
 * @property int $facility_id
 * @property int $medicine_id
 * @property int $batch_id
 * @property string $movement_type
 * @property int $quantity
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $remarks
 * @property int $performed_by
 * @property string $created_at
 */

class StockMovementModel extends Model
{
    protected $table         = 'stock_movements';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $protectFields = true;
    protected $allowedFields = [
        'facility_id',
        'medicine_id',
        'batch_id',
        'movement_type',
        'quantity',
        'reference_type',
        'reference_id',
        'remarks',
        'performed_by',
        'created_at',
    ];

    protected $validationRules = [
        'facility_id'    => 'required|is_natural_no_zero',
        'medicine_id'    => 'required|is_natural_no_zero',
        'batch_id'       => 'required|is_natural_no_zero',
        'movement_type'  => 'required|in_list[receive,consume,adjust,quarantine,recall]',
        'quantity'       => 'required|is_natural_no_zero',
        'reference_type' => 'permit_empty|max_length[60]',
        'reference_id'   => 'permit_empty|max_length[80]',
    ];
}
