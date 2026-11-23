<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TorMatrix;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ตารางเทียบ TOR (admin): every TOR clause → where the system answers it → status, printable for the acceptance
 * presentation (owner 2026-11-23). The content lives in App\Support\TorMatrix.
 */
class TorMatrixController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/TorMatrix', [
            'sections' => TorMatrix::forPage(),
            'statuses' => TorMatrix::STATUSES,
        ]);
    }
}
