<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class CreatePermisoGestionarClima extends Migration
{
    /**
     * Las estaciones y los datos de clima son compartidos por todos los ensayos:
     * importarlos, re-descargarlos de la API o corregirlos en la revision de calidad
     * deja de depender de "upload_files" (que tienen los investigadores). El permiso
     * no se asigna a ningun rol: lo tiene el administrador (Gate::before) y puede
     * darse luego a un responsable de clima.
     */
    public function up()
    {
        Permission::firstOrCreate(['name' => 'gestionar_clima', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down()
    {
        Permission::where('name', 'gestionar_clima')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
