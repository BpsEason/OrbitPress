import pytest
import httpx # Import httpx for mocking
from fastapi.testclient import TestClient
from main import app # 假設您的 FastAPI 應用程式在 main.py 中

# 創建 TestClient 實例
client = TestClient(app)

# 用於測試的模擬環境變數
@pytest.fixture(autouse=True)
def mock_env_vars(monkeypatch):
    monkeypatch.setenv("LARAVEL_BACKEND_BASE_URL", "http://mock-laravel:8000")
    monkeypatch.setenv("LARAVEL_GRAPHQL_URL", "http://mock-laravel:8000/graphql")
    monkeypatch.setenv("GCP_TTS_API_KEY", "mock_gcp_api_key")
    monkeypatch.setenv("CORS_ORIGINS", "http://test-origin")
    monkeypatch.setenv("JWT_SECRET_KEY", "test_jwt_secret_key_for_ci") # 與 CI 環境中的 Laravel JWT_SECRET_KEY 匹配
    monkeypatch.setenv("SENTRY_DSN", "") # 在測試中禁用 Sentry
    monkeypatch.setenv("FASTAPI_TENANT_INIT_WEBHOOK_URL", "http://localhost:80/webhook/tenant-init")


def test_health_check():
    """測試健康檢查端點。"""
    response = client.get("/")
    assert response.status_code == 200
    assert response.json() == {"status": "ok", "message": "OrbitPress API 閘道正在運行。"}

@pytest.mark.asyncio
async def test_route_to_tenant_api_success(respx_mock):
    """
    測試成功路由到租戶 API 端點。
    我們需要模擬對 Laravel 後端的外部 HTTP 呼叫。
    """
    # 模擬對 Laravel 後端的外部請求
    respx_mock.post("http://mock-laravel:8000/tenant-routes/articles").mock(
        return_value=httpx.Response(201, json={"id": 1, "title": "測試文章"})
    )

    # 為了測試，我們需要一個有效的 JWT
    # 這裡我們手動創建一個包含 tenant_id 的 JWT
    import jwt
    token_payload = {"sub": "test_user_id", "tenant_id": "test_tenant_id"}
    # 確保使用的秘密金鑰與 main.py 中的 SECRET_KEY 匹配
    jwt_token = jwt.encode(token_payload, "test_jwt_secret_key_for_ci", algorithm="HS256")

    headers = {"X-Tenant-ID": "test_tenant_id", "Authorization": f"Bearer {jwt_token}"}
    payload = {"title": "測試文章", "content": "一些內容。"}

    response = client.post("/tenant-api/articles", headers=headers, json=payload)

    assert response.status_code == 201
    assert response.json() == {"id": 1, "title": "測試文章"}
    assert respx_mock.calls.call_count == 1

@pytest.mark.asyncio
async def test_route_to_tenant_api_missing_tenant_id():
    """測試缺少 X-Tenant-ID 標頭時的路由。"""
    import jwt
    token_payload = {"sub": "test_user_id", "tenant_id": "test_tenant_id"}
    jwt_token = jwt.encode(token_payload, "test_jwt_secret_key_for_ci", algorithm="HS256")

    payload = {"title": "測試文章"}
    response = client.post("/tenant-api/articles", headers={"Authorization": f"Bearer {jwt_token}"}, json=payload)
    assert response.status_code == 400
    assert response.json() == {"detail": "無效的身份驗證 Token：缺少租戶或用戶 ID。"} # 修改為從 get_current_user 拋出的錯誤

@pytest.mark.asyncio
async def test_route_to_tenant_api_invalid_jwt():
    """測試 JWT 無效時的路由。"""
    headers = {"X-Tenant-ID": "test_tenant_id", "Authorization": "Bearer invalid.jwt.token"}
    payload = {"title": "測試文章"}
    response = client.post("/tenant-api/articles", headers=headers, json=payload)
    assert response.status_code == 401
    assert response.json() == {"detail": "無效的身份驗證 Token。"}

