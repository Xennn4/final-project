<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// --- Public Authentication Routes ---
$routes->get('/', 'AuthController::login');
$routes->get('login', 'AuthController::login');
$routes->post('login', 'AuthController::loginProcess');
$routes->get('register', 'AuthController::register');
$routes->post('register', 'AuthController::registerProcess');
$routes->get('logout', 'AuthController::logout');
$routes->get('unauthorized', 'AuthController::unauthorized');

// --- Unified Authenticated Web Routes ---
$routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    
    // Dashboards
    $routes->get('dashboard', 'Home::index');
    $routes->get('staff/dashboard', 'StaffController::dasshboard');

    // Supply Chain Operations
    $routes->get('supply', 'SupplyChainController::index');
    $routes->get('supply/intake', 'SupplyChainController::intake');
    $routes->post('supply/intake', 'SupplyChainController::storeIntake');
    $routes->get('supply/disposal', 'SupplyChainController::disposal');
    $routes->post('supply/disposal/flag-expired', 'SupplyChainController::flagExpired');
    
    $routes->get('supply/requisition', 'SupplyChainController::requisition');
    $routes->post('supply/requisition', 'SupplyChainController::fulfillRequisition');

    // User Profile
    $routes->get('profile', 'ProfileController::show');
    $routes->get('profile/edit', 'ProfileController::edit');
    $routes->post('profile/update', 'ProfileController::update');
});

// --- Strict SuperAdmin Routes ---
$routes->group('admin', ['filter' => ['auth', 'superadmin']], static function (RouteCollection $routes): void {
    $routes->get('roles', 'Admin\RoleController::index');
    $routes->get('roles/create', 'Admin\RoleController::create');
    $routes->post('roles/store', 'Admin\RoleController::store');
    $routes->get('roles/edit/(:num)', 'Admin\RoleController::edit/$1');
    $routes->post('roles/update/(:num)', 'Admin\RoleController::update/$1');
    $routes->get('roles/delete/(:num)', 'Admin\RoleController::delete/$1');

    $routes->get('users', 'Admin\UserAdminController::index');
    $routes->post('users/assign-role/(:num)', 'Admin\UserAdminController::assignRole/$1');
});

// --- API Routes ---
$routes->post('api/v1/auth/login', 'Api\AuthController::issueToken');

$routes->group('api/v1', ['filter' => 'api_auth'], static function (RouteCollection $routes): void {
    $routes->delete('auth/token', 'Api\AuthController::revokeToken');

    $routes->resource('facilities', [
        'controller'  => 'Api\FacilitiesController',
        'except'      => ['new', 'edit', 'delete'],
    ]);
    
    $routes->resource('medicines', [
        'controller'  => 'Api\MedicinesController',
        'except'      => ['new', 'edit'],
    ]);
    
    $routes->get('stock/current', 'Api\MedicinesController::currentStock');
    
    $routes->resource('medicine-batches', [
        'controller'  => 'Api\MedicineBatchesController',
        'except'      => ['new', 'edit', 'delete'],
    ]);

    $routes->post('stock-allocations', 'Api\StockAllocationsController::create');
    $routes->get('expiry-alerts', 'Api\ExpiryAlertsController::index');
    $routes->post('medical-attachments', 'Api\MedicalAttachmentsController::create');
});
