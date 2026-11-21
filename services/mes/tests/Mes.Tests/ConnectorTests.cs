using System.Net;
using FluentAssertions;
using Google.Protobuf;
using Mes.Application.Abstractions;
using Mes.Application.Contracts;
using Mes.Infrastructure.Connectors;
using Mes.Infrastructure.Connectors.Modbus;
using Mes.Infrastructure.Connectors.Mqtt;
using Microsoft.Extensions.Logging.Abstractions;
using Xunit;

namespace Mes.Tests;

public class ModbusDecoderTests
{
    // 42.0f = 0x42280000, A=42 B=28 C=00 D=00
    public static TheoryData<string, ushort[]> Float32Vectors => new()
    {
        { "ABCD", [0x4228, 0x0000] },
        { "CDAB", [0x0000, 0x4228] },
        { "BADC", [0x2842, 0x0000] },
        { "DCBA", [0x0000, 0x2842] },
    };

    // 1.0 = 0x3FF0_0000_0000_0000
    public static TheoryData<string, ushort[]> Float64Vectors => new()
    {
        { "ABCD", [0x3FF0, 0, 0, 0] },
        { "CDAB", [0, 0, 0, 0x3FF0] },
        { "BADC", [0xF03F, 0, 0, 0] },
        { "DCBA", [0, 0, 0, 0xF03F] },
    };

    [Theory]
    [MemberData(nameof(Float32Vectors))]
    public void Float32_42_decodes_in_every_order(string order, ushort[] regs) =>
        ModbusDecoder.Decode(regs, ModbusDataType.Float32, ModbusByteOrder.Parse(order)).Should().Be(42.0);

    [Theory]
    [MemberData(nameof(Float64Vectors))]
    public void Float64_1_decodes_in_every_order(string order, ushort[] regs) =>
        ModbusDecoder.Decode(regs, ModbusDataType.Float64, ModbusByteOrder.Parse(order)).Should().Be(1.0);

    [Fact]
    public void Integer_types()
    {
        ModbusDecoder.Decode([0xFFFE], ModbusDataType.Int16, ModbusByteOrder.ABCD).Should().Be(-2);
        ModbusDecoder.Decode([0xFFFE], ModbusDataType.UInt16, ModbusByteOrder.ABCD).Should().Be(65534);
        ModbusDecoder.Decode([0x0100], ModbusDataType.UInt16, ModbusByteOrder.BADC).Should().Be(1); // byte swap
        ModbusDecoder.Decode([0x0001, 0x0000], ModbusDataType.UInt32, ModbusByteOrder.CDAB).Should().Be(1);
        ModbusDecoder.Decode([0xFFFF, 0xFFFF], ModbusDataType.Int32, ModbusByteOrder.ABCD).Should().Be(-1);
        ModbusDecoder.Decode([0xFFFF, 0xFFFF], ModbusDataType.UInt32, ModbusByteOrder.ABCD).Should().Be(uint.MaxValue);
    }

    [Fact]
    public void Too_few_registers_throws() =>
        FluentActions.Invoking(() => ModbusDecoder.Decode([1], ModbusDataType.Float32, ModbusByteOrder.ABCD)).Should().Throw<ArgumentException>();

    [Fact]
    public void Planner_coalesces_within_gap_and_splits_beyond()
    {
        var tags = new[]
        {
            new ModbusTagSpec("a", 0, ModbusDataType.Float32, ModbusByteOrder.ABCD),
            new ModbusTagSpec("b", 4, ModbusDataType.UInt16, ModbusByteOrder.ABCD),   // gap 2 -> merged
            new ModbusTagSpec("c", 13, ModbusDataType.UInt16, ModbusByteOrder.ABCD),  // gap 8 (5..13) -> merged (<= 8)
            new ModbusTagSpec("d", 23, ModbusDataType.UInt16, ModbusByteOrder.ABCD),  // gap 9 -> new block
            new ModbusTagSpec("e", 1000, ModbusDataType.Float64, ModbusByteOrder.ABCD),
        };

        var blocks = ModbusReadPlanner.Coalesce(tags);

        blocks.Should().HaveCount(3);
        blocks[0].Start.Should().Be(0);
        blocks[0].Count.Should().Be(14);
        blocks[0].Tags.Select(t => t.SourceAddress).Should().Equal("a", "b", "c");
        blocks[1].Start.Should().Be(23);
        blocks[2].Count.Should().Be(4);
    }

    [Fact]
    public void Planner_never_exceeds_125_registers_per_request()
    {
        var tags = Enumerable.Range(0, 100).Select(i => new ModbusTagSpec($"t{i}", (ushort)(i * 2), ModbusDataType.Float32, ModbusByteOrder.ABCD));
        var blocks = ModbusReadPlanner.Coalesce(tags);
        blocks.Should().OnlyContain(b => b.Count <= 125);
        blocks.Sum(b => b.Tags.Count).Should().Be(100);
        blocks.Count.Should().BeGreaterThan(1);
    }

