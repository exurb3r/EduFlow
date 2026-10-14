<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

final class InstitutionPolicyDocuments
{
    /** @return array{text:string,name:string,digest:string} */
    public function extract(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'pdf') {
            $path = 'policy-imports/'.Str::uuid().'.pdf';
            if (! Storage::disk('local')->put($path, $file->getContent(), ['visibility' => 'private'])) {
                throw ValidationException::withMessages(['document' => 'Private extraction storage unavailable. Paste policy text instead.']);
            }
            try {
                $process = new Process(['pdftotext', '-layout', Storage::disk('local')->path($path), '-']);
                $process->setTimeout(20);
                $process->run();
                if (! $process->isSuccessful()) {
                    throw ValidationException::withMessages(['document' => 'PDF text extraction unavailable. Paste policy text instead.']);
                }
                $text = $process->getOutput();
            } catch (Throwable) {
                throw ValidationException::withMessages(['document' => 'PDF text extraction unavailable. Paste policy text instead.']);
            } finally {
                Storage::disk('local')->delete($path);
            }
        } else {
            $text = $file->getContent();
        }
        if (! mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text) > 120000) {
            throw ValidationException::withMessages(['document' => 'Upload readable UTF-8 text or text-based PDF up to 120,000 characters. Scanned documents require reviewed OCR first.']);
        }

        return ['text' => trim($text), 'name' => basename($file->getClientOriginalName()), 'digest' => hash('sha256', $file->getContent())];
    }
}
