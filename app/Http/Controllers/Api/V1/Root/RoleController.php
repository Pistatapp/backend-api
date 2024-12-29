<?php

namespace App\Http\Controllers\Api\V1\Root;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Request;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return RoleResource::collection(Role::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRoleRequest $request)
    {
        $this->updateFaJson($request->name, $request->persian_name);

        $role = Role::create([
            'name' => $request->name,
            'guard_name' => $request->guard_name,
        ]);

        return new RoleResource($role);
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role)
    {
        return new RoleResource($role->load('permissions'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRoleRequest $request, Role $role)
    {
        $this->updateFaJson($role->name, $request->persian_name);

        $role->update([
            'name' => $request->name,
            'guard_name' => $request->guard_name,
        ]);

        return new RoleResource($role);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        $this->removeFromFaJson($role->name);

        $role->delete();

        return response()->noContent();
    }

    /**
     * Update the fa.json file with the given role.
     */
    private function updateFaJson($roleName, $persianName)
    {
        $faRoles = json_decode(file_get_contents(lang_path('fa.json')), true);
        $faRoles[$roleName] = $persianName;
        file_put_contents(lang_path('fa.json'), json_encode($faRoles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * Remove the role from the fa.json file.
     */
    private function removeFromFaJson($roleName)
    {
        $faRoles = json_decode(file_get_contents(lang_path('fa.json')), true);
        unset($faRoles[$roleName]);
        file_put_contents(lang_path('fa.json'), json_encode($faRoles, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
