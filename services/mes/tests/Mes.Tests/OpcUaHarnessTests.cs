using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Domain.Telemetry;
using Mes.Infrastructure.Connectors;
using Mes.Infrastructure.Connectors.OpcUa;
using Mes.Tests.Support;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace Mes.Tests;

/// <summary>
/// End-to-end OPC UA connector tests against a REAL in-process OPC UA server (OPCFoundation SDK, loopback, no Docker):
/// session + subscription lifecycle, URI-based node resolution, server-side deadband, keep-alive supervision,
/// reconnect after a server restart, and channel back-pressure.
/// </summary>
public sealed class OpcUaHarnessTests : IDisposable
{
    private readonly string _clientPki = Path.Combine(Path.GetTempPath(), "mes-opc-client-" + Guid.NewGuid().ToString("N"));

    private OpcUaConnector Connector(TestOpcUaServer server, IReadOnlyList<OpcUaTagSpec> tags, int capacity = 1000) =>
        new(
            "opc-test",
            new OpcUaConnectorOptions(
                server.Url,
                tags,
                UseSecurity: false,
                PublishingIntervalMs: 50,
                KeepAliveCount: 5,
                LifetimeCount: 60,
                KeepAliveInterval: TimeSpan.FromMilliseconds(500),
                ReconnectTimeout: TimeSpan.FromSeconds(4),
                WatchdogInterval: TimeSpan.FromMilliseconds(250),
                StaleAfter: TimeSpan.FromSeconds(8),
                ChannelCapacity: capacity),
            new PkiOpcUaConfigProvider("MesHarnessClient", _clientPki, autoAcceptUntrusted: true),
            NullLogger<OpcUaConnector>.Instance,
            backoff: new BackoffOptions(TimeSpan.FromMilliseconds(100), TimeSpan.FromMilliseconds(500)));

    private static OpcUaTagSpec Temp(double? deadband = null) => new("temp", TestOpcUaServer.NamespaceUri, "Temp", 20, deadband);

    private static OpcUaTagSpec Count() => new("count", TestOpcUaServer.NamespaceUri, "Count", 20);

    private static async Task<(Task Run, CancellationTokenSource Cts)> StartAsync(IConnector c)
    {
        var cts = new CancellationTokenSource();
        var run = Task.Run(() => c.RunAsync(cts.Token));
        await Poll.UntilAsync(() => c.Health.Status == ConnectorStatus.Connected, "OPC UA connector Connected", 30_000);
        return (run, cts);
    }

    private static async Task<RawSample> NextWhere(IConnector c, Func<RawSample, bool> pred, int timeoutMs = 20_000)
    {
        using var cts = new CancellationTokenSource(timeoutMs);
        while (true)
        {
            var s = await c.Samples.ReadAsync(cts.Token);
            if (pred(s))
            {
                return s;
            }
        }
    }

    [Fact]
    public async Task Resolves_nodes_by_namespace_uri_and_streams_value_changes_with_source_timestamps()
    {
        await using var server = new TestOpcUaServer();
        await server.StartAsync();
        await using var c = Connector(server, [Temp(), Count(), new OpcUaTagSpec("ghost", "urn:not:exposed", "X")]);
        var (run, cts) = await StartAsync(c);

        // initial value is delivered when the subscription is created
        (await NextWhere(c, s => s.SourceAddress == "temp")).NumericValue.Should().Be(0.0);

        server.Set("Temp", 21.5);
        var temp = await NextWhere(c, s => s.SourceAddress == "temp" && s.NumericValue == 21.5);
        temp.Quality.Should().Be(SampleQuality.Good);
        temp.SourceTime.Should().NotBeNull();
        (DateTimeOffset.UtcNow - temp.SourceTime!.Value).Duration().Should().BeLessThan(TimeSpan.FromSeconds(30));

        server.Set("Count", 7);
        (await NextWhere(c, s => s.SourceAddress == "count" && s.NumericValue == 7)).Should().NotBeNull();
        c.Health.DroppedSamples.Should().Be(0);

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(15));
        c.Health.Status.Should().Be(ConnectorStatus.Stopped);
        await Poll.UntilAsync(() => server.SessionCount == 0, "server session closed on shutdown", 10_000);
    }

    [Fact]
    public async Task Server_side_deadband_suppresses_small_changes()
    {
        await using var server = new TestOpcUaServer();
        await server.StartAsync();
        await using var c = Connector(server, [Temp(deadband: 0.5)]);
        var (run, cts) = await StartAsync(c);
        await NextWhere(c, s => s.NumericValue == 0.0);

        server.Set("Temp", 0.1); // |Δ| < 0.5: filtered by the server
        server.Set("Temp", 0.2);
        server.Set("Temp", 1.0); // exceeds the deadband
        var next = await NextWhere(c, _ => true);
        next.NumericValue.Should().Be(1.0, "0.1 and 0.2 were inside the deadband");

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(15));
    }

    [Fact]
    public async Task Recovers_subscription_after_the_server_is_restarted()
    {
        await using var server = new TestOpcUaServer();
        await server.StartAsync();
        await using var c = Connector(server, [Temp()]);
        var (run, cts) = await StartAsync(c);
        await NextWhere(c, s => s.NumericValue == 0.0);

        var port = server.Port;
        await server.StopAsync(); // keep-alive fails -> reconnect attempt (server gone) -> bounded by ReconnectTimeout
        await Poll.UntilAsync(() => c.Health.Status != ConnectorStatus.Connected, "connector notices the outage", 30_000);

        await using var restarted = new TestOpcUaServer(port);
        await restarted.StartAsync();
        await Poll.UntilAsync(() => c.Health.Status == ConnectorStatus.Connected && restarted.SessionCount > 0, "connector re-established session + subscription on the restarted server", 60_000);

        restarted.Set("Temp", 42.0);
        (await NextWhere(c, s => s.NumericValue == 42.0, 30_000)).SourceAddress.Should().Be("temp"); // data flows again
        c.Health.ConsecutiveFailures.Should().Be(0);

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(15));
    }

    [Fact]
    public async Task Keeps_retrying_with_backoff_while_the_server_is_down_and_connects_when_it_appears()
    {
        var port = FreePort.Get();
        await using var late = new TestOpcUaServer(port);
        await using var c = Connector(late, [Temp()]);
        using var cts = new CancellationTokenSource();
        var run = Task.Run(() => c.RunAsync(cts.Token));

        await Poll.UntilAsync(() => c.Health.ConsecutiveFailures >= 2, "two consecutive failures while the server is down", 60_000);
        c.Health.LastError.Should().NotBeNullOrEmpty();

        await late.StartAsync();
        await Poll.UntilAsync(() => c.Health.Status == ConnectorStatus.Connected, "connected after the server came up", 60_000);

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(15));
    }

    [Fact]
    public async Task Slow_consumer_drops_and_counts_but_the_session_stays_up()
    {
        await using var server = new TestOpcUaServer();
        await server.StartAsync();
        await using var c = Connector(server, [Temp()], capacity: 3);
        var (run, cts) = await StartAsync(c);

        for (var i = 1; i <= 60; i++)
        {
            server.Set("Temp", i);
            await Task.Delay(15); // slower than the 20 ms sampling => most changes are published
        }

        await Poll.UntilAsync(() => c.Health.DroppedSamples > 0, "dropped samples counted", 20_000);
        c.Health.Status.Should().Be(ConnectorStatus.Connected, "back-pressure must not tear the OPC UA session down");

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(15));
    }

    public void Dispose()
    {
        try
        {
            Directory.Delete(_clientPki, true);
        }
        catch (IOException)
        {
            // best effort
        }
    }
}
