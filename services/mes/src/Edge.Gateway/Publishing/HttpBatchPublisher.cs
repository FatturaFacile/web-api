using System.Net.Http.Json;
using Edge.Gateway.Time;
using Mes.Application.Contracts;
using Microsoft.Extensions.Options;

namespace Edge.Gateway.Publishing;

public sealed record PublishResult(WireAck Ack, ClockExchange? Clock);

/// <summary>
/// Sends one stored batch to Mes.Core over TLS/mTLS (the HttpClient is configured in Program). Reads the server's
/// receive/send timestamps from response headers to feed the NTP-style clock estimator.
/// </summary>
public sealed class HttpBatchPublisher(HttpClient http)
{
    public const string Route = "/api/v1/mes/ingest/batches";

    public async Task<PublishResult> SendAsync(WireBatch batch, CancellationToken ct)
    {
        var t0 = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
        using var resp = await http.PostAsJsonAsync(Route, batch, ct).ConfigureAwait(false);
        var t3 = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds();
        resp.EnsureSuccessStatusCode();

        ClockExchange? clock = null;
        if (resp.Headers.TryGetValues("X-Mes-Rx-Ms", out var rx) && resp.Headers.TryGetValues("X-Mes-Tx-Ms", out var tx)
            && long.TryParse(rx.FirstOrDefault(), out var t1) && long.TryParse(tx.FirstOrDefault(), out var t2))
        {
            clock = new ClockExchange(t0, t1, t2, t3);
        }

        var ack = await resp.Content.ReadFromJsonAsync<WireAck>(ct).ConfigureAwait(false)
                  ?? throw new InvalidOperationException("Empty acknowledgement from Mes.Core.");
        return new PublishResult(ack, clock);
    }
}

public static class EdgeHttp
{
    /// <summary>TLS 1.2+ with an optional PFX client certificate (mTLS). Server certificate validation stays ON.</summary>
    public static SocketsHttpHandler CreateHandler(GatewayConfig cfg)
    {
        var handler = new SocketsHttpHandler
        {
            PooledConnectionLifetime = TimeSpan.FromMinutes(10),
            EnableMultipleHttp2Connections = true,
        };
        handler.SslOptions.EnabledSslProtocols = System.Security.Authentication.SslProtocols.Tls12 | System.Security.Authentication.SslProtocols.Tls13;
        if (cfg.ClientCertPath is not null)
        {
            handler.SslOptions.ClientCertificates = [System.Security.Cryptography.X509Certificates.X509CertificateLoader.LoadPkcs12FromFile(cfg.ClientCertPath, cfg.ClientCertPassword)];
        }

        return handler;
    }
}

public sealed class PublisherService(
    Wal.WalStore wal,
    HttpBatchPublisher publisher,
    ClockOffsetEstimator clock,
    ILogger<PublisherService> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var rng = new Random();
        var failures = 0;
        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                var pending = wal.PeekOldest(10);
                if (pending.Count == 0)
                {
                    await Task.Delay(TimeSpan.FromMilliseconds(250), stoppingToken).ConfigureAwait(false);
                    continue;
                }

                foreach (var item in pending)
                {
                    var result = await publisher.SendAsync(item.Batch, stoppingToken).ConfigureAwait(false);
                    if (result.Clock is { } x)
                    {
                        clock.Add(x);
                    }

                    wal.Ack([item.Id]); // delete only after the server acknowledged
                    failures = 0;
                }
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                failures++;
                var delay = Mes.Infrastructure.Connectors.Backoff.Delay(failures, Mes.Infrastructure.Connectors.BackoffOptions.Default, rng);
                logger.LogWarning(ex, "Publish failed ({Failures} in a row, WAL pending {Pending}); retrying in {Delay}", failures, wal.Pending, delay);
                await Task.Delay(delay, stoppingToken).ConfigureAwait(false);
            }
        }
    }
}
