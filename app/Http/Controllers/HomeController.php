<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class HomeController extends Controller
{
    /**
     * Display the dashboard
     */
    public function index(Request $request)
    {
        return Inertia::render('Dashboard');
    }
}

