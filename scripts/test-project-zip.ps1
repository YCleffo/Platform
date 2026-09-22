[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$projectName = Split-Path $root -Leaf
$temporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) ("platform-zip-test-" + [guid]::NewGuid().ToString("N"))
$archivePath = Join-Path $temporaryDirectory "$projectName-security-test.zip"

New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null

try {
    & (Join-Path $PSScriptRoot "create-project-zip.ps1") -OutputPath $archivePath -Force

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    $archive = [IO.Compression.ZipFile]::OpenRead($archivePath)
    try {
        $entries = @($archive.Entries | ForEach-Object { $_.FullName })
    }
    finally {
        $archive.Dispose()
    }

    $violations = @(
        foreach ($entry in $entries) {
            $segments = @($entry -split "/")
            $fileName = $segments[-1]

            if ($fileName -eq ".env") { $entry; continue }
            if ($fileName.EndsWith(".fail.json", [StringComparison]::OrdinalIgnoreCase)) { $entry; continue }
            if ($segments -contains "vendor") { $entry; continue }
            if ($segments -contains "runtime") { $entry; continue }

            for ($index = 0; $index -lt $segments.Count - 1; $index++) {
                if ($segments[$index] -eq "tests" -and $segments[$index + 1] -eq "_output") {
                    $entry
                    break
                }
            }

            if ($entry -match "^$([regex]::Escape($projectName))-\d{8}-\d{6}\.zip$") {
                $entry
            }
        }
    )

    if ($violations.Count -gt 0) {
        throw "Archive contains forbidden entries: $($violations -join ', ')"
    }

    foreach ($requiredEntry in @(
        "apps/backend/codeception.yml",
        "apps/backend/tests/Api/AuthSecurityCest.php"
    )) {
        if ($entries -notcontains $requiredEntry) {
            throw "Archive is missing required test source/configuration: $requiredEntry"
        }
    }

    Write-Host "Project ZIP security regression test passed." -ForegroundColor Green
}
finally {
    if (Test-Path -LiteralPath $temporaryDirectory) {
        Remove-Item -LiteralPath $temporaryDirectory -Recurse -Force
    }
}
