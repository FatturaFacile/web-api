<#
.SYNOPSIS
    Serialises local test runs across all checkouts/worktrees on this machine.

.DESCRIPTION
    Holds a machine-wide named mutex while the wrapped command runs, so two agents (or an agent and a
    human) never run parallel suites at the same time and thrash the CPU. Later callers queue and start
    as soon as the previous run ends. A crashed holder releases the mutex automatically (abandoned mutex).

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts/test-lock.ps1 php artisan test --parallel --processes=2

.NOTES
    TEST_LOCK_TIMEOUT_MIN  Max minutes to wait for the lock (default 90).
    TEST_LOCK_BYPASS=1     Skip the lock (CI, or a single-process run on an idle machine).
#>
# Plain $args (no param block/[Parameter]): an advanced script would bind `-d` to the -Debug common
# parameter and silently drop it from `php -d memory_limit=...`.
[string[]] $Command = $args

if ($Command.Count -eq 0) {
    Write-Host 'Usage: test-lock.ps1 <command> [args...]'
    exit 2
}

$ErrorActionPreference = 'Stop'

if ($env:TEST_LOCK_BYPASS -eq '1') {
    & $Command[0] @($Command | Select-Object -Skip 1)
    exit $LASTEXITCODE
}

$timeoutMin = if ($env:TEST_LOCK_TIMEOUT_MIN) { [int] $env:TEST_LOCK_TIMEOUT_MIN } else { 90 }
$mutex = New-Object System.Threading.Mutex($false, 'Global\FatturaFacileLocalTests')
$held = $false
$started = Get-Date

try {
    $waitedAnnounced = $false
    while (-not $held) {
        try {
            $held = $mutex.WaitOne(10000)
        } catch [System.Threading.AbandonedMutexException] {
            $held = $true # previous holder died; we own it now
        }
        if (-not $held) {
            if (-not $waitedAnnounced) {
                Write-Host "test-lock: another local test run is active, queueing (max $timeoutMin min)..."
                $waitedAnnounced = $true
            }
            if (((Get-Date) - $started).TotalMinutes -ge $timeoutMin) {
                Write-Host "test-lock: gave up after $timeoutMin minutes waiting for the lock."
                exit 75
            }
        }
    }

    if ($waitedAnnounced) {
        $waited = [int] ((Get-Date) - $started).TotalSeconds
        Write-Host "test-lock: acquired after ${waited}s."
    }

    $exe = $Command[0]
    $rest = @($Command | Select-Object -Skip 1)
    & $exe @rest
    $code = $LASTEXITCODE
} finally {
    if ($held) {
        $mutex.ReleaseMutex()
    }
    $mutex.Dispose()
}

exit $code
