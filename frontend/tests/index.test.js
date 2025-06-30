import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Home from '../pages/index';

// 模擬全局 fetch
global.fetch = jest.fn();

describe('首頁', () => {
  beforeEach(() => {
    // 在每個測試之前重置模擬
    fetch.mockClear();
    if (window.gtag) {
      window.gtag.mockClear(); // 清除 gtag 模擬
    }
  });

  it('渲染主標題', () => {
    fetch.mockImplementationOnce(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ token: 'mock_jwt_token' }), // 模擬 token 獲取成功
      })
    );
    fetch.mockImplementationOnce(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve([]),
      })
    );
    render(<Home />);
    expect(screen.getByRole('heading', { name: /OrbitPress CMS - CW/i })).toBeInTheDocument();
  });

  it('加載並顯示文章', async () => {
    const mockArticles = [
      { id: 1, title: '文章 1', content: '文章 1 的內容。', status: 'published' },
      { id: 2, title: '文章 2', content: '文章 2 的內容。', status: 'draft' },
    ];

    // 模擬 token 獲取
    fetch.mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ token: 'mock_jwt_token' }) }));
    // 模擬文章獲取
    fetch.mockImplementationOnce(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve(mockArticles),
      })
    );

    render(<Home />);

    expect(screen.getByText(/正在加載文章.../i)).toBeInTheDocument();

    await waitFor(() => {
      expect(screen.getByText(/文章 1/i)).toBeInTheDocument();
      expect(screen.getByText(/文章 2/i)).toBeInTheDocument();
      expect(screen.queryByText(/正在加載文章.../i)).not.toBeInTheDocument();
    });
  });

  it('允許創建新文章並觸發 GA 事件', async () => {
    const user = userEvent.setup();
    // 模擬 token 獲取
    fetch.mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ token: 'mock_jwt_token' }) }));
    // 模擬初始文章獲取
    fetch.mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve([]) }));
    // 模擬創建文章
    fetch.mockImplementationOnce(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ id: 3, title: '新文章', content: '新內容。', status: 'draft' }),
      })
    );
    // 模擬創建後刷新文章
    fetch.mockImplementationOnce(() =>
      Promise.resolve({
        ok: true,
        json: () => Promise.resolve([{ id: 3, title: '新文章', content: '新內容。', status: 'draft' }]),
      })
    );

    render(<Home />);

    await waitFor(() => expect(screen.getByText(/此租戶沒有找到文章。/i)).toBeInTheDocument());

    const titleInput = screen.getByPlaceholderText(/文章標題/i);
    const contentTextarea = screen.getByPlaceholderText(/文章內容/i);
    const createButton = screen.getByRole('button', { name: /創建文章/i });

    await user.type(titleInput, '我的新文章');
    await user.type(contentTextarea, '這是我的新文章的內容。');
    await user.click(createButton);

    await waitFor(() => {
      expect(fetch).toHaveBeenCalledTimes(4); // token 獲取，初始獲取，創建，刷新
      expect(screen.getByText(/文章創建成功!/i)).toBeInTheDocument();
      expect(screen.getByText(/我的新文章/i)).toBeInTheDocument();
      expect(window.gtag).toHaveBeenCalledWith(
        'event',
        'create_article',
        expect.objectContaining({
          event_category: 'Article Management',
          label: 'Tenant: cw',
          value: 1,
        })
      );
    });
  });

  it('允許發布文章並觸發 GA 事件', async () => {
    const user = userEvent.setup();
    const mockArticle = { id: 1, title: '草稿文章', content: '草稿內容。', status: 'draft' };

    // 模擬 token 獲取
    fetch.mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ token: 'mock_jwt_token' }) }));
    // 模擬初始文章獲取
    fetch.mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve([mockArticle]) }));
    // 模擬發布文章
    fetch.mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ message: '文章發布成功。' }) }));
    // 模擬發布後刷新文章
    fetch.mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve([{ ...mockArticle, status: 'published' }]) }));

    render(<Home />);

    await waitFor(() => {
      expect(screen.getByText(/草稿文章/i)).toBeInTheDocument();
    });

    const publishButton = screen.getByRole('button', { name: /發布/i });
    await user.click(publishButton);

    await waitFor(() => {
      expect(fetch).toHaveBeenCalledTimes(4); // token 獲取，初始獲取，發布，刷新
      expect(screen.getByText(/文章發布成功!/i)).toBeInTheDocument();
      expect(screen.getByText(/PUBLISHED/i)).toBeInTheDocument(); // 檢查更新後的狀態
      expect(window.gtag).toHaveBeenCalledWith(
        'event',
        'publish_article',
        expect.objectContaining({
          event_category: 'Article Management',
          label: `Article ID: ${mockArticle.id}, Tenant: cw`,
          value: 1,
        })
      );
    });
  });
});
