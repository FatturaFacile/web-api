using FluentAssertions;
using Google.Protobuf;
using Mes.Application.Abstractions;
using Mes.Infrastructure.Connectors;
using Mes.Infrastructure.Connectors.Mqtt;
using Mes.Tests.Support;
using Microsoft.Extensions.Logging.Abstractions;
using MQTTnet;
using Xunit;

namespace Mes.Tests;

/// <summary>
/// End-to-end connector tests against a REAL in-process MQTT broker (loopback TCP): connection lifecycle, subscription +
/// topic parsing, failover/reconnect backoff, DLQ and back-pressure. No Docker, no external services, no fixed sleeps.
/// </summary>
public class MqttHarnessTests
{
    private static readonly BackoffOptions FastBackoff = new(TimeSpan.FromMilliseconds(40), TimeSpan.FromMilliseconds(200));

    private static MqttConnector Connector(IEnumerable<int> ports, RecordingDlq dlq, int capacity = 1000, string? will = null, params MqttSubscriptionSpec[] subs) =>
        new("mqtt-test", new MqttConnectorOptions(
                ports.Select(p => new MqttBroker("127.0.0.1", p)).ToList(),
                "mes-edge-test",
                subs.Length > 0 ? subs : [new MqttSubscriptionSpec("plant/#", MqttPayloadFormat.Json, 1), new MqttSubscriptionSpec("spBv1.0/#", MqttPayloadFormat.SparkplugB, 0)],
                UseTls: false,
                WillTopic: will,
                KeepAlive: TimeSpan.FromSeconds(5),
                ChannelCapacity: capacity),
            dlq,
            NullLogger<MqttConnector>.Instance,
            backoff: FastBackoff);

    private static async Task<(Task Run, CancellationTokenSource Cts)> StartAsync(IConnector c)
    {
        var cts = new CancellationTokenSource();
        var run = Task.Run(() => c.RunAsync(cts.Token));
        await Poll.UntilAsync(() => c.Health.Status == ConnectorStatus.Connected, "connector Connected");
        return (run, cts);
    }

    private static byte[] SparkplugData(string? name, ulong? alias, double value, ulong tsMs)
    {
        using var ms = new MemoryStream();
        var m = new MemoryStream();
        var mo = new CodedOutputStream(m);
        if (name is not null) { mo.WriteTag(1, WireFormat.WireType.LengthDelimited); mo.WriteString(name); }
        if (alias is { } a) { mo.WriteTag(2, WireFormat.WireType.Varint); mo.WriteUInt64(a); }
        mo.WriteTag(4, WireFormat.WireType.Varint); mo.WriteUInt32(10); // Double
        mo.WriteTag(13, WireFormat.WireType.Fixed64); mo.WriteDouble(value);
        mo.Flush();

        var o = new CodedOutputStream(ms);
        o.WriteTag(1, WireFormat.WireType.Varint); o.WriteUInt64(tsMs);
        o.WriteTag(2, WireFormat.WireType.LengthDelimited); o.WriteBytes(ByteString.CopyFrom(m.ToArray()));
        o.Flush();
        return ms.ToArray();
    }

