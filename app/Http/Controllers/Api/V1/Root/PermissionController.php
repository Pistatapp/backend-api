<?php

namespace App\Http\Controllers\Api\V1\Root;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Request;
use App\Http\Requests\StorePermissionRequest;
use App\Http\Requests\UpdatePermissionRequest;

class PermissionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return PermissionResource::collection(Permission::simplePaginate(10));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePermissionRequest $request)
    {
        $this->updateFaJson($request->name, $request->persian_name);

        $permission = Permission::create([
            'name' => $request->name,
            'guard_name' => $request->guard_name,
        ]);

        return new PermissionResource($permission);
    }

    /**
     * Display the specified resource.
     */
    public function show(Permission $permission)
    {
        return new PermissionResource($permission);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePermissionRequest $request, Permission $permission)
    {
        $this->updateFaJson($permission->name, $request->persian_name);

        $permission->update([
            'name' => $request->name,
            'guard_name' => $request->guard_name,
        ]);

        return new PermissionResource($permission);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Permission $permission)
    {
        $permission->delete();

        $this->removeFromFaJson($permission->name);

        return response()->noContent();
    }

    /**
     * Update the Persian name of the permission in the fa.json file.
     */
    private function updateFaJson(string $name, string $persianName): void
    {
        $faJson = json_decode(file_get_contents(lang_path('fa.json')), true);

        $faJson[$name] = $persianName;

        file_put_contents(lang_path('fa.json'), json_encode($faJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Remove the permission from the fa.json file.
     */
    private function removeFromFaJson(string $name): void
    {
        $faJson = json_decode(file_get_contents(lang_path('fa.json')), true);

        unset($faJson[$name]);

        file_put_contents(lang_path('fa.json'), json_encode($faJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
