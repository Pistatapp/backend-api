<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\AttachmentResource;

class AttachmentController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Attachment::class, 'attachment');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:5000',
            'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif,mp4|max:10240',
            'attachable_type' => 'required|string',
            'attachable_id' => 'required|integer',
        ]);

        try {
            $attachableModel = app('App\Models\\' . ucfirst($request->attachable_type))->findOrFail($request->attachable_id);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'attachable_type' => 'Attachable model not found.',
            ]);
        }

        $attachment = $attachableModel->attachments()->create([
            'name' => $request->name,
            'description' => $request->description,
            'verified' => true,
            'user_id' => $request->user()->id,
        ]);

        $attachment->addMedia($request->file)->toMediaCollection('attachments');

        return new AttachmentResource($attachment->fresh());
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Attachment $attachment)
    {
        $request->validate([
            'name' => 'required|string',
            'description' => 'required|string',
            'file' => 'nullable|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,gif,mp4|max:10240',
        ]);

        $attachment->update($request->only('name', 'description'));

        if ($request->hasFile('file')) {
            $attachment->clearMediaCollection('attachments');
            $attachment->addMedia($request->file)->toMediaCollection('attachments');
        }

        return new AttachmentResource($attachment->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attachment $attachment)
    {
        $attachment->clearMediaCollection('attachments');
        $attachment->delete();
        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
