<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\System\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CreateFullTenant extends Command
{
    /**
     * 命令的名稱和簽名。
     *
     * @var string
     */
    protected $signature = 'tenants:create-full {id} {name} {--domain=} {--data=}';

    /**
     * 命令的描述。
     *
     * @var string
     */
    protected $description = '創建一個新租戶，並觸發 FastAPI 的初始化 webhook。';

    /**
     * 執行命令。
     */
    public function handle()
    {
        $id = $this->argument('id');
        $name = $this->argument('name');
        $domain = $this->option('domain');
        $data = json_decode($this->option('data') ?? '{}', true); # 解析額外的 JSON 數據

        $fastApiWebhookUrl = env('FASTAPI_TENANT_INIT_WEBHOOK_URL');

        if (!$fastApiWebhookUrl) {
            $this->error("FASTAPI_TENANT_INIT_WEBHOOK_URL 環境變數未設定。請在 .env 中配置它。");
            return Command::FAILURE;
        }

        try {
            DB::beginTransaction();

            $tenantData = [
                'id' => $id,
                'name' => $name,
                'domain' => $domain,
                'data' => json_encode($data),
            ];

            $tenant = Tenant::create($tenantData);
            $this->info("租戶 '{$id}' 已創建於中央資料庫。");

            # 步驟 1: 初始化 Laravel 租戶的資料庫和種子
            tenancy()->initialize($tenant);
            Artisan::call('migrate', ['--path' => 'database/migrations/tenant', '--force' => true, '--database' => 'tenant']);
            # 運行快照表的租戶遷移 (如果決定將其放在租戶資料庫)
            Artisan::call('migrate', ['--path' => 'vendor/spatie/eloquent-snapshot/database/migrations', '--force' => true, '--database' => 'tenant']);
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RolePermissionSeeder', '--force' => true]); # 在租戶資料庫上運行種子
            tenancy()->end();
            $this->info("租戶 '{$id}' 的 Laravel 資料庫和權限已初始化。");

            # 步驟 2: 觸發 FastAPI 的 webhook
            $this->info("正在向 FastAPI 發送初始化 webhook...");
            $response = Http::post($fastApiWebhookUrl, [
                'tenant_id' => $id,
                'tenant_name' => $name,
                'domain' => $domain,
                'data' => $data,
            ]);

            if ($response->successful()) {
                $this->info("FastAPI 初始化 webhook 成功。響應: " . $response->body());
            } else {
                $this->error("FastAPI 初始化 webhook 失敗。狀態: {$response->status()}，響應: " . $response->body());
                throw new \Exception("FastAPI webhook 失敗");
            }

            DB::commit();
            $this->info("租戶 '{$name}' ({$id}) 已成功完全設置。");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("設置租戶 '{$name}' ({$id}) 失敗: " . $e->getMessage());
            Log::error("租戶設置失敗: " . $e->getMessage(), ['tenant_id' => $id]);
            return Command::FAILURE;
        }
    }
}
