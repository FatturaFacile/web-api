using Mes.Domain.Common;

namespace Mes.Domain.State;

public enum MachineState : byte
{
    Unknown = 0,
    Running = 1,
    Setup = 2,
    Alarm = 3,
    Down = 4,
}

/// <summary>A contiguous period in one state. <see cref="End"/> is null while the interval is open.</summary>
public sealed record StateInterval(MachineId MachineId, MachineState State, DateTimeOffset Start, DateTimeOffset? End, ReasonCode? Reason = null)
{
    public bool IsOpen => End is null;

    public Interval ToInterval(DateTimeOffset openEnd) => new(Start, End ?? openEnd);
}

/// <summary>Raw signals the mapper turns into a candidate state.</summary>
public readonly record struct StateSignals(bool? Running, bool? Alarm, bool? Setup, double? SpeedRpm);

public interface IStateMapper
{
    MachineState Map(in StateSignals signals);
}

/// <summary>Default mapping: Alarm &gt; Setup &gt; Running (flag or speed &gt; threshold) &gt; Down; no signal at all = Unknown.</summary>
public sealed class SignalStateMapper(double runningRpmThreshold = 0.5) : IStateMapper
{
    public MachineState Map(in StateSignals s)
    {
        if (s.Alarm == true)
        {
            return MachineState.Alarm;
        }

        if (s.Setup == true)
        {
            return MachineState.Setup;
        }

        if (s.Running == true || s.SpeedRpm is { } rpm && rpm > runningRpmThreshold)
        {
            return MachineState.Running;
        }

        if (s.Running is null && s.SpeedRpm is null && s.Alarm is null && s.Setup is null)
        {
            return MachineState.Unknown;
        }

        return MachineState.Down;
    }
}

/// <summary>
/// Debounced state machine for one machine. NOT thread-safe: it is owned by exactly one pipeline partition
/// (single writer per machine). Observations must arrive in event-time order; older ones are ignored.
/// <list type="bullet">
/// <item>A candidate state is committed only after it has been observed continuously for <c>debounce</c>.</item>
/// <item>On commit the transition is back-dated to the first instant the candidate was seen (<c>firstSeenAt</c>),
/// clamped so it never precedes the start of the interval being closed.</item>
/// <item>Excursions shorter than the debounce (micro-stops) are absorbed and never produce an interval.</item>
/// </list>
/// </summary>
public sealed class MachineStateMachine
{
    private readonly MachineId _machineId;
    private readonly TimeSpan _debounce;
    private StateInterval? _current;
    private (MachineState State, DateTimeOffset FirstSeenAt)? _pending;
    private DateTimeOffset _lastObserved = DateTimeOffset.MinValue;

    public MachineStateMachine(MachineId machineId, TimeSpan debounce)
    {
        ArgumentOutOfRangeException.ThrowIfLessThan(debounce, TimeSpan.Zero);
        _machineId = machineId;
        _debounce = debounce;
    }

    public StateInterval? Current => _current;

    public MachineState State => _current?.State ?? MachineState.Unknown;

    /// <summary>Feed one observation; returns the intervals closed by it (zero or one).</summary>
    public IReadOnlyList<StateInterval> Observe(MachineState observed, DateTimeOffset at)
    {
        at = at.ToUniversalTime();
        if (at < _lastObserved)
        {
            return [];
        }

        _lastObserved = at;

        if (_current is null)
        {
            _current = new StateInterval(_machineId, observed, at, null);
            return [];
        }

        if (observed == _current.State)
        {
            _pending = null; // fell back before the debounce elapsed: micro-stop absorbed
            return [];
        }

        if (_pending is not { } p || p.State != observed)
        {
            _pending = (observed, at);
        }

        if (at - _pending.Value.FirstSeenAt < _debounce)
        {
            return [];
        }

        var boundary = _pending.Value.FirstSeenAt > _current.Start ? _pending.Value.FirstSeenAt : _current.Start;
        var closed = _current with { End = boundary };
        _current = new StateInterval(_machineId, observed, boundary, null);
        _pending = null;
        return boundary == closed.Start ? [] : [closed]; // never emit a zero-length interval
    }

    /// <summary>
    /// Immediate transition without debounce (data gap -> Unknown, then resume). Clamped to the current interval start.
    /// </summary>
    public StateInterval? ForceTransition(MachineState state, DateTimeOffset at)
    {
        at = at.ToUniversalTime();
        _pending = null;
        if (_current is null)
        {
            _current = new StateInterval(_machineId, state, at, null);
            _lastObserved = at;
            return null;
        }

        if (_current.State == state)
        {
            return null;
        }

        var boundary = at > _current.Start ? at : _current.Start;
        var closed = _current with { End = boundary };
        _current = new StateInterval(_machineId, state, boundary, null);
        if (boundary > _lastObserved)
        {
            _lastObserved = boundary;
        }

        return closed;
    }

    /// <summary>Seeds the machine from a persisted open interval after restart (pending debounce is not persisted).</summary>
    public void Restore(StateInterval open)
    {
        _current = open;
        _lastObserved = open.Start;
        _pending = null;
    }

    /// <summary>Closes the open interval at <paramref name="at"/> and reopens it (shift end, shutdown, bucket flush).</summary>
    public StateInterval? Flush(DateTimeOffset at)
    {
        at = at.ToUniversalTime();
        if (_current is null || at <= _current.Start)
        {
            return null;
        }

        var closed = _current with { End = at };
        _current = _current with { Start = at };
        return closed;
    }
}
