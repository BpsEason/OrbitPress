<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

class CreateRolesPermissionsTable extends Migration
{
    /**
     * 運行遷移。
     *
     * @return void
     */
    public function up()
    {
        $teams = config('permission.teams'); # 對於非多團隊設定，這將為 false
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $tableNames['model_has_roles'];
        $pivotPermission = $tableNames['model_has_permissions'];

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');       # 例如 'edit_articles'
            $table->string('tenant_id', 50)->index(); # 為多租戶添加
            $table->string('guard_name'); # 例如 'web' 或 'api'
            $table->timestamps();

            $table->unique(['name', 'guard_name', 'tenant_id']); # 每個租戶唯一
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) use ($teams, $columnNames) {
            $table->bigIncrements('id');
            $table->string('name');       # 例如 'admin' 或 'super-admin'
            $table->string('tenant_id', 50)->index(); # 為多租戶添加
            $table->string('guard_name'); # 例如 'web' 或 'api'
            $table->timestamps();
            
            $table->unique(['name', 'guard_name', 'tenant_id']); # 每個租戶唯一
        });

        Schema::create($pivotPermission, function (Blueprint $table) use ($tableNames, $columnNames, $teams) {
            $table->unsignedBigInteger(PermissionRegistrar::$pivotPermission);
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->string('model_type');
            
            # 主鍵是 (permission_id, model_id, model_type, tenant_id)
            $table->string('tenant_id', 50)->index(); # 為多租戶添加

            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign(PermissionRegistrar::$pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');

            $table->primary([PermissionRegistrar::$pivotPermission, $columnNames['model_morph_key'], 'model_type', 'tenant_id'], # 修改主鍵
                'model_has_permissions_permission_model_type_tenant_primary'); # 唯一名稱
        });

        Schema::create($pivotRole, function (Blueprint $table) use ($tableNames, $columnNames, $teams) {
            $table->unsignedBigInteger(PermissionRegistrar::$pivotRole);
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->string('model_type');
            
            # 主鍵是 (role_id, model_id, model_type, tenant_id)
            $table->string('tenant_id', 50)->index(); # 為多租戶添加

            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign(PermissionRegistrar::$pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->onDelete('cascade');

            $table->primary([PermissionRegistrar::$pivotRole, $columnNames['model_morph_key'], 'model_type', 'tenant_id'], # 修改主鍵
                'model_has_roles_role_model_type_tenant_primary'); # 唯一名稱
        });

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * 回滾遷移。
     *
     * @return void
     */
    public function down()
    {
        $tableNames = config('permission.table_names');

        Schema::dropIfExists($tableNames['role_has_permissions']);
        Schema::dropIfExists($tableNames['model_has_roles']);
        Schema::dropIfExists($tableNames['permissions']);
        Schema::dropIfExists($tableNames['roles']);
    }
}