    [Fact]
    public void Read_only_surface_has_no_write_capability()
    {
        var banned = new[] { "Write", "Mask", "ReadWrite", "Set", "Send" };
        foreach (var t in new[] { typeof(IReadOnlyModbusMaster), typeof(ModbusTcpMaster) })
        {
            t.GetMethods().Select(m => m.Name).Where(n => banned.Any(b => n.StartsWith(b, StringComparison.Ordinal)))
                .Should().BeEmpty($"{t.Name} must stay read-only");
        }

        typeof(IReadOnlyModbusMaster).GetMethods().Select(m => m.Name).Should().BeEquivalentTo(["ConnectAsync", "ReadHoldingRegistersAsync"]);
    }

    [Fact]
    public void Banned_symbols_file_forbids_common_modbus_write_apis()
    {
        var file = Directory.GetFiles(AppContext.BaseDirectory, "BannedSymbols.txt", SearchOption.AllDirectories).FirstOrDefault()
                   ?? FindUp("src/Mes.Infrastructure.Connectors/BannedSymbols.txt");
        File.ReadAllText(file).Should().Contain("WriteSingleRegister").And.Contain("WriteMultipleRegisters").And.Contain("IModbusMaster");
    }

    private static string FindUp(string rel)
    {
        for (var d = new DirectoryInfo(AppContext.BaseDirectory); d is not null; d = d.Parent)
        {
            var p = Path.Combine(d.FullName, rel);
            if (File.Exists(p))
            {
                return p;
            }
        }

        throw new FileNotFoundException(rel);
    }
}

public class BackoffAndBatchingTests
{
    [Fact]
    public void Backoff_is_bounded_and_grows()
    {
        var o = new BackoffOptions(TimeSpan.FromSeconds(1), TimeSpan.FromSeconds(30));
        var rng = new Random(1);
        for (var attempt = 0; attempt < 40; attempt++)
        {
            var d = Backoff.Delay(attempt, o, rng);
            d.Should().BeLessThanOrEqualTo(TimeSpan.FromSeconds(30)).And.BeGreaterThanOrEqualTo(TimeSpan.FromMilliseconds(500));
        }

        Enumerable.Range(0, 200).Max(_ => Backoff.Delay(6, o, rng)).Should().BeGreaterThan(TimeSpan.FromSeconds(20));
    }

    [Fact]
    public async Task Batcher_closes_on_count()
    {
        var ch = System.Threading.Channels.Channel.CreateUnbounded<int>();
        for (var i = 0; i < 10; i++)
        {
            ch.Writer.TryWrite(i);
        }

        ch.Writer.Complete();
        var batches = new List<IReadOnlyList<int>>();
        await foreach (var b in ChannelBatching.ReadBatchesAsync(ch.Reader, 4, TimeSpan.FromSeconds(30)))
        {
            batches.Add(b);
        }

        batches.Select(b => b.Count).Should().Equal(4, 4, 2);
        batches.SelectMany(b => b).Should().Equal(Enumerable.Range(0, 10));
    }

    [Fact]
    public async Task Batcher_closes_on_time_after_first_item_and_yields_nothing_when_idle()
    {
        var ch = System.Threading.Channels.Channel.CreateUnbounded<int>();
        using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(5));
        var e = ChannelBatching.ReadBatchesAsync(ch.Reader, 100, TimeSpan.FromMilliseconds(150), cts.Token).GetAsyncEnumerator(cts.Token);

        ch.Writer.TryWrite(1);
        ch.Writer.TryWrite(2);
        (await e.MoveNextAsync()).Should().BeTrue();
        e.Current.Should().Equal(1, 2); // flushed by the timer although far below maxCount

        var pending = e.MoveNextAsync().AsTask();
        await Task.Delay(400);
        pending.IsCompleted.Should().BeFalse("an empty window must not produce an empty batch");
        ch.Writer.Complete();
        (await pending).Should().BeFalse();
    }
}

public class MqttTests
{
    private static MqttConnector NewConnector(IDeadLetterSink dlq) =>
        new("t", new MqttConnectorOptions([new MqttBroker("localhost")], "c", []), dlq, NullLogger<MqttConnector>.Instance);

    private sealed class Dlq : IDeadLetterSink
    {
        public List<(string Topic, string Reason)> Items { get; } = [];

        public void Write(string source, string topic, ReadOnlyMemory<byte> payload, string reason) => Items.Add((topic, reason));
    }

    private static byte[] Metric(string? name, ulong? alias, uint type, Action<CodedOutputStream> value, ulong? ts = null)
    {
        using var ms = new MemoryStream();
        var o = new CodedOutputStream(ms);
        if (name is not null) { o.WriteTag(1, WireFormat.WireType.LengthDelimited); o.WriteString(name); }
        if (alias is { } a) { o.WriteTag(2, WireFormat.WireType.Varint); o.WriteUInt64(a); }
        if (ts is { } t) { o.WriteTag(3, WireFormat.WireType.Varint); o.WriteUInt64(t); }
        o.WriteTag(4, WireFormat.WireType.Varint);
        o.WriteUInt32(type);
        value(o);
        o.Flush();
        return ms.ToArray();
    }

