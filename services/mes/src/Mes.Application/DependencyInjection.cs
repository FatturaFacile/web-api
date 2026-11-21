using Mes.Application.Abstractions;
using Mes.Application.Commands;
using Mes.Application.Processing;
using Mes.Domain.State;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;

namespace Mes.Application;

public static class ApplicationServiceCollectionExtensions
{
    public static IServiceCollection AddMesApplication(this IServiceCollection services)
    {
        services.TryAddSingleton(TimeProvider.System);
        services.TryAddSingleton<IStateMapper, SignalStateMapper>();
        services.TryAddSingleton<IShiftCalendarProvider, AlwaysPlannedCalendarProvider>();

        services.AddSingleton<MachineProcessorRegistry>();
        services.AddSingleton<LiveViewBuilder>();
        services.AddSingleton<WindowRecomputer>();
        services.AddSingleton<IngestTelemetryBatchHandler>();
        services.AddSingleton<RecomputeWindowHandler>();
        services.AddSingleton<ClassifyDowntimeHandler>();
        services.AddSingleton<ReportScrapHandler>();
        services.AddSingleton<SplitIntervalHandler>();
        services.AddSingleton<CompleteProductionRunHandler>();
        services.AddSingleton<CloseShiftHandler>();
        return services;
    }
}
