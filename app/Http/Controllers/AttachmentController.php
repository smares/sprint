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

        $headers = ['Content-Type' => $contentType, 'X-Content-Type-Options' => 'nosniff'];

        // Uploaded files never run scripts on the app's origin. PDFs are the exception, because
        // the browsers' PDF viewers refuse to work in a sandbox; they only run in the viewer itself.
        if (! $inline || $kind !== 'pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox";
        }

        return $disk->response($attachment->path, $attachment->name, $headers, $inline ? 'inline' : 'attachment');
    }
}
