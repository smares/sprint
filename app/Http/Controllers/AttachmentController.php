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

        $disk = Storage::disk(Attachment::DISK);
        abort_unless($disk->exists($attachment->path), 404);

        $inline = $request->boolean('inline') && $attachment->isInlineImage();

        return $disk->response($attachment->path, $attachment->name, [
            'Content-Type' => $inline ? $attachment->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }
}
