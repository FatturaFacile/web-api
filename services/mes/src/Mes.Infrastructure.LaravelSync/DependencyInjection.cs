using Mes.Application.Abstractions;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Options;

namespace Mes.Infrastructure.LaravelSync;

public static class LaravelSyncServiceCollectionExtensions
{
    public static IServiceCollection AddLaravelSync(this IServiceCollection services, Action<LaravelSyncOptions> configure, Action<OutboxRelayOptions>? relay = null)
    {
        services.TryAddSingleton(TimeProvider.System);
        services.Configure(configure);
        services.Configure(relay ?? (_ => { }));
        services.AddSingleton(sp => sp.GetRequiredService<IOptions<LaravelSyncOptions>>().Value);
        services.AddTransient<HmacSigningHandler>();

        // Registration order = pipeline order (first = outermost): resilience OUTSIDE signing, so every retry is
        // re-signed with a fresh timestamp instead of replaying a signature that may have aged out.
        services.AddHttpClient<IManufacturingReferenceSync, LaravelReferenceSyncClient>((sp, c) => c.BaseAddress = new Uri(sp.GetRequiredService<LaravelSyncOptions>().BaseUrl))
            .AddStandardResilienceHandler().Services
            .AddHttpClient<IManufacturingReferenceSync, LaravelReferenceSyncClient>()
            .AddHttpMessageHandler<HmacSigningHandler>();

        services.AddHttpClient<OutboxRelay>((sp, c) => c.BaseAddress = new Uri(sp.GetRequiredService<LaravelSyncOptions>().BaseUrl))
            .AddHttpMessageHandler<HmacSigningHandler>(); // relay does its own scheduling/backoff, so no extra retry layer
        services.AddHostedService(sp => sp.GetRequiredService<OutboxRelay>());
        services.AddHostedService<ReferenceSyncWorker>();
        return services;
    }
}
