<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Pion\Laravel\ChunkUpload\Handler\AbstractHandler;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;
use Pion\Laravel\ChunkUpload\Exceptions\UploadMissingFileException;
use Illuminate\Http\UploadedFile;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    /**
     * Handles the file upload
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws UploadMissingFileException
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\UploadFailedException
     */
    public function upload(Request $request)
    {
        $this->validateReleaseUpload($request);

        // create the file receiver
        $receiver = new FileReceiver("file", $request, HandlerFactory::classFromRequest($request));

        // check if the upload is success, throw exception or return response you need
        if ($receiver->isUploaded() === false) {
            throw new UploadMissingFileException();
        }

        // receive the file
        $save = $receiver->receive();

        // check if the upload has finished (in chunk mode it will send smaller files)
        if ($save->isFinished()) {
            // save the file and return any response you need, current example uses `move` function. If you are
            // not using move, you need to manually delete the file by unlink($save->getFile()->getPathname())
            return $this->saveFile($save->getFile());
        }

        // we are in chunk mode, lets send the current progress
        /** @var AbstractHandler $handler */
        $handler = $save->handler();

        return response()->json([
            "done" => $handler->getPercentageDone(),
        ]);
    }

    /**
     * Saves the file
     *
     * @param UploadedFile $file
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function saveFile(UploadedFile $file)
    {
        $fileName = $this->createFilename($file);
        $filePath = 'app-releases/staging/'.date('Y-m-d').'/';
        $storedPath = $file->storeAs($filePath, $fileName, 'local');

        if ($storedPath === false) {
            abort(500, 'Unable to stage the application package.');
        }

        return response()->json([
            'path' => $filePath,
            'name' => $fileName,
            'mime_type' => 'application/vnd.android.package-archive',
        ]);
    }

    /**
     * Create unique filename for uploaded file
     * @param UploadedFile $file
     * @return string
     */
    protected function createFilename(UploadedFile $file)
    {
        $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'pistat-release';

        return Str::limit($filename, 150, '').'_'.Str::uuid().'.apk';
    }

    private function validateReleaseUpload(Request $request): void
    {
        $name = (string) ($request->input('resumableFilename')
            ?: $request->file('file')?->getClientOriginalName());
        $declaredSize = (int) $request->input(
            'resumableTotalSize',
            $request->file('file')?->getSize() ?: 0
        );

        if ($name === '' || basename($name) !== $name || !preg_match('/^[A-Za-z0-9._-]+\.apk$/i', $name)) {
            abort(422, 'Only APK files with a safe filename are accepted.');
        }

        if ($declaredSize < 1 || $declaredSize > config('app-release.max_file_size')) {
            abort(422, 'The APK exceeds the permitted size.');
        }

        $identifier = (string) $request->input('resumableIdentifier', '');
        if ($identifier !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $identifier)) {
            abort(422, 'Invalid upload identifier.');
        }
    }
}
