<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\GenericMail; # 我們將創建此 Mail 類
use Illuminate\Support\Facades\Http; # 用於 Firebase HTTP 請求
# use Kreait\Firebase\Factory; # 導入 Firebase Factory
# use Kreait\Firebase\Messaging\CloudMessage; # 導入 CloudMessage
# use Kreait\Firebase\Exception\FirebaseException;
# use Kreait\Firebase\Exception\MessagingException;

class NotificationService
{
    /**
     * 發送電子郵件。
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return void
     */
    public function sendEmail(string $to, string $subject, string $body): void
    {
        try {
            Mail::to($to)->send(new GenericMail($subject, $body));
            Log::info("電子郵件已發送至 {$to}，主題：{$subject}");
        } catch (\Exception $e) {
            Log::error("發送電子郵件至 {$to} 失敗：" . $e->getMessage());
        }
    }
    
    /**
     * 發送 Firebase 推送通知。
     * 這是一個概念性實現。實際實現需要 Firebase SDK 設定。
     *
     * @param string|array $deviceTokens 單個 token 或 token 陣列
     * @param string $title
     * @param string $body
     * @param array $data 可選的資料負載
     * @return void
     */
    public function sendFirebasePushNotification($deviceTokens, string $title, string $body, array $data = []): void
    {
        $serverKey = env('FIREBASE_SERVER_KEY');
        if (!$serverKey) {
            Log::warning("FIREBASE_SERVER_KEY 未設定。Firebase 推送通知已跳過。");
            return;
        }

        # 這裡需要設定 Firebase 服務帳戶憑證。
        # 最好的做法是將 service-account.json 內容存儲在環境變數中，然後從中解析。
        # 為簡化，我們將使用純粹的 HTTP 請求，但推薦使用 Firebase Admin SDK。
        $url = 'https://fcm.googleapis.com/fcm/send';
        $headers = [
            'Authorization' => 'key=' . $serverKey,
            'Content-Type' => 'application/json',
        ];

        $payload = [
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
        ];

        if (is_array($deviceTokens)) {
            $payload['registration_ids'] = $deviceTokens; # 用於多個 token
        } else {
            $payload['to'] = $deviceTokens; # 用於單個 token
        }

        try {
            $response = Http::withHeaders($headers)->post($url, $payload);
            
            if ($response->successful()) {
                Log::info("Firebase 推送通知發送成功：" . $response->body());
            } else {
                Log::error("發送 Firebase 推送通知失敗：" . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("發送 Firebase 推送通知時發生錯誤：" . $e->getMessage());
        }
    }
}
