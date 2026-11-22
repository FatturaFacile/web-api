using Microsoft.Extensions.Hosting;
using Edge.Gateway;
using Edge.Gateway.Publishing;
using Edge.Gateway.Time;
using Edge.Gateway.Wal;
using Microsoft.Extensions.Options;

var builder = Host.CreateApplicationBuilder(args);

// gateway.json is reloaded on change: tag maps hot-swap, connection changes restart only the affected connector.
builder.Configuration.AddJsonFile("gateway.json", optional: true, reloadOnChange: true);
builder.Services.Configure<GatewayConfig>(builder.Configuration.GetSection(GatewayConfig.Section));

builder.Services.AddSingleton<ClockOffsetEstimator>();
builder.Services.AddSingleton<WatermarkTracker>();
builder.Services.AddSingleton(sp =>
{
    var cfg = sp.GetRequiredService<IOptions<GatewayConfig>>().Value;
    return new WalStore(new WalOptions(cfg.WalPath, cfg.WalMaxBytes));
});

builder.Services.AddHostedService<GatewaySupervisor>();

// The forwarding target is chosen at start-up: "Laravel" = signed state events (HMAC), anything else = Mes.Core ingest.
var publisherMode = builder.Configuration.GetSection(GatewayConfig.Section)[nameof(GatewayConfig.Publisher)];
if (string.Equals(publisherMode, "Laravel", StringComparison.OrdinalIgnoreCase))
{
    builder.Services.AddHttpClient<LaravelTelemetryPublisher>((sp, http) =>
        {
            http.BaseAddress = new Uri(sp.GetRequiredService<IOptions<GatewayConfig>>().Value.Laravel.BaseUrl);
            http.Timeout = Timeout.InfiniteTimeSpan; // per-attempt timeouts come from the resilience handler
        })
        .ConfigurePrimaryHttpMessageHandler(sp => EdgeHttp.CreateHandler(sp.GetRequiredService<IOptions<GatewayConfig>>().Value))
        .AddStandardResilienceHandler(o =>
        {
            // Events are idempotent by event_uuid and the body is signed once (no timestamp), so retries resend identical bytes.
            // 401/422 are not retried by the handler; they come back to the forwarder, which decides.
            o.AttemptTimeout.Timeout = TimeSpan.FromSeconds(15);
            o.TotalRequestTimeout.Timeout = TimeSpan.FromSeconds(60);
            o.CircuitBreaker.SamplingDuration = TimeSpan.FromSeconds(30); // must be >= 2x attempt timeout
        });
    builder.Services.AddSingleton<LaravelForwarder>();
    builder.Services.AddHostedService<LaravelPublisherService>();
}
else
{
    builder.Services.AddHttpClient<HttpBatchPublisher>((sp, http) =>
        {
            http.BaseAddress = new Uri(sp.GetRequiredService<IOptions<GatewayConfig>>().Value.MesBaseUrl);
            http.Timeout = Timeout.InfiniteTimeSpan; // per-attempt timeouts come from the resilience handler
        })
        .ConfigurePrimaryHttpMessageHandler(sp => EdgeHttp.CreateHandler(sp.GetRequiredService<IOptions<GatewayConfig>>().Value))
        .AddStandardResilienceHandler(o =>
        {
            // Batches are idempotent (server de-duplicates on BatchId), so retrying POST is safe.
            o.AttemptTimeout.Timeout = TimeSpan.FromSeconds(15);
            o.TotalRequestTimeout.Timeout = TimeSpan.FromSeconds(60);
            o.CircuitBreaker.SamplingDuration = TimeSpan.FromSeconds(30); // must be >= 2x attempt timeout
        });
    builder.Services.AddHostedService<PublisherService>();
}

await builder.Build().RunAsync();
