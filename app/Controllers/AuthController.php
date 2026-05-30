<?php

// app/Controllers/AuthController.php

namespace App\Controllers;

use App\Models\RoleModel;
use App\Models\UserModel;
use App\Services\RoleAccess;

class AuthController extends BaseController
{
    public function login()
    {
        if (session()->has('user')) {
            $role = RoleAccess::normalize(session('user')['role'] ?? null);

            if (! in_array($role, ['superadmin', 'manager', 'staff'], true)) {
                session()->destroy();
                return redirect()->to('/login');
            }

            return $this->redirectByRole($role);
        }
        return view('auth/login');
    }

    public function loginProcess()
    {
        $userModel = new UserModel();
        $isJson    = strpos($this->request->getHeaderLine('Content-Type'), 'application/json') !== false;

        // 1. Get data from POST (standard web form)
        $email    = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        // 2. ONLY try to get JSON if the request actually contains it
        if ($isJson) {
            $json = $this->request->getJSON();
            if ($json !== null) {
                $email    = $json->email ?? $email;
                $password = $json->password ?? $password;
            }
        }

        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required',
        ];

        // 3. Package the extracted data so CodeIgniter validates THIS array, not the empty POST request
        $validationData = [
            'email'    => $email,
            'password' => $password
        ];

        if (! $this->validateData($validationData, $rules)) {
            if ($isJson) {
                return $this->response->setJSON([
                    'status' => 400,
                    'errors' => $this->validator->getErrors()
                ])->setStatusCode(400);
            }

            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        // Pass the extracted string to the model
        $found = $userModel->findByEmailWithRole($email);

        if (! $found || ! password_verify($password, $found['password'])) {
            if ($isJson) {
                return $this->response->setJSON([
                    'status' => 401,
                    'error'  => 'Invalid email or password.'
                ])->setStatusCode(401);
            }

            return redirect()->back()->withInput()
                             ->with('error', 'Invalid email or password.');
        }

        // ── Store role in session so filters can read it ──────
        session()->set([
            'user' => [
                'id'    => $found['id'],
                'name'  => $found['name'],
                'email' => $found['email'],
                'role'  => RoleAccess::normalize($found['role_name']) ?? 'staff',
            ],
        ]);

        // Success response for Postman
        if ($isJson) {
            return $this->response->setJSON([
                'status'  => 200,
                'message' => 'Login successful',
                'user'    => session('user')
            ])->setStatusCode(200);
        }

        // Success response for Web Browser
        session()->setFlashdata('success', 'Welcome, ' . $found['name'] . '!');
        return $this->redirectByRole($found['role_name'] ?? 'staff');
    }

    /**
     * Redirect user to the correct dashboard based on their role.
     */
    protected function redirectByRole(?string $role): \CodeIgniter\HTTP\RedirectResponse
    {
        return match (RoleAccess::normalize($role)) {
            'superadmin' => redirect()->to('/dashboard'),
            'manager'    => redirect()->to('/dashboard'),
            'staff'      => redirect()->to('/staff/dashboard'),
            default      => redirect()->to('/login'),
        };
    }

    public function register()
    {
        if (session()->has('user')) {
            $role = RoleAccess::normalize(session('user')['role'] ?? null);

            if (! in_array($role, ['superadmin', 'manager', 'staff'], true)) {
                session()->destroy();
                return redirect()->to('/register');
            }

            return $this->redirectByRole($role);
        }
        return view('auth/register');
    }

    public function registerProcess()
    {
        $userModel = new UserModel();
        $isJson    = strpos($this->request->getHeaderLine('Content-Type'), 'application/json') !== false;

        $rules = [
            'name'             => 'required|min_length[2]|max_length[100]',
            'email'            => 'required|valid_email|is_unique[users.email]',
            'password'         => 'required|min_length[8]',
            'confirm_password' => 'required|matches[password]',
        ];

        // 1. Default to standard Web Form POST data
        $name             = $this->request->getPost('name');
        $email            = $this->request->getPost('email');
        $password         = $this->request->getPost('password');
        $confirm_password = $this->request->getPost('confirm_password');

        // 2. ONLY parse JSON if the incoming request explicitly declares it is JSON
        if ($isJson) {
            $json = $this->request->getJSON();
            if ($json) {
                $name             = $json->name ?? $name;
                $email            = $json->email ?? $email;
                $password         = $json->password ?? $password;
                $confirm_password = $json->confirm_password ?? $confirm_password;
            }
        }

        $validationData = [
            'name'             => $name,
            'email'            => $email,
            'password'         => $password,
            'confirm_password' => $confirm_password
        ];

        if (! $this->validateData($validationData, $rules, ['confirm_password' => ['matches' => 'Passwords do not match.']])) {
            if ($isJson) {
                return $this->response->setJSON([
                    'status' => 400,
                    'errors' => $this->validator->getErrors()
                ])->setStatusCode(400);
            }

            return redirect()->back()->withInput()
                             ->with('errors', $this->validator->getErrors());
        }

        $staffRole = (new RoleModel())->findByName('staff');

        $userModel->insert([
            'name'     => $name,
            'email'    => $email,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role_id'  => $staffRole['id'] ?? null,
        ]);

        if ($isJson) {
            return $this->response->setJSON([
                'status'  => 201,
                'message' => 'Registration successful! Please log in.'
            ])->setStatusCode(201);
        }

        session()->setFlashdata('success', 'Registration successful! Please log in.');
        return redirect()->to('/login');
    }

    public function logout()
    {
        session()->destroy();
        $isJson = strpos($this->request->getHeaderLine('Content-Type'), 'application/json') !== false;

        if ($isJson) {
            return $this->response->setJSON([
                'status'  => 200,
                'message' => 'Logged out successfully.'
            ])->setStatusCode(200);
        }

        session()->setFlashdata('success', 'Logged out successfully.');
        return redirect()->to('/login');
    }

    /**
     * 403 Unauthorized page — shown when a role filter blocks access.
     */
    public function unauthorized()
    {
        return view('errors/unauthorized');
    }
}