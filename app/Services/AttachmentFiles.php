<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class AttachmentFiles
{
    public const TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    public function disk(): FilesystemAdapter
    {
        return Storage::disk('attachments');
    }

    /** Never trust an existing stored path supplied through a form. */
    public function inspect(mixed $upload): array
    {
        $reject = fn (string $message = 'Fichier invalide. Formats autorisés : JPEG, PNG, WebP et PDF.') => throw ValidationException::withMessages(['new_attachments' => $message]);
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            $reject();
        }
        $path = $upload->getRealPath();
        if ($upload instanceof TemporaryUploadedFile) {
            $temporaryRoot = realpath(FileUploadConfiguration::storage()->path(FileUploadConfiguration::directory()));
            if (! $temporaryRoot || dirname(realpath($path) ?: '') !== $temporaryRoot || is_link($path)) {
                $reject();
            }
        }
        $size = $path ? filesize($path) : false;
        if (! $size || $size > config('expenses.attachments.file_bytes')) {
            throw ValidationException::withMessages(['new_attachments' => 'Chaque fichier doit être non vide et respecter la taille maximale autorisée.']);
        }
        $extension = strtolower($upload->getClientOriginalExtension());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! isset(self::TYPES[$extension]) || self::TYPES[$extension] !== $mime) {
            $reject();
        }
        $bytes = file_get_contents($path);
        if ($mime === 'application/pdf') {
            // Some PDF generators leave horizontal whitespace after the version.
            // Structural readability is checked by the bounded parser below.
            if (! preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)[\x00\t\n\f\r ]/', $bytes) || ! preg_match('/%%EOF\s*\z/', $bytes)) {
                $reject('La signature du document PDF est invalide ou le fichier est incomplet.');
            }
        } else {
            $dimensions = @getimagesize($path);
            if (! $dimensions || ($dimensions['mime'] ?? '') !== $mime
                || config('expenses.attachments.image_pixels') < $dimensions[0] * $dimensions[1]) {
                $reject();
            }
        }
        // Bound both parser decompression and raster decoding independently of the
        // web worker. Malformed/resource-exhausting files fail before any staging.
        $binary = (new PhpExecutableFinder)->find(false);
        if (! $binary) {
            throw new RuntimeException('PHP CLI is required for attachment validation.');
        }
        $temporary = Storage::disk('local');
        $temporary->makeDirectory('validation-tmp');
        $temporaryPath = $temporary->path('validation-tmp');
        if (is_link($temporaryPath) || realpath($temporaryPath) !== realpath($temporary->path('')).'/validation-tmp') {
            throw new RuntimeException('Unsafe validation temporary directory.');
        }
        $process = new Process([$binary, '-d', 'memory_limit=128M', '-d', 'max_execution_time=5', '-d', 'sys_temp_dir='.$temporaryPath, base_path('scripts/validate-attachment.php'), $mime], base_path(), ['TMPDIR' => $temporaryPath, 'TMP' => $temporaryPath, 'TEMP' => $temporaryPath]);
        $process->setInput($bytes)->setTimeout(8);
        try {
            $process->run();
            if (! $process->isSuccessful()) {
                $reject($mime === 'application/pdf'
                    ? 'Le PDF ne peut pas être lu. Utilisez un document non chiffré de 500 pages maximum.'
                    : 'L’image ne peut pas être lue. Utilisez une image JPEG, PNG ou WebP valide.');
            }
        } catch (ProcessTimedOutException) {
            $reject('La vérification du fichier a pris trop de temps. Essayez un document plus simple.');
        }

        return [
            'source' => $path,
            'path' => 'objects/'.bin2hex(random_bytes(16)).'.'.$extension,
            'original_name' => self::displayName($upload->getClientOriginalName()),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => hash('sha256', $bytes),
        ];
    }

    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\p{C}]/u', '', mb_scrub($name));

        return Str::limit(trim($name), 240, '') ?: 'document';
    }

    /** Copy outside the DB transaction, while holding the consistency lock. */
    public function stage(array $file): void
    {
        $this->disk()->makeDirectory('objects');
        $this->assertDirectory();
        $stream = fopen($file['source'], 'rb');
        try {
            $this->disk()->put($file['path'], $stream, ['visibility' => 'private']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $path = $this->readPath($file['path']);
        if (! $path || filesize($path) !== $file['size_bytes'] || hash_file('sha256', $path) !== $file['sha256']) {
            throw new RuntimeException('Attachment staging verification failed.');
        }
    }

    public function readPath(string $relative): ?string
    {
        if (! preg_match('/\Aobjects\/[a-f0-9]{32}\.(?:jpg|jpeg|png|webp|pdf)\z/', $relative)) {
            return null;
        }
        $path = $this->disk()->path($relative);
        $root = realpath($this->disk()->path(''));

        return $root && ! is_link($path) && is_file($path) && realpath($path) === $root.'/'.$relative ? $path : null;
    }

    private function assertDirectory(): void
    {
        $root = realpath($this->disk()->path(''));
        if (! $root || is_link($root.'/objects') || realpath($root.'/objects') !== $root.'/objects') {
            throw new RuntimeException('Unsafe attachment directory.');
        }
    }

    /** Never remove a file until its database reference is committed away. Caller holds the lock. */
    public function removeUnreferenced(string $relative): bool
    {
        if (Attachment::query()->where('path', $relative)->exists()) {
            return false;
        }
        if (! $this->readPath($relative)) {
            return false;
        }
        try {
            if (! $this->disk()->delete($relative)) {
                throw new RuntimeException('Attachment cleanup failed.');
            }

            return true;
        } catch (\Throwable $exception) {
            // The record is already committed; hourly reconciliation retries orphan cleanup.
            Log::warning('attachment.cleanup_pending', ['exception' => $exception::class]);

            return false;
        }
    }
}
