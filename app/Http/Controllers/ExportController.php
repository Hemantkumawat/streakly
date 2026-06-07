<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ExportController extends Controller
{
    /** Download the signed-in user's full tracker data as a JSON backup. */
    public function __invoke(): JsonResponse
    {
        $user = Auth::user();

        $payload = [
            'version'     => 1,
            'exported_at' => now()->toIso8601String(),
            'user'        => ['name' => $user->name, 'email' => $user->email],
            'types'       => $user->activityTypes()
                ->orderBy('sort_order')
                ->get(['name', 'points', 'icon', 'sort_order', 'archived']),
            'logs'        => $user->activityLogs()
                ->orderBy('log_date')
                ->get(['type_id', 'name', 'points', 'log_date', 'note']),
        ];

        $filename = 'tracker_backup_'.now()->format('Y-m-d').'.json';

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
