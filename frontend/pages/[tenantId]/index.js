import Head from 'next/head';
import { useState, useEffect } from 'react';
import { event as gaEvent } from '../_app'; // 導入事件發送函數
import { useTranslation } from 'next-i18next'; // Import useTranslation
import { serverSideTranslations } from 'next-i18next/serverSideTranslations'; // Import serverSideTranslations

// This function runs on each request on the server side
export async function getServerSideProps({ params, req, locale }) {
  const tenantId = params.tenantId; // Get tenantId from dynamic route
  const host = req.headers.host;

  // In a real application, you'd fetch tenant-specific data from your backend
  // based on tenantId and potentially the host for domain-based routing.
  // For this example, we'll use mock data.
  const tenantDetails = {
    'cw': { name: '天下雜誌', seoTitle: '天下雜誌 - 洞察世界，掌握趨勢', seoDescription: '深度報導、財經分析、人文關懷，盡在天下雜誌。' },
    'health': { name: '康健雜誌', seoTitle: '康健雜誌 - 活得健康，身心富足', seoDescription: '健康飲食、運動健身、心靈成長，康健雜誌伴您健康生活。' },
    'parenting': { name: '親子天下', seoTitle: '親子天下 - 陪伴孩子，成為更好的大人', seoDescription: '育兒教養、親子關係、教育議題，親子天下與您一同成長。' },
  };

  const currentTenantData = tenantDetails[tenantId] || {
    name: '未知租戶',
    seoTitle: 'OrbitPress CMS - 未知租戶',
    seoDescription: '這個租戶不存在。',
  };

  return {
    props: {
      tenantId,
      tenantData: currentTenantData,
      ...(await serverSideTranslations(locale, ['common'])), // Load translations for the current locale
    },
  };
}

