param(
    [ValidateRange(1024, 65535)]
    [int] $Port = 8088,
    [switch] $WithScheduler
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.test.yml'
$env:MEMBER_PROMOTION_TEST_PORT = [string] $Port
$composeArgs = @('compose', '-f', $composeFile)
if ($WithScheduler) {
    $composeArgs += @('--profile', 'scheduler')
}

function Invoke-Docker {
    param([string[]] $Arguments)
    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed with exit code $LASTEXITCODE."
    }
}

Write-Host 'Building and starting the isolated Member Promotion test site...'
Invoke-Docker ($composeArgs + @('up', '-d', '--build'))

Write-Host 'Waiting for Flarum to become ready...'
$ready = $false
for ($attempt = 1; $attempt -le 60; $attempt++) {
    $probeArgs = $composeArgs + @('exec', '-T', 'flarum', 'curl', '-fsS', 'http://127.0.0.1:8000')
    try {
        & docker @probeArgs *> $null
    } catch {
        # The container can briefly reject requests while its init process is still running.
        # A failed readiness probe is expected here and should be retried below.
    }
    if ($LASTEXITCODE -eq 0) {
        $ready = $true
        break
    }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    $logArgs = $composeArgs + @('logs', '--tail', '100', 'flarum')
    & docker @logArgs
    throw 'Flarum did not become ready within 120 seconds.'
}

Write-Host 'Enabling dunea-member-promotion and running its migration...'
Invoke-Docker ($composeArgs + @('exec', '-T', '-u', 'flarum', 'flarum', 'php', 'flarum', 'extension:enable', 'flarum-lang-chinese-simplified'))
Invoke-Docker ($composeArgs + @('exec', '-T', '-u', 'flarum', 'flarum', 'php', 'flarum', 'extension:enable', 'dunea-member-promotion'))
Invoke-Docker ($composeArgs + @('exec', '-T', '-u', 'flarum', 'flarum', 'php', 'flarum', 'cache:clear'))

Write-Host ''
Write-Host "Ready: http://localhost:$Port"
Write-Host 'Administrator: flarum'
Write-Host 'Password:      flarum'
Write-Host 'Automatic promotion remains disabled until you enable it in the extension page.'
if (-not $WithScheduler) {
    Write-Host 'The scheduler is not running. Use -WithScheduler when you want to test scheduled batches.'
}
