<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Services\ExportLabService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ExportLabController extends Controller
{
    public function __construct(private ExportLabService $lab) {}

    public function index(): View
    {
        return view('lab.exports', [
            'snapshot' => $this->lab->snapshot(),
        ]);
    }

    public function snapshot(): JsonResponse
    {
        return response()->json($this->lab->snapshot());
    }
}
