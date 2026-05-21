<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppReleaseRequest;
use App\Http\Resources\AppReleaseResource;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        ]);

        $pathToFile = storage_path('app/'.$request->input('file.path').$request->input('file.name'));

        $release
            ->addMedia($pathToFile)
            ->toMediaCollection('package');

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

    public function download(AppRelease $appRelease): BinaryFileResponse
    {
        $media = $appRelease->packageMedia();

        abort_if($media === null, 404, 'Release package was not found.');

        return response()->download($media->getPath(), $media->file_name);
    }
}
