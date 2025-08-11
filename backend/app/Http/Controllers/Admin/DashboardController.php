<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;

class DashboardController extends Controller
{
    public function index()
    {
        $tenantCount = Tenant::count();
        return view('admin.dashboard', compact('tenantCount'));
    }
}

