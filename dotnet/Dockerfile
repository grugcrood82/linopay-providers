# Multi-stage Dockerfile for the @linotech/dotnet-sdk test stack.
#
# Stage 1 (sdk-build): restore + build the SDK + tests.
# Stage 2 (test):       install the test runner + deps, run xUnit against
#                       a real WireMock for the e2e tests when one is up.
#
# Default CMD is `dotnet test --no-build`. Compose services can override
# with `dotnet run --project examples/CreatePayment` to exercise the
# runnable example instead.

FROM mcr.microsoft.com/dotnet/sdk:9.0 AS sdk-build
WORKDIR /src
COPY . .
RUN dotnet restore LinoPay.Sdk.sln
RUN dotnet build LinoPay.Sdk.sln --no-restore --configuration Debug

FROM mcr.microsoft.com/dotnet/sdk:9.0 AS test
WORKDIR /src
ENV DOCKER_RUNNING_TESTS=1 \
    DOTNET_CLI_TELEMETRY_OPTOUT=1 \
    DOTNET_NOLOGO=1
COPY --from=sdk-build /src /src
# The e2e tests auto-activate when LINOPAY_E2E_BASE_URL is set by
# docker-compose. The test PEM is regenerated per-container invocation.
CMD ["dotnet", "test", "--no-build", "--configuration", "Debug", "--logger", "console;verbosity=normal", "--nologo"]