    private static byte[] Payload(ulong ts, params byte[][] metrics)
    {
        using var ms = new MemoryStream();
        var o = new CodedOutputStream(ms);
        o.WriteTag(1, WireFormat.WireType.Varint);
        o.WriteUInt64(ts);
        foreach (var m in metrics)
        {
            o.WriteTag(2, WireFormat.WireType.LengthDelimited);
            o.WriteBytes(ByteString.CopyFrom(m));
        }

        o.Flush();
        return ms.ToArray();
    }

    [Fact]
    public async Task Sparkplug_birth_teaches_aliases_used_by_later_data()
    {
        var dlq = new Dlq();
        await using var c = NewConnector(dlq);

        var birth = Payload(1_700_000_000_000, Metric("Temp", 7, 10, o => { o.WriteTag(13, WireFormat.WireType.Fixed64); o.WriteDouble(21.5); }));
        c.Handle("spBv1.0/g/DBIRTH/edge1/dev1", birth);

        var data = Payload(1_700_000_001_000, Metric(null, 7, 10, o => { o.WriteTag(13, WireFormat.WireType.Fixed64); o.WriteDouble(22.5); }));
        c.Handle("spBv1.0/g/DDATA/edge1/dev1", data);

        var cnt = Payload(1_700_000_002_000, Metric("Count", null, 3, o => { o.WriteTag(10, WireFormat.WireType.Varint); o.WriteUInt32(uint.MaxValue); })); // Int32 -1
        c.Handle("spBv1.0/g/DDATA/edge1/dev1", cnt);

        c.Samples.TryRead(out var s1).Should().BeTrue();
        s1!.SourceAddress.Should().Be("edge1/dev1/Temp");
        s1.NumericValue.Should().Be(21.5);
        s1.SourceTime.Should().Be(DateTimeOffset.FromUnixTimeMilliseconds(1_700_000_000_000));
        c.Samples.TryRead(out var s2).Should().BeTrue();
        s2!.SourceAddress.Should().Be("edge1/dev1/Temp");
        s2.NumericValue.Should().Be(22.5);
        c.Samples.TryRead(out var s3).Should().BeTrue();
        s3!.NumericValue.Should().Be(-1);
        dlq.Items.Should().BeEmpty();
        await Task.CompletedTask;
    }

    [Fact]
    public void Sparkplug_unknown_alias_after_missed_birth_goes_to_dlq()
    {
        var dlq = new Dlq();
        var c = NewConnector(dlq);
        c.Handle("spBv1.0/g/DDATA/e/d", Payload(1, Metric(null, 99, 10, o => { o.WriteTag(13, WireFormat.WireType.Fixed64); o.WriteDouble(1); })));
        dlq.Items.Should().ContainSingle().Which.Reason.Should().Contain("alias");
        c.Samples.TryRead(out _).Should().BeFalse();
    }

    [Theory]
    [InlineData("""{"value": 12.5}""", true)]
    [InlineData("""{"tag":"x","value":true,"ts":1700000000000}""", true)]
    [InlineData("""{"tag":"x","value":"RUN","ts":"2026-01-01T00:00:00Z"}""", true)]
    [InlineData("""{"value": null}""", false)]
    [InlineData("""{"tag": 5, "value": 1}""", false)]
    [InlineData("""{"value": 1, "ts": "garbage"}""", false)]
    [InlineData("""[1,2]""", false)]
    [InlineData("not json", false)]
    public void Json_schema_validation(string json, bool ok)
    {
        MqttJsonPayload.TryParse("plant/line1/temp", System.Text.Encoding.UTF8.GetBytes(json), DateTimeOffset.UtcNow, out var sample, out var error).Should().Be(ok);
        (sample is not null).Should().Be(ok);
        (error is null).Should().Be(ok);
    }

    [Fact]
    public void Invalid_json_message_is_dead_lettered_not_thrown()
    {
        var dlq = new Dlq();
        var c = NewConnector(dlq);
        c.Handle("plant/x", "{broken"u8.ToArray());
        dlq.Items.Should().ContainSingle();
    }

    [Fact]
    public void Full_channel_drops_and_counts_instead_of_blocking()
    {
        var c = new MqttConnector("t", new MqttConnectorOptions([new MqttBroker("h")], "c", [], ChannelCapacity: 2), new NullDeadLetterSink(), NullLogger<MqttConnector>.Instance);
        for (var i = 0; i < 5; i++)
        {
            c.Handle("t", """{"value":1}"""u8.ToArray());
        }

        c.Health.DroppedSamples.Should().Be(3);
    }
}
