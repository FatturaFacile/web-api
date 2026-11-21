using System.Net;
using System.Net.Sockets;
using Mes.Application.Abstractions;
using Mes.Infrastructure.Connectors;
using Mes.Infrastructure.Connectors.Mqtt;
using MQTTnet;
using MQTTnet.Server;

namespace Mes.Tests.Support;

public static class Poll
{
    /// <summary>Polls a condition with a deadline: deterministic, no fixed sleeps (fails with a clear message instead of hanging).</summary>
    public static async Task UntilAsync(Func<bool> condition, string what, int timeoutMs = 15_000)
    {
        var deadline = Environment.TickCount64 + timeoutMs;
        while (!condition())
        {
            if (Environment.TickCount64 > deadline)
            {
                throw new TimeoutException($"Timed out after {timeoutMs} ms waiting for: {what}");
            }

            await Task.Delay(20);
        }
    }

    public static async Task<RawSample> ReadAsync(IConnector c, int timeoutMs = 15_000)
    {
        using var cts = new CancellationTokenSource(timeoutMs);
        return await c.Samples.ReadAsync(cts.Token);
    }
}

public static class FreePort
{
    public static int Get()
    {
        var l = new TcpListener(IPAddress.Loopback, 0);
        l.Start();
        var port = ((IPEndPoint)l.LocalEndpoint).Port;
        l.Stop();
        return port;
    }
}

/// <summary>In-process MQTT broker on a loopback port (MQTTnet.Server): real sockets, no Docker, no external process.</summary>
public sealed class TestBroker : IAsyncDisposable
{
    private MqttServer? _server;

    public TestBroker(int? port = null) => Port = port ?? FreePort.Get();

    public int Port { get; }

    public async Task StartAsync()
    {
        var factory = new MqttServerFactory();
        _server = factory.CreateMqttServer(factory.CreateServerOptionsBuilder().WithDefaultEndpoint().WithDefaultEndpointPort(Port).Build());
        await _server.StartAsync();
    }

    public async Task StopAsync()
    {
        if (_server is { } s)
        {
            await s.StopAsync();
            s.Dispose();
            _server = null;
        }
    }

    public Task PublishAsync(string topic, byte[] payload, bool retain = false)
    {
        var msg = new MqttApplicationMessageBuilder().WithTopic(topic).WithPayload(payload).WithRetainFlag(retain).Build();
        return _server!.InjectApplicationMessage(new InjectedMqttApplicationMessage(msg));
    }

    public Task PublishAsync(string topic, string json) => PublishAsync(topic, System.Text.Encoding.UTF8.GetBytes(json));

    public int ConnectedClients => _server is null ? 0 : _server.GetClientsAsync().GetAwaiter().GetResult().Count;

    public async ValueTask DisposeAsync() => await StopAsync();
}

internal sealed class RecordingDlq : IDeadLetterSink
{
    private readonly List<(string Topic, string Reason)> _items = [];

    public IReadOnlyList<(string Topic, string Reason)> Items
    {
        get
        {
            lock (_items)
            {
                return _items.ToList();
            }
        }
    }

    public void Write(string source, string topic, ReadOnlyMemory<byte> payload, string reason)
    {
        lock (_items)
        {
            _items.Add((topic, reason));
        }
    }
}