@pytest.mark.asyncio
async def test_tts_endpoint_success(respx_mock):
    """測試 TTS 端點成功。"""
    # 模擬對 Google Cloud TTS API 的外部請求
    respx_mock.post("[https://texttospeech.googleapis.com/v1/text:synthesize?key=mock_gcp_api_key](https://texttospeech.googleapis.com/v1/text:synthesize?key=mock_gcp_api_key)").mock(
        return_value=httpx.Response(200, json={"audioContent": "mock_audio_base64"})
    )

    headers = {"X-Tenant-ID": "test_tenant_id"} # Tenant ID 仍由 get_tenant_id 依賴項要求
    payload = {"text": "你好，世界！", "tenant_id": "test_tenant_id"}

    response = client.post("/tts", headers=headers, json=payload)

    assert response.status_code == 200
    assert response.json() == {"audioContent": "mock_audio_base64"}
    assert respx_mock.calls.call_count == 1

@pytest.mark.asyncio
async def test_tts_endpoint_gcp_error(respx_mock):
    """測試當 GCP API 返回錯誤時的 TTS 端點。"""
    respx_mock.post("[https://texttospeech.googleapis.com/v1/text:synthesize?key=mock_gcp_api_key](https://texttospeech.googleapis.com/v1/text:synthesize?key=mock_gcp_api_key)").mock(
        return_value=httpx.Response(500, text="GCP 內部伺服器錯誤")
    )

    headers = {"X-Tenant-ID": "test_tenant_id"}
    payload = {"text": "你好，世界！", "tenant_id": "test_tenant_id"}

    response = client.post("/tts", headers=headers, json=payload)

    assert response.status_code == 500
    assert "GCP TTS API 錯誤" in response.json()["detail"]

@pytest.mark.asyncio
async def test_graphql_proxy_success(respx_mock):
    """測試 GraphQL 代理端點成功。"""
    import jwt
    token_payload = {"sub": "test_user_id", "tenant_id": "test_tenant_id"}
    jwt_token = jwt.encode(token_payload, "test_jwt_secret_key_for_ci", algorithm="HS256")

    respx_mock.post("http://mock-laravel:8000/graphql").mock(
        return_value=httpx.Response(200, json={"data": {"articles": [{"id": "1", "title": "GraphQL Article"}]}})
    )

    headers = {"X-Tenant-ID": "test_tenant_id", "Authorization": f"Bearer {jwt_token}"}
    payload = {"query": "query { articles { id title } }"}

    response = client.post("/graphql", headers=headers, json=payload)

    assert response.status_code == 200
    assert response.json()["data"]["articles"][0]["title"] == "GraphQL Article"

@pytest.mark.asyncio
async def test_tenant_init_webhook_success():
    """測試租戶初始化 webhook 端點成功。"""
    payload = {"tenant_id": "newtenant", "tenant_name": "New Company", "domain": "newcompany.localhost"}
    response = client.post("/webhook/tenant-init", json=payload)
    assert response.status_code == 200
    assert response.json() == {"message": "租戶 newtenant 在 FastAPI 端已成功初始化"}


def test_metrics_endpoint():
    """測試指標端點返回 Prometheus 格式的純文本。"""
    response = client.get("/metrics")
    assert response.status_code == 200
    assert response.headers['content-type'] == 'text/plain; charset=utf-8'
    assert "# HELP fastapi_info 關於 FastAPI 應用程式的資訊。" in response.text
    assert "# TYPE fastapi_info gauge" in response.text
    assert 'fastapi_info{version="1.0.0"}' in response.text

    # 測試在一些請求後是否存在計數器和直方圖
    import jwt
    token_payload = {"sub": "user1", "tenant_id": "tenant1"}
    jwt_token = jwt.encode(token_payload, "test_jwt_secret_key_for_ci", algorithm="HS256")
    client.post("/tenant-api/articles", headers={"X-Tenant-ID": "tenant1", "Authorization": f"Bearer {jwt_token}"}, json={"title": "a", "content": "b"})
    client.post("/tts", headers={"X-Tenant-ID": "tenant1"}, json={"text": "hi", "tenant_id": "tenant1"}) 

    metrics_response = client.get("/metrics")
    assert "fastapi_http_requests_total" in metrics_response.text
    assert "fastapi_request_duration_seconds_sum" in metrics_response.text
    assert "fastapi_tts_requests_total" in metrics_response.text
    assert "fastapi_tts_duration_seconds_sum" in metri

    metrics_response = client.get("/metrics")
    assert "fastapi_http_requests_total" in metrics_response.text
    assert "fastapi_request_duration_seconds_sum" in metrics_response.text
    assert "fastapi_tts_requests_total" in metrics_response.text
    assert "fastapi_tts_duration_seconds_sum" in metrics_response.text

# 添加更多不同場景的測試，如 GET、PUT、DELETE，以及來自後端的錯誤處理
