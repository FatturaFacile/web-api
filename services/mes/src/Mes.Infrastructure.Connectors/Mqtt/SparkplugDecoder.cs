using Google.Protobuf;

namespace Mes.Infrastructure.Connectors.Mqtt;

public sealed record SparkplugMetric(string? Name, ulong? Alias, ulong? TimestampMs, uint DataType, double? Numeric, string? Text, bool IsNull, bool IsHistorical);

public sealed record SparkplugPayload(ulong? TimestampMs, ulong? Seq, IReadOnlyList<SparkplugMetric> Metrics);

/// <summary>
/// Minimal Sparkplug B payload decoder (Eclipse Tahu <c>Payload</c> / <c>Payload.Metric</c> wire format) built on
/// Google.Protobuf's <see cref="CodedInputStream"/>, so no generated classes or protoc toolchain are needed.
/// Only the fields the MES needs are read; everything else (templates, datasets, properties) is skipped safely.
/// </summary>
public static class SparkplugDecoder
{
    // Sparkplug DataType enum values.
    private const uint Int8 = 1, Int16 = 2, Int32 = 3, Int64 = 4, UInt8 = 5, UInt16 = 6, UInt32 = 7, UInt64 = 8, Float = 9, Double = 10, Boolean = 11, String = 12;

    public static SparkplugPayload Decode(ReadOnlySpan<byte> bytes)
    {
        var input = new CodedInputStream(bytes.ToArray());
        ulong? ts = null, seq = null;
        var metrics = new List<SparkplugMetric>();
        uint tag;
        while ((tag = input.ReadTag()) != 0)
        {
            switch (WireFormat.GetTagFieldNumber(tag))
            {
                case 1:
                    ts = input.ReadUInt64();
                    break;
                case 2:
                    metrics.Add(DecodeMetric(input.ReadBytes().ToByteArray()));
                    break;
                case 3:
                    seq = input.ReadUInt64();
                    break;
                default:
                    input.SkipLastField();
                    break;
            }
        }

        return new SparkplugPayload(ts, seq, metrics);
    }

    private static SparkplugMetric DecodeMetric(byte[] bytes)
    {
        var input = new CodedInputStream(bytes);
        string? name = null, text = null;
        ulong? alias = null, ts = null;
        uint dataType = 0;
        bool isNull = false, historical = false;
        ulong? raw = null;
        double? dbl = null;
        bool? boolean = null;
        uint tag;
        while ((tag = input.ReadTag()) != 0)
        {
            switch (WireFormat.GetTagFieldNumber(tag))
            {
                case 1: name = input.ReadString(); break;
                case 2: alias = input.ReadUInt64(); break;
                case 3: ts = input.ReadUInt64(); break;
                case 4: dataType = input.ReadUInt32(); break;
                case 5: historical = input.ReadBool(); break;
                case 7: isNull = input.ReadBool(); break;
                case 10: raw = input.ReadUInt32(); break;
                case 11: raw = input.ReadUInt64(); break;
                case 12: dbl = input.ReadFloat(); break;
                case 13: dbl = input.ReadDouble(); break;
                case 14: boolean = input.ReadBool(); break;
                case 15: text = input.ReadString(); break;
                default: input.SkipLastField(); break;
            }
        }

        double? numeric = null;
        if (!isNull)
        {
            numeric = dataType switch
            {
                Int8 when raw is { } r => (sbyte)(byte)r,
                Int16 when raw is { } r => (short)(ushort)r,
                Int32 when raw is { } r => (int)(uint)r,
                Int64 when raw is { } r => (long)r,
                UInt8 or UInt16 or UInt32 or UInt64 when raw is { } r => r,
                Float or Double => dbl,
                Boolean => boolean is { } b ? (b ? 1d : 0d) : null,
                String => null,
                _ => dbl ?? (raw is { } rr ? rr : (boolean is { } bb ? (bb ? 1d : 0d) : null)),
            };
        }

        return new SparkplugMetric(name, alias, ts, dataType, numeric, text, isNull, historical);
    }
}

/// <summary>Per edge-node/device alias table, rebuilt on every BIRTH (Sparkplug data messages may carry only aliases).</summary>
public sealed class SparkplugAliasMap
{
    private readonly Dictionary<(string Edge, string Device), Dictionary<ulong, string>> _map = [];

    public void LearnBirth(string edge, string device, SparkplugPayload birth)
    {
        var d = new Dictionary<ulong, string>();
        foreach (var m in birth.Metrics)
        {
            if (m.Name is not null && m.Alias is { } a)
            {
                d[a] = m.Name;
            }
        }

        _map[(edge, device)] = d;
    }

    public void Forget(string edge, string device) => _map.Remove((edge, device));

    public string? Resolve(string edge, string device, SparkplugMetric m) =>
        m.Name ?? (m.Alias is { } a && _map.TryGetValue((edge, device), out var d) && d.TryGetValue(a, out var n) ? n : null);
}
