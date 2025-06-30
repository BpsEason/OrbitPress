import httpx
import os
import time # 用於指標
import asyncio # 用於模擬非同步工作
from fastapi import FastAPI, HTTPException, Request, Depends, status, Security
from fastapi.responses import JSONResponse, PlainTextResponse # 用於指標
from pydantic import BaseModel, HttpUrl
from typing import Dict, Any, List, Optional
from fastapi.middleware.cors import CORSMiddleware
from dotenv import load_dotenv
from jose import JWTError, jwt # 導入 JWT 相關模組
from fastapi.security import OAuth2PasswordBearer # 用於身份驗證方案
from slowapi import Limiter, _rate_limit_exceeded_handler # 導入速率限制模組
from slowapi.util import get_remote_address
from slowapi.errors import RateLimitExceeded
from fastapi.routing import APIRoute # 用於自訂路由以進行指標追蹤
from starlette.middleware.base import BaseHTTPMiddleware
from strawberry.asgi import GraphQL
from strawberry.exceptions import StrawberryGraphQLError

# Sentry 相關導入
import sentry_sdk
from sentry_sdk.integrations.asgi import SentryAsgiMiddleware # 或直接使用 starlette 整合
from sentry_sdk.integrations.httpx import HttpxIntegration # 追蹤 httpx 請求

# 載入環境變數
load_dotenv()

# 從 config.py 導入設定
from config.config import settings

# Sentry 初始化
# 確保 SENTRY_DSN 存在於 .env 檔案中
if settings.SENTRY_DSN:
    sentry_sdk.init(
        dsn=settings.SENTRY_DSN,
        integrations=[
            SentryAsgiMiddleware(), # 或 SentryStarletteMiddleware()
            HttpxIntegration(), # 追蹤 httpx 請求
        ],
        traces_sample_rate=1.0, # 調整為您的需求
        profiles_sample_rate=1.0, # 調整為您的需求
        environment=os.getenv("APP_ENV", "development"),
        release=f"fastapi@{settings.VERSION}", # 使用 config.py 中的版本
    )
    print(f"Sentry SDK 已初始化，環境: {os.getenv('APP_ENV', 'development')}, DSN: {settings.SENTRY_DSN}")
else:
    print("SENTRY_DSN 未設定，Sentry 錯誤追蹤已跳過。")

# JWT 配置
SECRET_KEY = settings.JWT_SECRET_KEY
ALGORITHM = "HS256"
oauth2_scheme = OAuth2PasswordBearer(tokenUrl="api/auth/token") # Laravel token endpoint

# 速率限制器
limiter = Limiter(key_func=get_remote_address)

app = FastAPI(
    title="OrbitPress API 閘道",
    description="將請求路由到適當的租戶後端並處理身份驗證。它還提供了文本轉語音集成和基本的 API 指標。",
    version=settings.VERSION # 使用 config.py 中的版本
)

# 添加速率限制中間件
app.state.limiter = limiter
app.add_exception_handler(RateLimitExceeded, _rate_limit_exceeded_handler)


# 設定 CORS
origins = [origin.strip() for origin in settings.CORS_ORIGINS.split(',')]
app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Laravel 後端服務基礎 URL
LARAVEL_BACKEND_BASE_URL = settings.LARAVEL_BACKEND_BASE_URL
LARAVEL_GRAPHQL_URL = settings.LARAVEL_GRAPHQL_URL # Laravel GraphQL 端點

# 簡單的內存指標 (僅用於演示目的)
request_count = {}
request_duration_sum = {}
request_duration_count = {}

def increment_counter(metric_name: str, labels: Dict[str, str] = None):
    # 創建一個用於指標字典鍵的標準化字符串
    label_suffix = ""
    if labels:
        sorted_labels = sorted(labels.items())
        label_suffix = "_" + "_".join(f"{k}_{v}" for k, v in sorted_labels)
    key = metric_name + label_suffix
    request_count[key] = request_count.get(key, 0) + 1

def observe_histogram(metric_name: str, value: float, labels: Dict[str, str] = None):
    # 創建一個用於指標字典鍵的標準化字符串
    label_suffix = ""
    if labels:
        sorted_labels = sorted(labels.items())
        label_suffix = "_" + "_".join(f"{k}_{v}" for k, v in sorted_labels)
    key = metric_name + label_suffix
    request_duration_sum[key] = request_duration_sum.get(key, 0) + value
    request_duration_count[key] = request_duration_count.get(key, 0) + 1

