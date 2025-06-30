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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->json('title'); # 更改為 JSON 欄位以支援多語系
            $table->json('content'); # 更改為 JSON 欄位以支援多語系
            $table->string('status')->default('draft'); # draft, review, published
            $table->json('metadata')->nullable();
            $table->string('locale', 10)->default('zh_TW'); # 新增預設語言環境
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        
        # 為租戶特定用戶添加用戶表 (如果尚未由租戶包創建)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * 回滾遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
        Schema::dropIfExists('users');
    }
};
