import '../styles/globals.css';
import { useEffect } from 'react';
import { useRouter } from 'next/router';
import { appWithTranslation } from 'next-i18next'; // Import appWithTranslation

// 假設您的 GA 追蹤 ID 在環境變數中
const GA_TRACKING_ID = process.env.NEXT_PUBLIC_GA_ID;

// 輔助函數用於發送頁面瀏覽事件
function pageview(url) {
  if (GA_TRACKING_ID && window.gtag) {
    window.gtag('config', GA_TRACKING_ID, {
      page_path: url,
    });
  }
}

// 輔助函數用於發送自定義事件
export function event({ action, category, label, value }) {
  if (GA_TRACKING_ID && window.gtag) {
    window.gtag('event', action, {
      event_category: category,
      event_label: label,
      value: value,
    });
  }
}

function MyApp({ Component, pageProps }) {
  const router = useRouter();

  useEffect(() => {
    if (!GA_TRACKING_ID) {
      console.warn("NEXT_PUBLIC_GA_ID 未設定。Google Analytics 追蹤已禁用。");
      return;
    }

    // 檢查 gtag 是否已經存在，如果沒有，則手動添加
    if (!window.gtag) {
        const script = document.createElement('script');
        script.src = `https://www.googletagmanager.com/gtag/js?id=${GA_TRACKING_ID}`;
        script.async = true;
        document.head.appendChild(script);

        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        window.gtag = gtag; // 將 gtag 函數掛載到 window 上
        gtag('js', new Date());
        gtag('config', GA_TRACKING_ID, {
          send_page_view: false, // 我們將手動發送頁面瀏覽事件
        });
    }


    // 監聽路由變化，發送頁面瀏覽事件
    const handleRouteChange = (url) => {
      pageview(url);
    };

    router.events.on('routeChangeComplete', handleRouteChange);

    return () => {
      router.events.off('routeChangeComplete', handleRouteChange);
    };
  }, [router.events]);

  return <Component {...pageProps} />;
}

// Wrap your app with appWithTranslation for i18n support
export default appWithTranslation(MyApp);
