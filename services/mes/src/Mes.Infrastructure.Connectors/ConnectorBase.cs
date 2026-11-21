using System.Threading.Channels;
using Mes.Application.Abstractions;
using Microsoft.Extensions.Logging;

namespace Mes.Infrastructure.Connectors;

public sealed record BackoffOptions(TimeSpan Initial, TimeSpan Max, double Multiplier = 2.0)
{
    public static BackoffOptions Default { get; } = new(TimeSpan.FromSeconds(1), TimeSpan.FromSeconds(60));
}

public static class Backoff
{
    /// <summary>
    /// "Full jitter" exponential backoff: uniform in [0, min(max, initial * mult^attempt)], floored at initial/2 so a
    /// reconnect storm of many gateways spreads out but never spins hot.
    /// </summary>
    public static TimeSpan Delay(int attempt, BackoffOptions o, Random rng)
    {
        var exp = Math.Min(o.Max.TotalMilliseconds, o.Initial.TotalMilliseconds * Math.Pow(o.Multiplier, Math.Min(attempt, 30)));
        var jittered = rng.NextDouble() * exp;
        return TimeSpan.FromMilliseconds(Math.Max(o.Initial.TotalMilliseconds / 2, jittered));
    }
}

/// <summary>
/// Shared connector lifecycle: bounded output channel, reconnect loop with backoff + jitter, health reporting.
/// <para>Threading: protocol libraries invoke callbacks on their own threads, so <see cref="TryEmit"/> is strictly
/// non-blocking (<c>TryWrite</c>); a full channel drops the new sample and counts it (surfaced in <see cref="Health"/>).
/// The single consumer is the edge pipeline.</para>
/// </summary>
public abstract class ConnectorBase : IConnector
{
    private readonly Channel<RawSample> _channel;
    private readonly BackoffOptions _backoff;
    private readonly Random _rng = new();
    private readonly TimeProvider _time;
    private long _dropped;
    private ConnectorHealth _health;

    protected ConnectorBase(string name, int channelCapacity, ILogger logger, TimeProvider? time = null, BackoffOptions? backoff = null)
    {
        Name = name;
        Logger = logger;
        _time = time ?? TimeProvider.System;
        _backoff = backoff ?? BackoffOptions.Default;
        _channel = Channel.CreateBounded<RawSample>(new BoundedChannelOptions(channelCapacity)
        {
            FullMode = BoundedChannelFullMode.Wait, // TryWrite returns false when full; we never block a protocol thread
            SingleReader = true,
            SingleWriter = false,
        });
        _health = new ConnectorHealth(ConnectorStatus.Stopped, _time.GetUtcNow(), 0, null, 0);
    }

    public string Name { get; }

    public ConnectorHealth Health => Volatile.Read(ref _health) with { DroppedSamples = Interlocked.Read(ref _dropped) };

    public ChannelReader<RawSample> Samples => _channel.Reader;

    protected ILogger Logger { get; }

    protected TimeProvider Time => _time;

    /// <summary>Connect, run until the connection is lost (return) or fails (throw). Must honour <paramref name="ct"/>.</summary>
    protected abstract Task ConnectAndRunAsync(CancellationToken ct);

    protected bool TryEmit(RawSample sample)
    {
        if (_channel.Writer.TryWrite(sample))
        {
            return true;
        }

        Interlocked.Increment(ref _dropped);
        return false;
    }

    protected void MarkConnected() => Set(ConnectorStatus.Connected, 0, null);

    public async Task RunAsync(CancellationToken ct)
    {
        var attempt = 0;
        try
        {
            while (!ct.IsCancellationRequested)
            {
                Set(ConnectorStatus.Connecting, attempt, _health.LastError);
                try
                {
                    await ConnectAndRunAsync(ct).ConfigureAwait(false);
                    attempt = 0; // a clean return after having been connected restarts the backoff ladder
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    break;
                }
                catch (Exception ex)
                {
                    attempt++;
                    Logger.LogWarning(ex, "Connector {Name} failed (attempt {Attempt})", Name, attempt);
                    Set(ConnectorStatus.Backoff, attempt, ex.Message);
                }

                var delay = Backoff.Delay(attempt, _backoff, _rng);
                await Task.Delay(delay, _time, ct).ConfigureAwait(false);
            }
        }
        catch (OperationCanceledException)
        {
            // normal shutdown
        }
        finally
        {
            Set(ConnectorStatus.Stopped, 0, _health.LastError);
            _channel.Writer.TryComplete();
        }
    }

    private void Set(ConnectorStatus status, int failures, string? error) =>
        Volatile.Write(ref _health, new ConnectorHealth(status, _time.GetUtcNow(), failures, error, 0));

    public virtual ValueTask DisposeAsync()
    {
        _channel.Writer.TryComplete();
        return ValueTask.CompletedTask;
    }
}
