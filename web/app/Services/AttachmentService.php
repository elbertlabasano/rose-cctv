<?php

namespace App\Services;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttachmentService
{
    private array $written = [];

    public function transaction(callable $callback): mixed
    {
        $this->written = [];
        try {
            return DB::transaction($callback);
        } catch (\Throwable $error) {
            foreach ($this->written as $path) {
                Storage::disk('local')->delete($path);
            } throw $error;
        } finally {
            $this->written = [];
        }
    }

    public function store(UploadedFile $file, int $branchId, array $owner): Attachment
    {
        $mime = $file->getMimeType();
        if ($mime === 'application/pdf') {
            $bytes = $file->getContent();
            $extension = 'pdf';
        } else {
            $info = @getimagesize($file->getRealPath());
            if (! $info || $info[0] * $info[1] > 20000000 || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'])) {
                throw ValidationException::withMessages(['images' => 'Use a valid image up to 20 megapixels.']);
            }
            $source = @imagecreatefromstring($file->getContent());
            if (! $source) {
                throw ValidationException::withMessages(['images' => 'The image could not be decoded.']);
            }
            $scale = min(1, 1600 / max($info[0], $info[1]));
            $target = imagecreatetruecolor(max(1, (int) ($info[0] * $scale)), max(1, (int) ($info[1] * $scale)));
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $info[0], $info[1]);
            ob_start();
            imagejpeg($target, null, 85);
            $bytes = ob_get_clean();
            imagedestroy($target);
            imagedestroy($source);
            $mime = 'image/jpeg';
            $extension = 'jpg';
        }
        $path = 'evidence/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new \RuntimeException('Evidence storage unavailable.');
        }
        $this->written[] = $path;

        return Attachment::create([...$owner, 'branch_id' => $branchId, 'uploaded_by' => auth()->id(), 'disk' => 'local', 'path' => $path, 'name' => basename($file->getClientOriginalName()), 'mime' => $mime, 'size' => strlen($bytes)]);
    }

    public function remove(Attachment $attachment): void
    {
        $path = $attachment->path;
        $disk = $attachment->disk;
        $attachment->delete();
        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }
}
