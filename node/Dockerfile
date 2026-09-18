# Multi-stage Dockerfile for the @linotech/sdk test stack.
#
# Stage 1 (build): compile TypeScript with `tsc`.
# Stage 2 (test): install only production deps + compiled output; this
# is what the containerized test stack actually runs.
#
# Default CMD is the unit + integration test suite. Override `command:`
# in docker-compose.test.yml to run a specific test against the
# WireMock upstream (see examples/create-payment.ts as the runnable
# example).

FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --no-audit --fund=false
COPY tsconfig.json tsconfig.test.json eslint.config.js ./
COPY src ./src
RUN npm run build

FROM node:22-alpine AS test
WORKDIR /app
ENV NODE_ENV=test
COPY package.json package-lock.json* ./
RUN npm install --omit=dev --no-audit --fund=false && \
    npm install --no-audit --fund=false \
      vitest@^2.1.8 tsx@^4.19.2 typescript@^5.7.2 \
      @types/node@^22.10.0
COPY --from=build /app/dist ./dist
COPY tsconfig.json tsconfig.test.json eslint.config.js vitest.config.ts ./
COPY tests ./tests
COPY examples ./examples
COPY src ./src
CMD ["npm", "run", "test"]
