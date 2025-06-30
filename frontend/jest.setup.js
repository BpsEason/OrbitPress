// 可選：在每個測試之前配置或設定測試框架。
// 例如，如果您使用 @testing-library/jest-dom 進行自定義匹配器：
import '@testing-library/jest-dom/extend-expect';

// 如果需要，為 Jest 測試模擬環境變數
process.env.NEXT_PUBLIC_API_GATEWAY_URL = 'http://localhost';
process.env.NEXT_PUBLIC_GA_ID = 'UA-TEST-ID'; // 模擬 GA ID

// 模擬 window.gtag 函數
if (typeof window !== 'undefined') {
  window.gtag = jest.fn();
}
