<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = true;

    protected array $casts = [];
    protected array $castHandlers = [];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    protected $skipValidation       = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert   = [];
    protected $afterInsert    = [];
    protected $beforeUpdate   = [];
    protected $afterUpdate    = [];
    protected $beforeFind     = [];
    protected $afterFind      = [];
    protected $beforeDelete   = [];
    protected $afterDelete    = [];

    protected $allowedFields = [
        'name',
        'email',
        'password',
        'role_id',
        'profile_image',
    ];

    protected $validationRules = [
        'name'     => 'required|min_length[2]|max_length[100]',
        'email'    => 'required|valid_email|is_unique[users.email]',
        'password' => 'required|min_length[8]',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->first();
    }

    public function findByEmailWithRole(string $email): ?array
    {
        return $this->db->table('users u')
            ->select('u.*, r.name AS role_name, r.label AS role_label')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->where('u.email', $email)
            ->get()
            ->getRowArray();
    }

    public function findWithRole(int $id): ?array
    {
        return $this->db->table('users u')
            ->select('u.*, r.name AS role_name, r.label AS role_label')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->where('u.id', $id)
            ->get()
            ->getRowArray();
    }

    public function getAllWithRoles(): array
    {
        return $this->db->table('users u')
            ->select('u.id, u.name, u.email, u.created_at,
                      r.name AS role_name, r.label AS role_label')
            ->join('roles r', 'r.id = u.role_id', 'left')
            ->orderBy('u.name', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function updateProfile(int $userId, array $data): bool
    {
        return $this->update($userId, $data);
    }
}