# 自定義路由類，用於自動追蹤每個請求的指標
class TimedRoute(APIRoute):
    def get_route_handler(self):
        original_route_handler = super().get_route_handler()

        async def custom_route_handler(request: Request) -> Any:
            start_time = time.perf_counter()
            response = None
            try:
                response = await original_route_handler(request)
                status_code = response.status_code
            except HTTPException as e:
                status_code = e.status_code
                raise
            except Exception as e:
                status_code = 500 # For unexpected errors
                raise
            finally:
                end_time = time.perf_counter()
                duration = end_time - start_time
                method = request.method
                path = request.url.path
                
                # 僅追蹤相關路徑的指標
                if path.startswith("/tenant-api/") or path.startswith("/tts") or path.startswith("/graphql"):
                    increment_counter("fastapi_http_requests_total", {"method": method, "path": path, "status": str(status_code)})
                    observe_histogram("fastapi_request_duration_seconds", duration, {"method": method, "path": path})
            return response

        return custom_route_handler


# 將自定義路由類應用於整個應用程式
app.router.route_class = TimedRoute

class TenantRequest(BaseModel):
    endpoint: str
    payload: Dict[str, Any] = {}
    method: str = "POST"

    model_config = {
        "json_schema_extra": {
            "examples": [
                {
                    "endpoint": "articles",
                    "payload": {"title": "新文章標題", "content": "這是新文章的內容。", "status": "draft"},
                    "method": "POST"
                }
            ]
        }
    }

class ExternalServiceRequest(BaseModel):
    text: str
    tenant_id: str

    model_config = {
        "json_schema_extra": {
            "examples": [
                {
                    "text": "你好，這是一篇測試文章。",
                    "tenant_id": "cw"
                }
            ]
        }
    }

class GraphQLRequest(BaseModel):
    query: str
    variables: Optional[Dict[str, Any]] = None
    operationName: Optional[str] = None

    model_config = {
        "json_schema_extra": {
            "examples": [
                {
                    "query": "query { articles { id title } }",
                    "variables": {},
                    "operationName": "GetArticles"
                }
            ]
        }
    }

class TenantInitWebhookPayload(BaseModel):
    tenant_id: str
    tenant_name: str
    domain: Optional[str] = None
    data: Optional[Dict[str, Any]] = {}

async def get_current_user(token: str = Security(oauth2_scheme)):
    """
    從 JWT Token 中提取用戶資訊和租戶 ID。
    """
    try:
        payload = jwt.decode(token, SECRET_KEY, algorithms=[ALGORITHM])
        tenant_id = payload.get("tenant_id")
        user_id = payload.get("sub")
        if not tenant_id or not user_id:
            raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="無效的身份驗證 Token：缺少租戶或用戶 ID。")
        return {"user_id": user_id, "tenant_id": tenant_id}
    except JWTError:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="無效的身份驗證 Token。")
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"JWT 解碼錯誤：{e}")


@app.api_route(
    "/tenant-api/{endpoint:path}", 
    methods=["GET", "POST", "PUT", "DELETE", "PATCH"],
    response_model=Dict[str, Any],
    summary="將請求路由到租戶特定的 Laravel 後端",
    description="將請求轉發到指定租戶的 Laravel 後端。需要有效的 JWT Token 和 X-Tenant-ID 標頭。",
    responses={
        200: {"description": "後端成功響應"},
        201: {"description": "資源成功創建"},
        204: {"description": "資源成功刪除，無內容"},
        400: {"description": "缺失或無效的 X-Tenant-ID 標頭"},
        401: {"description": "無效或缺失的 JWT Token"},
        403: {"description": "無權限執行操作"},
        404: {"description": "找不到資源或租戶"},
        429: {"description": "請求過於頻繁 (速率限制)"},
        500: {"description": "後端或意外錯誤"}
    }
)
@limiter.limit("100/minute") # 每分鐘 100 次請求的速率限制
async def route_to_tenant_api(endpoint: str, request: Request, current_user: Dict[str, Any] = Depends(get_current_user)):
    tenant_id = current_user["tenant_id"]
    
    body = None
    if request.method in ["POST", "PUT", "PATCH"]:
        try:
            body = await request.json()
        except Exception: # 捕獲 JSON 解碼錯誤
            raise HTTPException(status_code=status.HTTP_400_BAD_REQUEST, detail="無效的 JSON 請求主體")

    target_url = f"{LARAVEL_BACKEND_BASE_URL}/tenant-routes/{endpoint}"
    method = request.method

    # 轉發相關標頭
    headers = {
        key: value 
        for key, value in request.headers.items() 
        if key.lower() not in ["host", "content-length", "accept-encoding", "user-agent", "connection", "authorization"]
    }
    headers["X-Tenant-ID"] = tenant_id # 確保租戶 ID 傳遞
    headers["Accept"] = "application/json" # 確保 JSON 響應
    headers["Authorization"] = request.headers.get("Authorization") # 轉發授權標頭

    try:
        async with httpx.AsyncClient() as client:
            response = await client.request(method, target_url, json=body, headers=headers, timeout=30.0)
            response.raise_for_status() # 對 4xx/5xx 響應引發異常
            return JSONResponse(content=response.json(), status_code=response.status_code)
            
    except httpx.HTTPStatusError as e:
        # 處理來自後端的 HTTP 錯誤 (例如，403, 404, 500)
        raise HTTPException(status_code=e.response.status_code, detail=f"後端錯誤: {e.response.text}")
    except Exception as e:
        # 處理其他潛在錯誤
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"發生意外錯誤: {e}")


