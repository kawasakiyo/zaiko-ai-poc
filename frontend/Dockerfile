# --- ビルドステージ: Vue 2 アプリを Vite でビルド ---
FROM node:18-alpine AS build
WORKDIR /app
COPY package.json ./
RUN npm install
COPY . .
RUN npm run build

# --- 配信ステージ: nginx で静的配信 + /api をプロキシ ---
FROM nginx:alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
