FROM node:18-alpine

WORKDIR /app

COPY package.json yarn.lock ./

RUN yarn install --frozen-lockfile

COPY . .

# 構建 Next.js 應用程式用於生產
RUN yarn build

EXPOSE 3000

CMD ["yarn", "start"]
