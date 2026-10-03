<?php

namespace App\Http\Controllers;

use App\Support\AcademicPrograms;
use Illuminate\Http\JsonResponse;

class AcademicProgramController extends Controller
{
    /**
     * Course / department options grouped by department. Public, because
     * the mobile registration form needs it before the student signs in.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => collect(AcademicPrograms::grouped())
                ->map(fn (array $courses, string $department) => [
                    'department' => $department,
                    'courses' => array_values($courses),
                ])
                ->values(),
        ]);
    }
}
