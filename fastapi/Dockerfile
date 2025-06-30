FROM python:3.10-slim

WORKDIR /app

COPY requirements.txt .

# 安裝依賴
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# 暴露 80 端口用於 HTTP 流量，9001 端口用於指標
EXPOSE 80 9001

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "80"]
