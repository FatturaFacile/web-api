using System.Buffers.Binary;

namespace Mes.Infrastructure.Connectors.Modbus;

public enum ModbusDataType
{
    UInt16,
    Int16,
    UInt32,
    Int32,
    Float32,
    Float64,
}

/// <summary>
/// Byte/word permutation of a multi-register value, as two orthogonal flags.
/// Naming follows the usual convention where the value's big-endian bytes are A B C D (A = most significant):
/// <c>ABCD</c> = as is; <c>CDAB</c> = words reversed; <c>BADC</c> = bytes swapped inside each word; <c>DCBA</c> = both.
/// For 64-bit values the same flags apply to the whole 4-register sequence (ReverseWords reverses all four words).
/// </summary>
public readonly record struct ModbusByteOrder(bool ReverseWords, bool SwapBytes)
{
    public static ModbusByteOrder ABCD => new(false, false);

    public static ModbusByteOrder CDAB => new(true, false);

    public static ModbusByteOrder BADC => new(false, true);

    public static ModbusByteOrder DCBA => new(true, true);

    public static ModbusByteOrder Parse(string s) => s.ToUpperInvariant() switch
    {
        "ABCD" => ABCD,
        "CDAB" => CDAB,
        "BADC" => BADC,
        "DCBA" => DCBA,
        _ => throw new ArgumentException($"Unknown Modbus byte order '{s}'.", nameof(s)),
    };
}

public static class ModbusDecoder
{
    public static int RegisterCount(ModbusDataType t) => t switch
    {
        ModbusDataType.UInt16 or ModbusDataType.Int16 => 1,
        ModbusDataType.UInt32 or ModbusDataType.Int32 or ModbusDataType.Float32 => 2,
        ModbusDataType.Float64 => 4,
        _ => throw new ArgumentOutOfRangeException(nameof(t)),
    };

    /// <summary>Decodes <see cref="RegisterCount"/> registers (each one a big-endian 16-bit word on the wire) to a double.</summary>
    public static double Decode(ReadOnlySpan<ushort> registers, ModbusDataType type, ModbusByteOrder order)
    {
        var n = RegisterCount(type);
        if (registers.Length < n)
        {
            throw new ArgumentException($"{type} needs {n} register(s), got {registers.Length}.", nameof(registers));
        }

        Span<byte> bytes = stackalloc byte[8];
        for (var i = 0; i < n; i++)
        {
            var word = registers[order.ReverseWords ? n - 1 - i : i];
            if (order.SwapBytes)
            {
                word = BinaryPrimitives.ReverseEndianness(word);
            }

            BinaryPrimitives.WriteUInt16BigEndian(bytes[(i * 2)..], word);
        }

        var b = bytes[..(n * 2)];
        return type switch
        {
            ModbusDataType.UInt16 => BinaryPrimitives.ReadUInt16BigEndian(b),
            ModbusDataType.Int16 => BinaryPrimitives.ReadInt16BigEndian(b),
            ModbusDataType.UInt32 => BinaryPrimitives.ReadUInt32BigEndian(b),
            ModbusDataType.Int32 => BinaryPrimitives.ReadInt32BigEndian(b),
            ModbusDataType.Float32 => BinaryPrimitives.ReadSingleBigEndian(b),
            ModbusDataType.Float64 => BinaryPrimitives.ReadDoubleBigEndian(b),
            _ => throw new ArgumentOutOfRangeException(nameof(type)),
        };
    }
}

public sealed record ModbusTagSpec(string SourceAddress, ushort Register, ModbusDataType Type, ModbusByteOrder Order)
{
    public int Count => ModbusDecoder.RegisterCount(Type);

    public int EndExclusive => Register + Count;
}

public sealed record ReadBlock(ushort Start, ushort Count, IReadOnlyList<ModbusTagSpec> Tags);

public static class ModbusReadPlanner
{
    public const int MaxRegistersPerRequest = 125; // Modbus spec limit for FC03

    /// <summary>
    /// Coalesces holding-register tags into few requests: neighbouring tags are merged while the hole between them is
    /// at most <paramref name="maxGap"/> registers and the whole block stays within <paramref name="maxRegisters"/>.
    /// Reading a few unused registers is cheaper than another round-trip.
    /// </summary>
    public static IReadOnlyList<ReadBlock> Coalesce(IEnumerable<ModbusTagSpec> tags, int maxGap = 8, int maxRegisters = MaxRegistersPerRequest)
    {
        ArgumentOutOfRangeException.ThrowIfGreaterThan(maxRegisters, MaxRegistersPerRequest);
        var sorted = tags.OrderBy(t => t.Register).ThenBy(t => t.Count).ToList();
        var blocks = new List<ReadBlock>();
        List<ModbusTagSpec>? cur = null;
        var start = 0;
        var end = 0;

        foreach (var t in sorted)
        {
            if (t.Count > maxRegisters)
            {
                throw new ArgumentException($"Tag {t.SourceAddress} wider than a request.");
            }

            if (cur is not null && t.Register - end <= maxGap && Math.Max(end, t.EndExclusive) - start <= maxRegisters)
            {
                cur.Add(t);
                end = Math.Max(end, t.EndExclusive);
                continue;
            }

            if (cur is not null)
            {
                blocks.Add(new ReadBlock((ushort)start, (ushort)(end - start), cur));
            }

            cur = [t];
            start = t.Register;
            end = t.EndExclusive;
        }

        if (cur is not null)
        {
            blocks.Add(new ReadBlock((ushort)start, (ushort)(end - start), cur));
        }

        return blocks;
    }
}
