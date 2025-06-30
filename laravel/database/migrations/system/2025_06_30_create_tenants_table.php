<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 運行遷移。
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary(); # 租戶 ID (例如 'cw', 'health')
            $table->string('name')->nullable(); # 租戶的顯示名稱
            $table->string('domain')->unique()->nullable(); # 可選：用於基於域名的租戶
            $table->json('data')->nullable(); # 存儲額外的租戶特定配置
            $table->timestamps();
        });
    }

    /**
     * 回滾遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
