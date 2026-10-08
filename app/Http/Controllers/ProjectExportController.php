<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\TaskCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectExportController extends Controller
{
    public function __invoke(Request $request, Project $project, TaskCsv $csv): StreamedResponse
    {
        Gate::authorize('view', $project);

        $delimiter = $request->query('delimiter') === 'semicolon' ? ';' : ',';
        $name = Str::slug($project->name) ?: 'project';

        return response()->streamDownload(function () use ($csv, $project, $delimiter) {
            $out = fopen('php://output', 'w');
            $csv->write($out, $project, $delimiter);
            fclose($out);
        }, "{$name}-".now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
