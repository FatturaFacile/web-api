using Mes.Domain.Common;
using Mes.Domain.Counters;
using Mes.Domain.State;
using Mes.Domain.Telemetry;
using Mes.Application.Abstractions;

namespace Mes.Application.Processing;

public readonly record struct CountEvent(DateTimeOffset At, long Total, long? Good);

public sealed record ProcessResult(
    IReadOnlyList<StateInterval> ClosedIntervals,
    StateInterval? OpenInterval,
    IReadOnlyList<CountEvent> Counts,
    IReadOnlyList<DateTimeOffset> AlarmEntries,
    DateTimeOffset? LastEventTime,
    IReadOnlyList<CounterCheckpoint> Checkpoints);

/// <summary>
/// Pure, synchronous per-machine processor (state machine + counter trackers + signal cache). Not thread-safe:
/// owned by one pipeline partition. Samples must be time-ordered; anything older than the last processed event
/// is dropped here (store-and-forward replays go through RecomputeWindow instead).
/// </summary>
public sealed class MachineProcessor
{
    private readonly MachineProfile _profile;
    private readonly IStateMapper _mapper;
    private readonly MachineStateMachine _machine;
    private readonly CounterTracker _total;
    private readonly CounterTracker _good;
    private bool? _running;
    private bool? _alarm;
    private bool? _setup;
    private double? _speed;
    private DateTimeOffset? _last;

    public const string TotalKey = "total";
    public const string GoodKey = "good";

    public MachineProcessor(MachineProfile profile, IStateMapper mapper, StateInterval? restoredOpen, IReadOnlyList<CounterCheckpoint>? checkpoints = null)
    {
        _profile = profile;
        _mapper = mapper;
        _machine = new MachineStateMachine(profile.MachineId, profile.Debounce);
        _total = new CounterTracker(profile.Counter);
        _good = new CounterTracker(profile.Counter);
        foreach (var cp in checkpoints ?? [])
        {
            (cp.Key == TotalKey ? _total : cp.Key == GoodKey ? _good : null)?.Restore(cp.Value);
        }

        if (restoredOpen is not null)
        {
            _machine.Restore(restoredOpen);
            _last = restoredOpen.Start;
        }
    }

    public MachineState State => _machine.State;

    public ProcessResult Process(IReadOnlyList<TelemetrySample> samples)
    {
        var closed = new List<StateInterval>();
        var counts = new List<CountEvent>();
        var alarms = new List<DateTimeOffset>();
        var totalBefore = _total.Last;
        var goodBefore = _good.Last;

        foreach (var s in samples.Where(x => x.IsUsable).OrderBy(x => x.Times.EventTime))
        {
            var at = s.Times.EventTime;
            if (_last is { } last && at < last)
            {
                continue;
            }

            if (_last is { } prev && at - prev > _profile.StaleAfter && _machine.Current is not null)
            {
                // Silence longer than the stale threshold: the gap is Unknown, not "whatever state it was in".
                if (_machine.ForceTransition(MachineState.Unknown, prev) is { } c1)
                {
                    closed.Add(c1);
                }

                ResetSignals();
            }

            _last = at;

            if (!_profile.Roles.TryGetValue(s.TagId, out var role))
            {
                continue;
            }

            ApplySignal(role, s, at, counts);

            var candidate = _mapper.Map(new StateSignals(_running, _alarm, _setup, _speed));
            var before = _machine.State;
            foreach (var done in _machine.Observe(candidate, at))
            {
                closed.Add(done);
            }

            if (_machine.State == MachineState.Alarm && before != MachineState.Alarm)
            {
                alarms.Add(_machine.Current!.Start);
            }
        }

        var checkpoints = new List<CounterCheckpoint>();
        if (_last is { } at0)
        {
            if (_total.Last is { } t && t != totalBefore)
            {
                checkpoints.Add(new CounterCheckpoint(TotalKey, t, at0));
            }

            if (_good.Last is { } g && g != goodBefore)
            {
                checkpoints.Add(new CounterCheckpoint(GoodKey, g, at0));
            }
        }

        return new ProcessResult(closed, _machine.Current, counts, alarms, _last, checkpoints);
    }

    private void ApplySignal(TagRole role, TelemetrySample s, DateTimeOffset at, List<CountEvent> counts)
    {
        var v = s.NumericValue;
        switch (role)
        {
            case TagRole.Running:
                _running = v is { } r ? r != 0 : null;
                break;
            case TagRole.Alarm:
                _alarm = v is { } a ? a != 0 : null;
                break;
            case TagRole.Setup:
                _setup = v is { } u ? u != 0 : null;
                break;
            case TagRole.Speed:
                _speed = v;
                break;
            case TagRole.TotalCounter when v is { } t:
                var td = _total.Observe((decimal)t);
                if (td.Delta > 0)
                {
                    counts.Add(new CountEvent(at, (long)td.Delta, null));
                }

                break;
            case TagRole.GoodCounter when v is { } g:
                var gd = _good.Observe((decimal)g);
                if (gd.Delta > 0)
                {
                    counts.Add(new CountEvent(at, 0, (long)gd.Delta));
                }

                break;
        }
    }

    private void ResetSignals()
    {
        _running = _alarm = _setup = null;
        _speed = null;
    }
}