export default function TenantHome({ tenantId, tenantData }) {
  const { t, i18n } = useTranslation('common'); // Initialize translation hook
  const [articles, setArticles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [newArticleTitle, setNewArticleTitle] = useState('');
  const [newArticleContent, setNewArticleContent] = useState('');
  const [notification, setNotification] = useState('');
  const [token, setToken] = useState(''); // 新增狀態來保存 JWT Token
  const [currentLocale, setCurrentLocale] = useState(i18n.language); // Initialize with current i18n locale

  const apiGatewayUrl = process.env.NEXT_PUBLIC_API_GATEWAY_URL || 'http://localhost'; // FastAPI 閘道 URL

  // Effect to update i18n language
  useEffect(() => {
    if (i18n.language !== currentLocale) {
      i18n.changeLanguage(currentLocale);
    }
  }, [currentLocale, i18n]);


  useEffect(() => {
    const authenticateAndFetch = async () => {
      await fetchToken();
    };
    authenticateAndFetch();
  }, [tenantId]); // 當 tenantId 改變時重新認證

  // 獨立的 useEffect 監聽 token 變化，以觸發文章獲取
  useEffect(() => {
    if (token) {
      fetchArticles();
    }
  }, [token, tenantId, currentLocale]); // 確保當 token、tenantId 或語言改變時都觸發

  const fetchToken = async () => {
    setNotification('');
    try {
      const response = await fetch(`${apiGatewayUrl}/api/auth/token`, {
        method: 'POST',
        headers: { 
          'Content-Type': 'application/json', 
          'X-Tenant-ID': tenantId 
        },
        body: JSON.stringify({
          email: `chief_editor_${tenantId}@example.com`, // 使用預設總編輯用戶
          password: 'password', // 預設密碼
          device_name: 'web_frontend',
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`${t('authentication_fail')} ${response.status} - ${errorData.error || response.statusText}`);
      }
      const data = await response.json();
      setToken(data.token);
      setNotification(t('authentication_success'));
    } catch (e) {
      console.error("獲取 Token 失敗:", e);
      setError(`${t('authentication_fail')} ${e.message}`);
      setNotification(`${t('authentication_fail')} ${e.message}`);
      setToken(''); // 清除 token
    }
  };


  const fetchArticles = async () => {
    setLoading(true);
    setError(null);
    if (!token) {
      setError(t('tenant_not_authenticated'));
      setLoading(false);
      return;
    }
    try {
      // Pass the current locale to the backend if your API supports it for filtering/sorting
      const response = await fetch(`${apiGatewayUrl}/tenant-api/articles?locale=${currentLocale}`, {
        headers: {
          'X-Tenant-ID': tenantId,
          'Authorization': `Bearer ${token}`, // 使用真實的 Token
          'Content-Type': 'application/json',
        },
      });

      if (!response.ok) {
        throw new Error(`HTTP 錯誤! 狀態: ${response.status} - ${response.statusText}`);
      }
      const data = await response.json();
      setArticles(data);
    } catch (e) {
      console.error("獲取文章失敗:", e);
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  const createArticle = async (e) => {
    e.preventDefault();
    setNotification('');
    if (!token) {
      setNotification(t('tenant_not_authenticated'));
      return;
    }
    try {
      const response = await fetch(`${apiGatewayUrl}/tenant-api/articles`, {
        method: 'POST',
        headers: {
          'X-Tenant-ID': tenantId,
          'Authorization': `Bearer ${token}`, // 使用真實的 Token
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          title: { [currentLocale]: newArticleTitle }, // Send as translated object
          content: { [currentLocale]: newArticleContent },
          status: 'draft',
          locale: currentLocale, // Send the selected locale
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`${t('create_article_fail')} ${response.status} - ${errorData.error || response.statusText}`);
      }

      setNotification(t('create_article_success'));
      setNewArticleTitle('');
      setNewArticleContent('');
      fetchArticles(); // 刷新列表

      // 發送 GA 事件: 文章創建
      gaEvent({
        action: 'create_article',
        category: 'Article Management',
        label: `Tenant: ${tenantId}, Locale: ${currentLocale}`,
        value: 1,
      });

    } catch (e) {
      console.error("創建文章時發生錯誤:", e);
      setNotification(`${t('create_article_fail')} ${e.message}`);
    }
  };

  const submitForReview = async (articleId) => {
    setNotification('');
    if (!token) {
      setNotification(t('tenant_not_authenticated'));
      return;
    }
    try {
      const response = await fetch(`${apiGatewayUrl}/tenant-api/articles/${articleId}/submit-for-review`, {
        method: 'POST',
        headers: {
          'X-Tenant-ID': tenantId,
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`${t('submit_review_fail')} ${response.status} - ${errorData.error || response.statusText}`);
      }

      setNotification(t('submit_review_success'));
      fetchArticles(); // Refresh list

      gaEvent({
        action: 'submit_for_review',
        category: 'Article Workflow',
        label: `Article ID: ${articleId}, Tenant: ${tenantId}`,
        value: 1,
      });

    } catch (e) {
      console.error("提交審核時發生錯誤:", e);
      setNotification(`${t('submit_review_fail')} ${e.message}`);
    }
  };


  const publishArticle = async (articleId) => {
    setNotification('');
    if (!token) {
      setNotification(t('tenant_not_authenticated'));
      return;
    }
    try {
      const response = await fetch(`${apiGatewayUrl}/tenant-api/articles/${articleId}/publish`, {
        method: 'POST',
        headers: {
          'X-Tenant-ID': tenantId,
          'Authorization': `Bearer ${token}`, // 使用真實的 Token
          'Content-Type': 'application/json',
        },
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`${t('publish_article_fail')} ${response.status} - ${errorData.error || response.statusText}`);
      }

      setNotification(t('publish_article_success'));
      fetchArticles(); // 刷新列表

      // 發送 GA 事件: 文章發布
      gaEvent({
        action: 'publish_article',
        category: 'Article Management',
        label: `Article ID: ${articleId}, Tenant: ${tenantId}`,
        value: 1,
      });

    } catch (e) {
      console.error("發布文章時發生錯誤:", e);
      setNotification(`${t('publish_article_fail')} ${e.message}`);
    }
  };

  const approveArticle = async (articleId) => {
    setNotification('');
    if (!token) {
      setNotification(t('tenant_not_authenticated'));
      return;
    }
    try {
      const response = await fetch(`${apiGatewayUrl}/tenant-api/articles/${articleId}/approve`, {
        method: 'POST',
        headers: {
          'X-Tenant-ID': tenantId,
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`${t('approve_article_fail')} ${response.status} - ${errorData.error || response.statusText}`);
      }

      setNotification(t('approve_article_success'));
      fetchArticles();

      gaEvent({
        action: 'approve_article',
        category: 'Article Workflow',
        label: `Article ID: ${articleId}, Tenant: ${tenantId}`,
        value: 1,
      });

    } catch (e) {
      console.error("批准文章時發生錯誤:", e);
      setNotification(`${t('approve_article_fail')} ${e.message}`);
    }
  };

  const rejectArticle = async (articleId) => {
    setNotification('');
    if (!token) {
      setNotification(t('tenant_not_authenticated'));
      return;
    }
    try {
      const response = await fetch(`${apiGatewayUrl}/tenant-api/articles/${articleId}/reject`, {
        method: 'POST',
        headers: {
          'X-Tenant-ID': tenantId,
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`${t('reject_article_fail')} ${response.status} - ${errorData.error || response.statusText}`);
      }

      setNotification(t('reject_article_success'));
      fetchArticles();

      gaEvent({
        action: 'reject_article',
        category: 'Article Workflow',
        label: `Article ID: ${articleId}, Tenant: ${tenantId}`,
        value: 1,
      });

    } catch (e) {
      console.error("拒絕文章時發生錯誤:", e);
      setNotification(`${t('reject_article_fail')} ${e.message}`);
    }
  };


  return (
    <div className="container">
      <Head>
        <title>{tenantData.seoTitle}</title>
        <meta name="description" content={tenantData.seoDescription} />
        <link rel="icon" href="/favicon.ico" />
      </Head>

      <main>
        <h1>{t('welcome_message')} - {tenantData.name} ({tenantId.toUpperCase()})</h1>

        <div className="tenant-switcher">
          <label htmlFor="tenant-select">{t('select_tenant')}:</label>
          <select id="tenant-select" value={tenantId} onChange={(e) => {
            setTenantId(e.target.value);
            // 當租戶改變時，導航到新的租戶子路由
            router.push(`/${e.target.value}`);
          }}>
            <option value="cw">天下雜誌 (cw)</option>
            <option value="health">康健雜誌 (health)</option>
            <option value="parenting">親子天下 (parenting)</option>
          </select>
        </div>

        <div className="locale-switcher">
          <label htmlFor="locale-select">{t('select_locale')}:</label>
          <select id="locale-select" value={currentLocale} onChange={(e) => setCurrentLocale(e.target.value)}>
            <option value="zh_TW">繁體中文</option>
            <option value="zh_CN">簡體中文</option>
            <option value="en">English</option>
          </select>
        </div>

        {notification && <p className="notification">{notification}</p>}
        {error && <p className="error">{t('error')}: {error}</p>}

        <section className="article-form">
          <h2>{t('create_new_article')}</h2>
          <form onSubmit={createArticle}>
            <input
              type="text"
              placeholder={t('article_title')}
              value={newArticleTitle}
              onChange={(e) => setNewArticleTitle(e.target.value)}
              required
            />
            <textarea
              placeholder={t('article_content')}
              value={newArticleContent}
              onChange={(e) => setNewArticleContent(e.target.value)}
              rows="5"
              required
            ></textarea>
            <button type="submit" disabled={!token}>{t('create_article_button')}</button>
          </form>
        </section>

        <section className="article-list">
          <h2>{t('article_list')}</h2>
          {loading && <p>{t('loading_articles')}</p>}
          {!loading && !error && articles.length === 0 && <p>{t('no_articles_found')}</p>}
          <ul>
            {articles.map((article) => (
              <li key={article.id}>
                <h3>
                  {article.title ? (article.title[currentLocale] || article.title.en || 'No Title') : 'No Title'}
                  <span className={`status ${article.status}`}>
                    {article.status ? article.status.toUpperCase() : 'UNKNOWN'}
                  </span>
                </h3>
                <p>{article.content ? (article.content[currentLocale] || article.content.en || 'No Content').substring(0, 150) : 'No Content'}...</p>
                <div className="article-actions">
                  {article.status === 'draft' && (
                    <button onClick={() => submitForReview(article.id)} disabled={!token}>
                      {t('submit_for_review_button')}
                    </button>
                  )}
                  {article.status === 'review' && (
                    <>
                      <button onClick={() => approveArticle(article.id)} disabled={!token} className="approve-button">
                        {t('approve_button')}
                      </button>
                      <button onClick={() => rejectArticle(article.id)} disabled={!token} className="reject-button">
                        {t('reject_button')}
                      </button>
                    </>
                  )}
                  {article.status === 'draft' || article.status === 'review' && (
                    <button onClick={() => publishArticle(article.id)} disabled={!token} className="publish-button">
                        {t('publish_button')} (Direct)
                    </button>
                  )}
                  {/* 添加編輯/刪除按鈕 (需要更多的後端 API 端點和權限) */}
                </div>
              </li>
            ))}
          </ul>
        </section>
      </main>

      <style jsx global>{`
        html,
        body {
          padding: 0;
          margin: 0;
          font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Oxygen,
            Ubuntu, Cantarell, Fira Sans, Droid Sans, Helvetica Neue, sans-serif;
          background-color: #f0f2f5;
          color: #333;
        }

        .container {
          max-width: 900px;
          margin: 40px auto;
          padding: 20px;
          background-color: #fff;
          border-radius: 8px;
          box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        h1, h2 {
          color: #2c3e50;
          text-align: center;
          margin-bottom: 25px;
        }

        .tenant-switcher, .locale-switcher {
          text-align: center;
          margin-bottom: 30px;
          padding: 15px;
          background-color: #e9eff6;
          border-radius: 8px;
        }
        .tenant-switcher label, .locale-switcher label {
          margin-right: 10px;
          font-weight: bold;
        }
        .tenant-switcher select, .locale-switcher select {
          padding: 8px 12px;
          border-radius: 5px;
          border: 1px solid #ccc;
          font-size: 1rem;
          background-color: #f8f8f8;
        }

        .notification {
          background-color: #d4edda;
          color: #155724;
          border: 1px solid #c3e6cb;
          padding: 10px;
          border-radius: 5px;
          margin-bottom: 20px;
          text-align: center;
        }
        .error {
          background-color: #f8d7da;
          color: #721c24;
          border: 1px solid #f5c6cb;
        }

        .article-form, .article-list {
          margin-bottom: 40px;
          padding: 20px;
          border: 1px solid #eee;
          border-radius: 8px;
          background-color: #fdfdfd;
        }

        .article-form h2, .article-list h2 {
            margin-top: 0;
            text-align: left;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .article-form input[type="text"],
        .article-form textarea {
          width: calc(100% - 20px);
          padding: 10px;
          margin-bottom: 15px;
          border: 1px solid #ddd;
          border-radius: 5px;
          font-size: 1rem;
        }

        .article-form button, .article-list button {
          background-color: #0070f3;
          color: white;
          padding: 10px 20px;
          border: none;
          border-radius: 5px;
          cursor: pointer;
          font-size: 1rem;
          transition: background-color 0.2s ease-in-out;
        }
        .article-form button:hover, .article-list button:hover {
          background-color: #005bb5;
        }
        .article-form button:disabled, .article-list button:disabled {
          background-color: #cccccc;
          cursor: not-allowed;
        }

        .article-list ul {
          list-style: none;
          padding: 0;
        }
        .article-list li {
          background-color: #ffffff;
          border: 1px solid #e0e0e0;
          border-radius: 8px;
          padding: 15px 20px;
          margin-bottom: 15px;
          display: flex;
          flex-direction: column;
          gap: 10px;
          box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        .article-list li h3 {
          margin: 0;
          color: #34495e;
          display: flex;
          align-items: center;
          justify-content: space-between;
          font-size: 1.2rem;
        }
        .article-list li p {
          color: #555;
          line-height: 1.6;
          margin: 0;
        }
        .article-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .article-list li button {
            background-color: #007bff; /* 預設藍色 */
        }
        .article-list li button.publish-button {
            background-color: #28a745; /* 發布按鈕為綠色 */
        }
        .article-list li button.approve-button {
            background-color: #17a2b8; /* 批准按鈕為青色 */
        }
        .article-list li button.reject-button {
            background-color: #dc3545; /* 拒絕按鈕為紅色 */
        }
        .article-list li button:hover.publish-button {
            background-color: #218838;
        }
        .article-list li button:hover.approve-button {
            background-color: #138496;
        }
        .article-list li button:hover.reject-button {
            background-color: #c82333;
        }

        .status {
            font-size: 0.8em;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: bold;
            color: white;
            margin-left: 10px;
        }
        .status.draft {
            background-color: #ffc107; /* 黃色 */
            color: #343a40;
        }
        .status.review {
            background-color: #17a2b8; /* 青色 */
        }
        .status.published {
            background-color: #28a745; /* 綠色 */
        }
      `}</style>
    </div>
  );
}
