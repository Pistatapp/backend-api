<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\SendAppReleaseNotifications;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppReleaseRequest;
use App\Http\Resources\AppReleaseResource;
use App\Models\AppRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;

class AppReleaseController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:root', ['only' => ['store']]);
    }

    public function store(StoreAppReleaseRequest $request): JsonResponse
    {
        $staged = $this->resolveStagedFile($request->input('file', []));
        $disk = Storage::disk('local');

        try {
            $absolutePath = $disk->path($staged['relative_path']);
            $fileSize = filesize($absolutePath);

            if ($fileSize === false || $fileSize <= 0 || $fileSize > config('app-release.max_file_size')) {
                throw ValidationException::withMessages([
                    'file' => 'اندازه فایل APK مجاز نیست.',
                ]);
            }

            $sha256 = hash_file('sha256', $absolutePath);
            if ($sha256 === false) {
                throw ValidationException::withMessages([
                    'file' => 'امکان بررسی صحت فایل APK وجود ندارد.',
                ]);
            }

            $release = DB::transaction(function () use ($request, $staged, $absolutePath, $fileSize, $sha256) {
                $release = AppRelease::create([
                    'version' => $request->string('version')->toString(),
                    'release_notes' => $request->input('release_notes'),
                    'created_by' => $request->user()->id,
                    'published_at' => now(),
                    'package_name' => config('app-release.package_name'),
                    'version_code' => $request->integer('version_code') ?: null,
                    'sha256' => $sha256,
                    'file_size' => $fileSize,
                    'mime_type' => 'application/vnd.android.package-archive',
                ]);

                $release->addMedia($absolutePath)
                    ->usingFileName($staged['name'])
                    ->withCustomProperties(['sha256' => $sha256])
                    ->toMediaCollection('package');

                $media = $release->packageMedia();
                if ($media === null) {
                    throw new \RuntimeException('The application package could not be stored.');
                }

                $release->update([
                    'file_url' => $media->getPathRelativeToRoot(),
                ]);

                return $release->fresh();
            });
        } finally {
            // The staging file is private and short-lived. The media library
            // owns the final package after the transaction succeeds.
            $disk->delete($staged['relative_path']);
        }

        if (config('app-release.notifications_enabled')) {
            SendAppReleaseNotifications::dispatch($release->id);
        }

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
        $media = $appRelease->packageMedia();
        if ($media !== null) {
            $path = $media->getPathRelativeToRoot();

            return Storage::disk('app_releases')->download($path, $media->file_name, [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Legacy releases are supported only for safe, relative paths. New
        // releases never use this public-disk fallback.
        $legacyPath = (string) $appRelease->file_url;
        if ($legacyPath === '' || Str::contains($legacyPath, ['..', '\\']) || Str::startsWith($legacyPath, ['/'])) {
            abort(404);
        }

        $legacyDisk = Storage::disk('public');
        if (!$legacyDisk->exists($legacyPath)) {
            abort(404);
        }

        return $legacyDisk->download($legacyPath, basename($legacyPath), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolve only files created by the protected release staging endpoint.
     * Client-provided paths can never escape the local storage root.
     *
     * @param array<string, mixed> $metadata
     * @return array{relative_path: string, name: string}
     */
    private function resolveStagedFile(array $metadata): array
    {
        $path = trim((string) ($metadata['path'] ?? ''), '/');
        $name = (string) ($metadata['name'] ?? '');

        if ($name === '' || basename($name) !== $name || !preg_match('/^[A-Za-z0-9._-]+\.apk$/i', $name)) {
            throw ValidationException::withMessages(['file.name' => 'نام فایل APK معتبر نیست.']);
        }

        $allowedPrefix = Str::startsWith($path, ['app-releases/staging/', 'upload/']);
        if (!$allowedPrefix || Str::contains($path, ['..', '\\'])) {
            throw ValidationException::withMessages(['file.path' => 'مسیر فایل APK معتبر نیست.']);
        }

        $relativePath = $path . '/' . $name;
        $disk = Storage::disk('local');
        if (!$disk->exists($relativePath)) {
            throw ValidationException::withMessages(['file' => 'فایل موقت APK پیدا نشد.']);
        }

        $realPath = realpath($disk->path($relativePath));
        $storageRoot = realpath(storage_path('app'));
        if ($realPath === false || $storageRoot === false || !Str::startsWith(
            $realPath,
            rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        )) {
            throw ValidationException::withMessages(['file' => 'مسیر فایل APK امن نیست.']);
        }

        return [
            'relative_path' => $relativePath,
            'name' => $name,
        ];
    }
}
