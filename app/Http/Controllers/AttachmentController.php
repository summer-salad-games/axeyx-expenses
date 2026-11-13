<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Services\AttachmentFiles;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class AttachmentController
{
    public function __invoke(Attachment $attachment, string $mode = 'download'): BinaryFileResponse
    {
        Gate::authorize('view', $attachment);
        $files = app(AttachmentFiles::class);
        $path = $files->readPath($attachment->path);
        abort_unless($path && in_array($attachment->mime_type, AttachmentFiles::TYPES, true), 404);
        $extension = pathinfo($attachment->path, PATHINFO_EXTENSION);
        abort_unless(AttachmentFiles::TYPES[$extension] === $attachment->mime_type, 404);
        abort_unless(in_array($mode, ['download', 'view'], true), 404);

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            $mode === 'download' ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE,
            AttachmentFiles::displayName($attachment->original_name), 'document.'.$extension,
        );
        $response->headers->set('Content-Type', $attachment->mime_type);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'; frame-ancestors 'self'");
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