@app.post(
    "/tts",
    response_model=Dict[str, Any],
    summary="使用 Google Cloud TTS 將文本轉語音",
    description="將文本發送到 Google Cloud Text-to-Speech API 並返回音訊內容。需要 GCP_TTS_API_KEY 和 tenant_id。",
    responses={
        200: {
            "description": "成功的 TTS 響應",
            "content": {
                "application/json": {
                    "example": {"audioContent": "base64_encoded_audio"}
                }
            }
        },
        500: {"description": "GCP TTS API 錯誤或配置問題"}
    }
)
@limiter.limit("10/minute") # 語音轉換的速率限制
async def text_to_speech(req: ExternalServiceRequest, request: Request): # 添加 request 參數以進行速率限制
    gcp_tts_url = "https://texttospeech.googleapis.com/v1/text:synthesize"
    gcp_api_key = settings.GCP_TTS_API_KEY

    if not gcp_api_key:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail="GCP_TTS_API_KEY 未配置。")

    headers = {"Content-Type": "application/json"}
    
    payload = {
        "input": {"text": req.text},
        "voice": {"languageCode": "zh-TW", "name": "cmn-TW-Wavenet-A"}, # 範例語音
        "audioConfig": {"audioEncoding": "MP3"}
    }
    
    api_url_with_key = f"{gcp_tts_url}?key={gcp_api_key}"

    try:
        async with httpx.AsyncClient() as client:
            response = await client.post(api_url_with_key, json=payload, headers=headers)
            response.raise_for_status()
            return response.json()
    except httpx.HTTPStatusError as e:
        raise HTTPException(status_code=e.response.status_code, detail=f"GCP TTS API 錯誤: {e.response.text}")
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"發生意外錯誤: {e}")

@app.post(
    "/graphql",
    response_model=Dict[str, Any],
    summary="GraphQL 代理",
    description="將 GraphQL 請求轉發到 Laravel 後端 GraphQL 服務。需要有效的 JWT Token 和 X-Tenant-ID 標頭。",
    responses={
        200: {"description": "GraphQL 請求成功"},
        400: {"description": "無效的 GraphQL 請求"},
        401: {"description": "無效或缺失的 JWT Token"},
        500: {"description": "GraphQL 後端服務錯誤"}
    }
)
@limiter.limit("50/minute") # GraphQL 查詢的速率限制
async def graphql_proxy(graphql_request: GraphQLRequest, request: Request, current_user: Dict[str, Any] = Depends(get_current_user)):
    tenant_id = current_user["tenant_id"]
    
    headers = {
        key: value 
        for key, value in request.headers.items() 
        if key.lower() not in ["host", "content-length", "accept-encoding", "user-agent", "connection", "authorization"]
    }
    headers["X-Tenant-ID"] = tenant_id # 轉發租戶 ID
    headers["Authorization"] = request.headers.get("Authorization") # 轉發授權標頭
    headers["Content-Type"] = "application/json" # 確保內容類型

    try:
        async with httpx.AsyncClient() as client:
            response = await client.post(LARAVEL_GRAPHQL_URL, json=graphql_request.model_dump(by_alias=True, exclude_unset=True), headers=headers, timeout=60.0) # GraphQL 請求可能較長
            response.raise_for_status()
            return JSONResponse(content=response.json(), status_code=response.status_code)
    except httpx.HTTPStatusError as e:
        raise HTTPException(status_code=e.response.status_code, detail=f"GraphQL 後端錯誤: {e.response.text}")
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"GraphQL 代理發生意外錯誤: {e}")

