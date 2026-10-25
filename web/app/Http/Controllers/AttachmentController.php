<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function show(Request $request, Attachment $attachment, AccessService $access)
    {
        abort_unless($request->user()->can('view', $attachment), 403);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
        $headers = ['Content-Type' => $attachment->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"];
        if ($request->routeIs('attachments.download')) {
            return Storage::disk($attachment->disk)->download($attachment->path, $attachment->name, $headers);
        }

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->name, $headers);
    }
}
