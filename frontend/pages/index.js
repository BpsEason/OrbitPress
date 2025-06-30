import Head from 'next/head';
import { useState, useEffect } from 'react';
import { event as gaEvent } from '../pages/_app'; // 導入事件發送函數

export default function Home() {
  const [articles, setArticles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [tenantId, setTenantId] = useState('cw'); // 預設租戶 ID
  const [newArticleTitle, setNewArticleTitle] = useState('');
  const [newArticleContent, setNewArticleContent] = useState('');
  const [notification, setNotification] = useState('');
  const [token, setToken] = useState(''); // 新增狀態來保存 JWT Token

  const apiGatewayUrl = process.env.NEXT_PUBLIC_API_GATEWAY_URL || 'http://localhost'; // FastAPI 閘道 URL

  useEffect(() => {
    const authenticateAndFetch = async () => {
      await fetchToken();
      // 在 Token 獲取後，才嘗試獲取文章
      // 這裡需要依賴 token 狀態更新來觸發 fetchArticles
      // 可以考慮將 fetchArticles 放在一個單獨的 useEffect，並將 token 作為其依賴項
    };
    authenticateAndFetch();
  }, [tenantId]); // 當 tenantId 改變時重新認證

  // 獨立的 useEffect 監聽 token 變化，以觸發文章獲取
  useEffect(() => {
    if (token) {
      fetchArticles();
    }
  }, [token, tenantId]); // 確保當 token 或 tenantId 改變時都觸發

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
        throw new Error(`認證失敗: ${response.status} - ${errorData.error || response.statusText}`);
      }
      const data = await response.json();
      setToken(data.token);
      setNotification('已成功獲取認證 Token。');
    } catch (e) {
      console.error("獲取 Token 失敗:", e);
      setError(`無法獲取認證 Token: ${e.message}`);
      setNotification(`無法獲取認證 Token: ${e.message}`);
      setToken(''); // 清除 token
    }
  };


  const fetchArticles = async () => {
    setLoading(true);
    setError(null);
    if (!token) {
      setError('未經認證。請先獲取 Token。');
      setLoading(false);
      return;
    }
    try {
      const response = await fetch(`${apiGatewayUrl}/tenant-api/articles`, {
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
      setNotification('未經認證。請先獲取 Token。');
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
          title: newArticleTitle,
          content: newArticleContent,
          status: 'draft',
        }),
      });

      if (!response.ok) {
        const errorData = await response.json();
        throw new Error(`創建文章失敗: ${response.status} - ${errorData.error || response.statusText}`);
      }

      setNotification('文章創建成功!');
      setNewArticleTitle('');
      setNewArticleContent('');
      fetchArticles(); // 刷新列表

      // 發送 GA 事件: 文章創建
      gaEvent({
        action: 'create_article',
        category: 'Article Management',
        label: `Tenant: ${tenantId}`,
        value: 1,
      });

    } catch (e) {
      console.error("創建文章時發生錯誤:", e);
      setNotification(`創建文章時發生錯誤: ${e.message}`);
    }
  };

  const publishArticle = async (articleId) => {
    setNotification('');
    if (!token) {
      setNotification('未經認證。請先獲取 Token。');
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
        throw new Error(`發布文章失敗: ${response.status} - ${errorData.error || response.statusText}`);
      }

      setNotification('文章發布成功!');
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
      setNotification(`發布文章時發生錯誤: ${e.message}`);
    }
  };

  return (
    <div className="container">
      <Head>
        <title>OrbitPress CMS - {tenantId.toUpperCase()}</title>
        <link rel="icon" href="/favicon.ico" />
      </Head>

      <main>
        <h1>OrbitPress CMS - {tenantId.toUpperCase()}</h1>

        <div className="tenant-switcher">
          <label htmlFor="tenant-select">選擇租戶:</label>
          <select id="tenant-select" value={tenantId} onChange={(e) => setTenantId(e.target.value)}>
            <option value="cw">天下雜誌 (cw)</option>
            <option value="health">康健雜誌 (health)</option>
            <option value="parenting">親子天下 (parenting)</option>
          </select>
        </div>

        {notification && <p className="notification">{notification}</p>}
        {error && <p className="error">錯誤: {error}</p>}

        <section className="article-form">
          <h2>創建新文章</h2>
          <form onSubmit={createArticle}>
            <input
              type="text"
              placeholder="文章標題"
              value={newArticleTitle}
              onChange={(e) => setNewArticleTitle(e.target.value)}
              required
            />
            <textarea
              placeholder="文章內容"
              value={newArticleContent}
              onChange={(e) => setNewArticleContent(e.target.value)}
              rows="5"
              required
            ></textarea>
            <button type="submit" disabled={!token}>創建文章</button>
          </form>
        </section>

        <section className="article-list">
          <h2>文章列表</h2>
          {loading && <p>正在加載文章...</p>}
          {!loading && !error && articles.length === 0 && <p>此租戶沒有找到文章。</p>}
          <ul>
            {articles.map((article) => (
              <li key={article.id}>
                <h3>{article.title} <span className={`status ${article.status}`}>{article.status.toUpperCase()}</span></h3>
                <p>{article.content.substring(0, 150)}...</p>
                {article.status === 'draft' && (
                  <button onClick={() => publishArticle(article.id)} disabled={!token}>發布</button>
                )}
                {/* 添加編輯/刪除按鈕 (需要更多的後端 API 端點和權限) */}
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

        .tenant-switcher {
          text-align: center;
          margin-bottom: 30px;
          padding: 15px;
          background-color: #e9eff6;
          border-radius: 8px;
        }
        .tenant-switcher label {
          margin-right: 10px;
          font-weight: bold;
        }
        .tenant-switcher select {
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
        .article-list li button {
            align-self: flex-end;
            margin-top: 10px;
            background-color: #28a745; /* 發布按鈕為綠色 */
        }
        .article-list li button:hover {
            background-color: #218838;
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
