from pydantic_settings import BaseSettings, SettingsConfigDict
from typing import Optional

class Settings(BaseSettings):
    # Laravel 後端服務的基礎 URL
    LARAVEL_BACKEND_BASE_URL: str = "http://laravel:8000"
    
    # Laravel GraphQL API 端點 URL
    LARAVEL_GRAPHQL_URL: str = "http://laravel:8000/graphql"

    # Google Cloud TTS API 金鑰
    GCP_TTS_API_KEY: str # 請在 .env 中設定

    # Firebase 伺服器金鑰 (用於推送通知)
    FIREBASE_SERVER_KEY: Optional[str] = "" # 請在 .env 中設定 (可選，如果實際不使用 Firebase)

    # JWT 秘密金鑰 (用於簽名和驗證 Token)
    JWT_SECRET_KEY: str # 確保與 Laravel 的秘密金鑰匹配

    # CORS 允許的來源 (逗號分隔的 URL 列表)
    CORS_ORIGINS: str = "http://localhost:3000,http://localhost"

    # Sentry DSN (錯誤追蹤)
    SENTRY_DSN: Optional[str] = ""

    # FastAPI Tenant Init Webhook URL
    FASTAPI_TENANT_INIT_WEBHOOK_URL: str = "http://0.0.0.0:80/webhook/tenant-init"

    # 版本資訊
    VERSION: str = "1.0.0" # FastAPI 應用程式版本

    model_config = SettingsConfigDict(env_file='.env', env_file_encoding='utf-8', extra='ignore') # 'extra' 設置為 'ignore' 以允許 .env 中存在非模型定義的變數

settings = Settings()
