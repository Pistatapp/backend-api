<?php

namespace App\Services;

use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use Illuminate\Support\Str;
use Illuminate\Http\UploadedFile;

class TicketAttachmentService
{
    /**
     * Store an attachment securely.
     */
    public function storeAttachment(UploadedFile $file, TicketMessage $message): TicketAttachment
    {
        // Validate MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file->getRealPath());
        finfo_close($finfo);

        $allowedMimes = ['image/png', 'image/jpeg', 'application/pdf'];
        if (!in_array($mimeType, $allowedMimes)) {
            abort(422, __('Invalid file type. Only PNG, JPG, and PDF files are allowed.'));
        }

        // Generate secure random filename
        $extension = $file->getClientOriginalExtension();
        $hash = Str::random(40);
        $fileName = $hash . '.' . $extension;

        // Store file in organized structure: pistat/support/{ticket_id}/{hash}.{ext}
        $ticketId = $message->ticket_id;
        $storagePath = "pistat/support/{$ticketId}/{$fileName}";

        // Store file
        $file->storeAs("pistat/support/{$ticketId}", $fileName, 'local');

        // Create attachment record
        return TicketAttachment::create([
            'ticket_message_id' => $message->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $storagePath,
            'file_size' => $file->getSize(),
            'mime_type' => $mimeType,
        ]);
    }
}