    [Fact]
    public async Task Connects_subscribes_and_parses_json_and_sparkplug_topics_end_to_end()
    {
        await using var broker = new TestBroker();
        await broker.StartAsync();
        var dlq = new RecordingDlq();
        await using var c = Connector([broker.Port], dlq);
        var (run, cts) = await StartAsync(c);

        await broker.PublishAsync("plant/line1/temp", """{"value": 21.5, "ts": 1700000000000}""");
        var json = await Poll.ReadAsync(c);
        json.SourceAddress.Should().Be("plant/line1/temp");
        json.NumericValue.Should().Be(21.5);
        json.SourceTime.Should().Be(DateTimeOffset.FromUnixTimeMilliseconds(1_700_000_000_000));

        await broker.PublishAsync("spBv1.0/g/DBIRTH/edge1/dev1", SparkplugData("Temp", 7, 1.0, 1_700_000_000_000));
        await broker.PublishAsync("spBv1.0/g/DDATA/edge1/dev1", SparkplugData(null, 7, 2.5, 1_700_000_001_000));
        (await Poll.ReadAsync(c)).NumericValue.Should().Be(1.0);
        var data = await Poll.ReadAsync(c);
        data.SourceAddress.Should().Be("edge1/dev1/Temp"); // alias resolved through the BIRTH learned over the wire
        data.NumericValue.Should().Be(2.5);

        await broker.PublishAsync("plant/line1/bad", "{not json");
        await broker.PublishAsync("plant/line1/schema", """{"value": null}""");
        await Poll.UntilAsync(() => dlq.Items.Count == 2, "two dead-lettered messages");
        dlq.Items.Select(i => i.Topic).Should().BeEquivalentTo(["plant/line1/bad", "plant/line1/schema"]);

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(10));
        c.Health.Status.Should().Be(ConnectorStatus.Stopped);
        (await c.Samples.WaitToReadAsync()).Should().BeFalse("the channel completes on shutdown");
    }

    [Fact]
    public async Task Publishes_retained_online_status_replacing_the_last_will()
    {
        await using var broker = new TestBroker();
        await broker.StartAsync();
        await using var c = Connector([broker.Port], new RecordingDlq(), will: "status/gw");
        var (run, cts) = await StartAsync(c);

        var observer = new MqttClientFactory().CreateMqttClient();
        var seen = new TaskCompletionSource<string>(TaskCreationOptions.RunContinuationsAsynchronously);
        observer.ApplicationMessageReceivedAsync += e =>
        {
            seen.TrySetResult(System.Text.Encoding.UTF8.GetString(System.Buffers.BuffersExtensions.ToArray(e.ApplicationMessage.Payload)));
            return Task.CompletedTask;
        };
        await observer.ConnectAsync(new MqttClientOptionsBuilder().WithTcpServer("127.0.0.1", broker.Port).WithClientId("observer").Build());
        await observer.SubscribeAsync(new MqttClientSubscribeOptionsBuilder().WithTopicFilter(f => f.WithTopic("status/gw")).Build());

        (await seen.Task.WaitAsync(TimeSpan.FromSeconds(10))).Should().Be("online"); // retained, delivered on subscribe
        observer.Dispose();
        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(10));
    }

    [Fact]
    public async Task Fails_over_to_the_second_broker_after_the_first_dies_and_recovers_data_flow()
    {
        await using var a = new TestBroker();
        await using var b = new TestBroker();
        await a.StartAsync();
        await b.StartAsync();
        await using var c = Connector([a.Port, b.Port], new RecordingDlq());
        var (run, cts) = await StartAsync(c);

        await a.PublishAsync("plant/x", """{"value": 1}""");
        (await Poll.ReadAsync(c)).NumericValue.Should().Be(1);

        await a.StopAsync(); // broker A dies
        await Poll.UntilAsync(() => b.ConnectedClients == 1, "connector reconnected to broker B");
        await Poll.UntilAsync(() => c.Health.Status == ConnectorStatus.Connected, "health back to Connected");
        c.Health.ConsecutiveFailures.Should().Be(0, "a successful reconnect resets the failure counter");

        await b.PublishAsync("plant/x", """{"value": 2}""");
        (await Poll.ReadAsync(c)).NumericValue.Should().Be(2);

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(10));
    }

    [Fact]
    public async Task Backs_off_with_failures_while_no_broker_is_reachable_then_connects_when_it_appears()
    {
        var port = FreePort.Get();
        await using var c = Connector([port], new RecordingDlq());
        using var cts = new CancellationTokenSource();
        var run = Task.Run(() => c.RunAsync(cts.Token));

        await Poll.UntilAsync(() => c.Health.ConsecutiveFailures >= 3, "three consecutive connection failures");
        c.Health.Status.Should().BeOneOf(ConnectorStatus.Backoff, ConnectorStatus.Connecting);
        c.Health.LastError.Should().NotBeNullOrEmpty();

        await using var late = new TestBroker(port);
        await late.StartAsync();
        await Poll.UntilAsync(() => c.Health.Status == ConnectorStatus.Connected, "connected once the broker is up");
        c.Health.ConsecutiveFailures.Should().Be(0);

        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(10));
    }

    [Fact]
    public async Task Slow_consumer_drops_and_counts_instead_of_stalling_the_connection()
    {
        await using var broker = new TestBroker();
        await broker.StartAsync();
        await using var c = Connector([broker.Port], new RecordingDlq(), capacity: 3);
        var (run, cts) = await StartAsync(c);

        for (var i = 0; i < 25; i++)
        {
            await broker.PublishAsync("plant/burst", $$"""{"value": {{i}}}""");
        }

        await Poll.UntilAsync(() => c.Health.DroppedSamples >= 22, "22 dropped samples");
        c.Health.Status.Should().Be(ConnectorStatus.Connected, "back-pressure must never drop the MQTT session");

        // The oldest three are kept (Wait + TryWrite semantic), in order; the consumer is not poisoned.
        var kept = new List<double>();
        for (var i = 0; i < 3; i++)
        {
            kept.Add((await Poll.ReadAsync(c)).NumericValue!.Value);
        }

        kept.Should().Equal(0, 1, 2);

        await broker.PublishAsync("plant/burst", """{"value": 99}""");
        (await Poll.ReadAsync(c)).NumericValue.Should().Be(99); // flow resumes once the consumer catches up
        await cts.CancelAsync();
        await run.WaitAsync(TimeSpan.FromSeconds(10));
    }
}
