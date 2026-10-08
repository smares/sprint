<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment->task->project);

        $disk = Storage::disk();
        abort_unless($disk->exists($attachment->path), 404);

        $kind = $attachment->previewKind();
        $inline = $request->boolean('inline') && $kind !== null;

        $contentType = match (true) {
            ! $inline => 'application/octet-stream',
            $kind === 'text' => 'text/plain; charset=UTF-8',
            default => $attachment->mime_type,
        };

        return $disk->response($attachment->path, $attachment->name, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }
}
