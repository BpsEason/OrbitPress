<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant\Article;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse; // For CSV export

class ReportController extends Controller
{
    /**
     * 獲取按狀態分組的文章數量報表。
     */
    public function articlesByStatus(Request $request)
    {
        if (Gate::denies('access_reports')) {
            return response()->json(['error' => '未經授權訪問報表'], 403);
        }

        $articlesByStatus = Article::select('status', DB::raw('count(*) as total'))
                                   ->groupBy('status')
                                   ->get();

        return response()->json($articlesByStatus);
    }

    /**
     * 獲取租戶內用戶活動的報告。
     */
    public function userActivity(Request $request)
    {
        if (Gate::denies('access_reports')) {
            return response()->json(['error' => '未經授權訪問報表'], 403);
        }

        // 獲取當前租戶的活動日誌 (假設活動日誌是租戶範圍的)
        // 如果 Activity Log 表在中央資料庫，您可能需要按 tenant_id 過濾
        // 注意：spatie/laravel-activitylog 預設在 default connection，如果需要租戶級別，
        // 您可能需要為 activity_log 表創建租戶遷移並修改 config/activitylog.php
        $userActivity = Activity::where('log_name', 'default') // 預設日誌名稱
                                ->with('causer') // 載入操作者關係
                                ->latest()
                                ->limit(50) // 限制為最近的 50 條活動
                                ->get()
                                ->map(function ($activity) {
                                    return [
                                        'description' => $activity->description,
                                        'event' => $activity->event,
                                        'causer' => $activity->causer ? $activity->causer->name : '系統',
                                        'subject_type' => class_basename($activity->subject_type),
                                        'subject_id' => $activity->subject_id,
                                        'time' => $activity->created_at->diffForHumans(),
                                    ];
                                });

        return response()->json($userActivity);
    }

    /**
     * 導出報表為 CSV 或 PDF (概念性，需要額外的函式庫和實現)。
     * 這裡提供一個 CSV 導出的基本範例。
     */
    public function exportReport(Request $request, string $reportType)
    {
        if (Gate::denies('access_reports')) {
            return response()->json(['error' => '未經授權導出報表'], 403);
        }

        $filename = "report_{$reportType}_" . now()->format('Ymd_His') . ".csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($reportType, $request) {
            $file = fopen('php://output', 'w');
            fputs($file, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF))); // 添加 BOM 以確保 Excel 正確顯示中文

            switch ($reportType) {
                case 'articles-by-status':
                    fputcsv($file, ['Status', 'Total Articles']);
                    $data = $this->articlesByStatus($request)->getData();
                    foreach ($data as $row) {
                        fputcsv($file, [$row->status, $row->total]);
                    }
                    break;
                case 'user-activity':
                    fputcsv($file, ['Description', 'Event', 'Causer', 'Subject Type', 'Subject ID', 'Time']);
                    $data = $this->userActivity($request)->getData();
                    foreach ($data as $row) {
                        fputcsv($file, [
                            $row->description,
                            $row->event,
                            $row->causer,
                            $row->subject_type,
                            $row->subject_id,
                            $row->time
                        ]);
                    }
                    break;
                default:
                    fputcsv($file, ['Error', 'Invalid report type']);
                    break;
            }
            fclose($file);
        };

        return new StreamedResponse($callback, 200, $headers);
    }
}
