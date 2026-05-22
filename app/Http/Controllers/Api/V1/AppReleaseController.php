<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppReleaseRequest;
use App\Http\Resources\AppReleaseResource;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;

class AppReleaseController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:root', ['only' => ['store']]);
    }

    public function store(StoreAppReleaseRequest $request): JsonResponse
    {
        $release = AppRelease::create([
            'version' => $request->string('version')->toString(),
            'release_notes' => $request->input('release_notes'),
            'created_by' => $request->user()->id,
            'published_at' => now(),
            'file_url' => $request->input('file.path') . $request->input('file.name'),
        ]);

        return (new AppReleaseResource($release->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function latest(): AppReleaseResource
    {
        $release = AppRelease::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->firstOrFail();

        return new AppReleaseResource($release);
    }

    public function download(AppRelease $appRelease)
    {
        return response()->download(
            storage_path('app/public/' . $appRelease->file_url)
        );
    }
}
