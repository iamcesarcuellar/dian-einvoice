# Development image for the web app: Node LTS with pnpm and the Vite dev server.
FROM node:26-alpine

RUN npm install --global pnpm@12 && npm cache clean --force

# The container runs with the host user id; tool caches stay inside the container.
ENV HOME=/tmp

WORKDIR /app

EXPOSE 5173

CMD ["pnpm", "dev"]
