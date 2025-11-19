<?php

namespace App\Services;

use App\Models\ChatRoom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileStorageService
{
    /**
     * Maximum file size per upload (5 MB).
     */
    const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB in bytes

    /**
     * Maximum total file size per user (50 MB).
     */
    const MAX_TOTAL_SIZE = 50 * 1024 * 1024; // 50 MB in bytes

    /**
     * Allowed file types.
     */
    const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'application/pdf',
    ];

    /**
     * Allowed file extensions.
     */
    const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'pdf'];

    /**
     * Validate and store a file for a chat room.
     *
     * @param UploadedFile $file
     * @param ChatRoom $room
     * @return array
     * @throws \Exception
     */
    public function storeFile(UploadedFile $file, ChatRoom $room): array
    {
        // Validate the file
        $this->validateFile($file);

        // Generate secure filename
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $safeName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));
        $filename = $safeName . '_' . time() . '_' . Str::random(8) . '.' . $extension;

        // Build storage path
        $year = now()->year;
        $month = now()->month;
        $path = "pistat/chat-files/{$room->farm_id}/{$room->id}/{$year}/{$month}/{$filename}";

        // Store the file
        $storedPath = Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        if (!$storedPath) {
            throw new \Exception('Failed to store file');
        }

        return [
            'path' => $path,
            'name' => $originalName,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ];
    }

    /**
     * Validate a file before storing.
     *
     * @param UploadedFile $file
     * @return void
     * @throws \Exception
     */
    public function validateFile(UploadedFile $file): void
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \Exception('File size exceeds maximum allowed size of 5 MB');
        }

        // Check file extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new \Exception('File type not allowed. Allowed types: PNG, JPG, PDF');
        }

        // Check MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \Exception('File MIME type not allowed');
        }

        // Additional security: verify file content matches extension
        $this->verifyFileContent($file, $extension);
    }

    /**
     * Verify file content matches its extension.
     *
     * @param UploadedFile $file
     * @param string $extension
     * @return void
     * @throws \Exception
     */
    protected function verifyFileContent(UploadedFile $file, string $extension): void
    {
        $mimeType = $file->getMimeType();

        // Verify image files
        if (in_array($extension, ['png', 'jpg', 'jpeg'])) {
            if (!in_array($mimeType, ['image/png', 'image/jpeg'])) {
                throw new \Exception('File content does not match file type');
            }

            // Try to get image info to verify it's a valid image
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo === false) {
                throw new \Exception('Invalid image file');
            }
        }

        // Verify PDF files
        if ($extension === 'pdf') {
            if ($mimeType !== 'application/pdf') {
                throw new \Exception('File content does not match file type');
            }

            // Basic PDF verification - check if file starts with PDF header
            $handle = fopen($file->getRealPath(), 'rb');
            $header = fread($handle, 4);
            fclose($handle);

            if ($header !== '%PDF') {
                throw new \Exception('Invalid PDF file');
            }
        }
    }

    /**
     * Get a secure temporary URL for file download.
     * Note: For local storage, files are accessed through the ChatFileController
     * which handles authorization. This method is kept for consistency.
     *
     * @param string $path
     * @param int $expiresInMinutes
     * @return string
     */
    public function getFileUrl(string $path, int $expiresInMinutes = 60): string
    {
        // Files are accessed through the ChatFileController route
        // The actual URL will be constructed in MessageResource
        return $path;
    }

    /**
     * Delete a file from storage.
     *
     * @param string $path
     * @return bool
     */
    public function deleteFile(string $path): bool
    {
        return Storage::disk('local')->delete($path);
    }

    /**
     * Get file content for download.
     *
     * @param string $path
     * @return string|null
     */
    public function getFileContent(string $path): ?string
    {
        if (!Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->get($path);
    }
}