@app.post(
    "/webhook/tenant-init",
    summary="租戶初始化 Webhook",
    description="由 Laravel 後端調用，用於觸發 FastAPI 中租戶特定的初始化邏輯。",
    status_code=status.HTTP_200_OK,
    responses={
        200: {"description": "租戶初始化成功"},
        400: {"description": "無效的 Webhook 請求"},
        500: {"description": "內部伺服器錯誤"}
    }
)
async def tenant_init_webhook(payload: TenantInitWebhookPayload):
    # 這裡可以添加驗證 Webhook 簽名以提高安全性
    # 例如，使用共享秘密來驗證請求是否來自可信來源

    try:
        # 在這裡執行 FastAPI 端的租戶初始化邏輯
        # 例如：
        # - 為新租戶配置特定的路由規則 (如果需要)
        # - 在某些外部服務中註冊租戶
        # - 清理或刷新緩存等

        print(f"收到來自 Laravel 的租戶初始化請求：租戶 ID: {payload.tenant_id}, 名稱: {payload.tenant_name}")
        # Log.info(f"FastAPI 收到租戶初始化 Webhook，租戶 ID: {payload.tenant_id}") # 如果您有更複雜的日誌記錄

        # 模擬一些工作
        await asyncio.sleep(1) 

        return {"message": f"租戶 {payload.tenant_id} 在 FastAPI 端已成功初始化"}
    except Exception as e:
        # Log.error(f"FastAPI 處理租戶初始化 Webhook 失敗：{e}") # 如果您有更複雜的日誌記錄
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=f"處理租戶初始化失敗: {e}")

@app.get("/metrics", response_class=PlainTextResponse, summary="Prometheus 指標", description="提供 Prometheus 格式的應用程式指標。")
def get_metrics(request: Request): # 添加 request 參數以進行速率限制
    """
    FastAPI 的 Prometheus 指標端點。
    """
    metrics_output = ""

    # 計數器
    for key, value in request_count.items():
        # 嘗試解析標籤，處理可能不包含標籤的情況
        parts = key.split("_", 1)
        metric_name = parts[0]
        labels_str_raw = parts[1] if len(parts) > 1 else ""

        labels_dict = {}
        # 簡易解析 key-value 標籤對
        if labels_str_raw:
            # 假設標籤字符串格式為 "label1_value1_label2_value2"
            label_pairs = labels_str_raw.split('_')
            for i in range(0, len(label_pairs), 2):
                if i + 1 < len(label_pairs):
                    labels_dict[label_pairs[i]] = label_pairs[i+1]
        
        labels_formatted = ",".join(f'{k}="{v}"' for k,v in labels_dict.items())
        
        metrics_output += f"# HELP {metric_name} 請求總數。\n"
        metrics_output += f"# TYPE {metric_name} counter\n"
        metrics_output += f"{metric_name}{{{labels_formatted}}} {value}\n"
    
    # 直方圖 (此演示簡化為總和和計數)
    for key, value in request_duration_sum.items():
        parts = key.split("_", 1)
        metric_name = parts[0]
        labels_str_raw = parts[1] if len(parts) > 1 else ""

        labels_dict = {}
        if labels_str_raw:
            label_pairs = labels_str_raw.split('_')
            for i in range(0, len(label_pairs), 2):
                if i + 1 < len(label_pairs):
                    labels_dict[label_pairs[i]] = label_pairs[i+1]
        
        labels_formatted = ",".join(f'{k}="{v}"' for k,v in labels_dict.items())
        
        metrics_output += f"# HELP {metric_name}_sum 請求的總持續時間。\n"
        metrics_output += f"# TYPE {metric_name}_sum gauge\n" # 為簡化使用 gauge，應為 histogram
        metrics_output += f"{metric_name}_sum{{{labels_formatted}}} {value}\n"
        metrics_output += f"# HELP {metric_name}_count 請求持續時間的總計數。\n"
        metrics_output += f"# TYPE {metric_name}_count gauge\n" # 為簡化使用 gauge
        metrics_output += f"{metric_name}_count{{{labels_formatted}}} {request_duration_count[key]}\n"


    metrics_output += "# HELP fastapi_info 關於 FastAPI 應用程式的資訊。\n"
    metrics_output += "# TYPE fastapi_info gauge\n"
    metrics_output += f'fastapi_info{{version="{app.version}"}} 1\n'

    return PlainTextResponse(content=metrics_output)


@app.get("/", summary="健康檢查", description="API 閘道的健康檢查端點。")
def health_check():
    """
    API 閘道的健康檢查端點。
    """
    return {"status": "ok", "message": "OrbitPress API 閘道正在運行。"}

