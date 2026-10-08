FROM node:20-alpine as build-stage

WORKDIR /app
RUN corepack enable
RUN corepack prepare pnpm@latest --activate

RUN npm config set registry https://registry.npmmirror.com

COPY .npmrc package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

COPY . .
RUN pnpm build

FROM nginx:stable-alpine as production-stage

# vite.config.ts 中 build.outDir 指向 ../public/admin
# （管理端需与后端同源部署以共享 Session Cookie），产物在 /app/public/admin 而非 /app/dist
COPY --from=build-stage /app/public/admin /usr/share/nginx/html
EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]