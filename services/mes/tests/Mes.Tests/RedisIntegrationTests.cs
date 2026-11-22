using FluentAssertions;
using Mes.Application.Abstractions;
using Mes.Domain.Common;
using Mes.Domain.Oee;
using Mes.Domain.State;
using Mes.Infrastructure.Persistence;
using StackExchange.Redis;
using Testcontainers.Redis;
using Xunit;

namespace Mes.Tests;

/// <summary>Runs against <c>MES_TEST_REDIS</c> (CI service container) or a Testcontainers Redis; skipped only when neither exists.</summary>
public sealed class RedisFactAttribute : FactAttribute
{
    public RedisFactAttribute()
    {
        if (!RedisAvailable.Yes)
        {
            Skip = "No Redis available (set MES_TEST_REDIS or start Docker): integration test skipped.";
        }
    }
}

internal static class RedisAvailable
{
    public static string? External => Environment.GetEnvironmentVariable("MES_TEST_REDIS") is { Length: > 0 } s ? s : null;

    public static bool Yes => External is not null || DockerAvailable.Yes;
}

public sealed class RedisIntegrationTests : IAsyncLifetime
{
    private readonly RedisContainer? _container = RedisAvailable.External is null && DockerAvailable.Yes ? new RedisBuilder("redis:7.4-alpine").Build() : null;
    private IConnectionMultiplexer _redis = null!;

    public async Task InitializeAsync()
    {
        if (!RedisAvailable.Yes)
        {
            return;
        }

        if (_container is not null)
        {
            await _container.StartAsync();
        }

        _redis = await ConnectionMultiplexer.ConnectAsync(RedisAvailable.External ?? _container!.GetConnectionString());
    }

    public async Task DisposeAsync()
    {
        if (RedisAvailable.Yes)
        {
            await _redis.DisposeAsync();
        }

        if (_container is not null)
        {
            await _container.DisposeAsync();
        }
    }

    private static OeeLiveView View(TenantId t, MachineId m, long run)
    {
        var acc = new OeeAccumulator(100, run, 0, 0, 100 - run, 0, run * 0.9, run * 0.8, 10, 9, true);
        return new OeeLiveView(t, m, "shift", acc, OeeCalculator.Compute(acc), MachineState.Running, DateTimeOffset.UtcNow);
    }

    [RedisFact]
    public async Task Publishes_under_a_tenant_qualified_key_with_ttl_and_round_trips()
    {
        var store = new RedisOeeLiveStore(_redis);
        var a = TenantId.New();
        var b = TenantId.New();
        var m = MachineId.New();

        await store.PublishAsync(View(a, m, 80), default);

        var back = await store.GetAsync(a, m, "shift", default);
        back.Should().NotBeNull();
        back!.Acc.RunMs.Should().Be(80);
        back.State.Should().Be(MachineState.Running);
        back.Result.Oee.Should().NotBeNull(); // result survives serialisation

        (await store.GetAsync(b, m, "shift", default)).Should().BeNull("same machine id under another tenant is a different key");
        var ttl = await _redis.GetDatabase().KeyTimeToLiveAsync(RedisOeeLiveStore.Key(a, m, "shift"));
        ttl.Should().NotBeNull().And.Subject!.Value.Should().BeLessThanOrEqualTo(TimeSpan.FromMinutes(10));
        RedisOeeLiveStore.Key(a, m, "shift").Should().Be($"oee:live:{a}:{m}:shift");
    }

    [RedisFact]
    public async Task Publish_notifies_subscribers_so_every_replica_can_push_to_its_signalr_clients()
    {
        var store = new RedisOeeLiveStore(_redis);
        var t = TenantId.New();
        var m = MachineId.New();
        var got = new TaskCompletionSource<string>(TaskCreationOptions.RunContinuationsAsynchronously);
        var sub = _redis.GetSubscriber();
        await sub.SubscribeAsync(RedisChannel.Literal(RedisOeeLiveStore.UpdatesChannel), (_, key) =>
        {
            if ((string?)key == RedisOeeLiveStore.Key(t, m, "shift"))
            {
                got.TrySetResult((string)key!);
            }
        });

        await store.PublishAsync(View(t, m, 50), default);

        (await got.Task.WaitAsync(TimeSpan.FromSeconds(10))).Should().Be($"oee:live:{t}:{m}:shift");
    }
}
