# Multi-stage Dockerfile for the @linotech/cli test stack.
#
# Stage A — sdk-build:  compile the SDK (TypeScript -> dist/).
# Stage B — cli-build:  compile the CLI on top of the SDK; the CLI
#                       package declares `@linotech/sdk: file:../node`,
#                       so the SDK dist must live at `/node` at this
#                       point. We copy the SDK stage into both `/sdk`
#                       (named by convention) and `/node` (what the
#                       file: dep expects).
# Stage C — cli-test:   add vitest + tsx + typescript as dev deps
#                       and run the test suite. Reuses the SDK dist
#                       from stage B.

FROM node:22-alpine AS sdk-build
WORKDIR /sdk
COPY node/ ./
RUN npm install --no-audit --fund=false && \
    npm run build

FROM node:22-alpine AS cli-build
WORKDIR /cli
# The CLI's package.json declares @linotech/sdk as "file:../node".
# During the build phase, /node must exist and contain a valid SDK
# package (with a built dist). Stage A's output goes to BOTH /sdk
# (clearer in logs) and /node (where the file: dep expects it).
COPY --from=sdk-build /sdk /sdk
COPY --from=sdk-build /sdk /node
COPY cli/ ./
RUN npm install --no-audit --fund=false && \
    npm run build

FROM node:22-alpine AS cli-test
WORKDIR /cli
ENV NODE_ENV=test
COPY --from=sdk-build /sdk /sdk
COPY --from=sdk-build /sdk /node
COPY --from=cli-build /cli/dist ./dist
COPY cli/package.json cli/package-lock.json* ./
RUN npm install --no-audit --fund=false
COPY cli/tsconfig.json cli/tsconfig.test.json cli/vitest.config.ts ./
COPY cli/src ./src
COPY cli/tests ./tests
CMD ["npm", "run", "test"]
