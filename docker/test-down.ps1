param(
    [switch] $DeleteData
)

$ErrorActionPreference = 'Stop'
$composeFile = Join-Path $PSScriptRoot 'compose.test.yml'
$arguments = @('compose', '-f', $composeFile, 'down', '--remove-orphans')
if ($DeleteData) {
    $arguments += '--volumes'
}

& docker @arguments
if ($LASTEXITCODE -ne 0) {
    throw "Docker command failed with exit code $LASTEXITCODE."
}

if ($DeleteData) {
    Write-Host 'The isolated test containers, network, and database/data volumes were deleted.'
} else {
    Write-Host 'The isolated test containers were stopped. Test database and files were retained.'
}
