[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$projectName = Split-Path $root -Leaf
$temporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) ("platform-zip-test-" + [guid]::NewGuid().ToString("N"))
$archivePath = Join-Path $temporaryDirectory "$projectName-security-test.zip"

# Fixtures to prove exclusions catch prohibited files
$fixtures = @(
    (Join-Path $root "$projectName.zip"),
    (Join-Path $root ".env.archive-test"),
    (Join-Path $root "backups\test.dump"),
    (Join-Path $root "apps\backend\runtime\test.log"),
    (Join-Path $root "apps\backend\tests\_output\example.fail.json"),
    (Join-Path $root "apps\backend\vendor\zip-fixture.php"),
    (Join-Path $root "apps\backend\node_modules\zip-fixture.js"),
    (Join-Path $root "apps\backend\dist\zip-fixture.js"),
    (Join-Path $root "apps\control-plane\vendor\zip-control-vendor.php"),
    (Join-Path $root "apps\control-plane\var\cache\zip-control-cache.php"),
    (Join-Path $root "apps\control-plane\var\log\zip-control-log.log"),
    (Join-Path $root "apps\control-plane\tests\_output\zip-control.fail.json")
)

foreach ($fixture in $fixtures) {
    if (Test-Path -LiteralPath $fixture) {
        throw "Refusing to replace existing archive test fixture: $fixture"
    }
}

New-Item -ItemType Directory -Path $temporaryDirectory | Out-Null

try {
    # ------------------------------------------------------------
    # Create regression fixture files
    # ------------------------------------------------------------
    foreach ($fixture in $fixtures) {
        $parent = [IO.Path]::GetDirectoryName($fixture)
        if (-not (Test-Path -LiteralPath $parent)) {
            New-Item -ItemType Directory -Force -Path $parent | Out-Null
        }
        Set-Content -LiteralPath $fixture -Value "fixture content for zip exclusion test" -Encoding utf8
    }

    # ------------------------------------------------------------
    # Run archive creation
    # ------------------------------------------------------------
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

    # ------------------------------------------------------------
    # Check violations
    # ------------------------------------------------------------
    $violations = @(
        foreach ($entry in $entries) {
            $segments = @($entry -split "/")
            $fileName = $segments[-1]

            # Sensitive env files
            if (($fileName -eq ".env" -or $fileName -like ".env.*") -and $fileName -ne ".env.example") {
                $entry
                continue
            }

            # Test failure logs/dumps
            if ($fileName.EndsWith(".fail.json", [StringComparison]::OrdinalIgnoreCase)) {
                $entry
                continue
            }

            # Database dumps
            if ($fileName.EndsWith(".dump", [StringComparison]::OrdinalIgnoreCase) -or
                $fileName.EndsWith(".sql.gz", [StringComparison]::OrdinalIgnoreCase)) {
                $entry
                continue
            }

            # Nested zip archives
            if ($fileName.EndsWith(".zip", [StringComparison]::OrdinalIgnoreCase)) {
                $entry
                continue
            }

            # Excluded directories
            if ($segments -contains "vendor" -or
                (($segments -contains "runtime") -and $entry -notlike 'docs/database/runtime/*') -or
                $segments -contains "node_modules" -or
                $segments -contains "dist" -or
                $segments -contains "backups" -or
                ($entry -like 'apps/control-plane/var/*')) {
                $entry
                continue
            }

            for ($index = 0; $index -lt $segments.Count - 1; $index++) {
                if ($segments[$index] -eq "tests" -and $segments[$index + 1] -eq "_output") {
                    $entry
                    break
                }
            }
        }
    )

    if ($violations.Count -gt 0) {
        throw "Archive contains forbidden entries: $($violations -join ', ')"
    }

    # Ensure regression fixtures are demonstrably NOT present in archive
    $fixtureLeaves = @("Platform.zip", ".env.archive-test", "test.dump", "test.log", "example.fail.json", "zip-fixture.php", "zip-fixture.js", "zip-control-vendor.php", "zip-control-cache.php", "zip-control-log.log", "zip-control.fail.json")
    foreach ($entry in $entries) {
        $leaf = Split-Path $entry -Leaf
        if ($fixtureLeaves -contains $leaf) {
            throw "Regression fixture '$leaf' was improperly included in archive: $entry"
        }
    }

    foreach ($requiredEntry in @(
        "apps/backend/codeception.yml",
        "apps/backend/tests/Api/AuthSecurityCest.php",
        "docs/database/runtime/Platform_Runtime_v1.dbml",
        "docs/database/commercial/Commercial_ControlPlane_v2.dbml",
        "apps/control-plane/composer.json",
        "apps/control-plane/composer.lock",
        "apps/control-plane/.dockerignore",
        "apps/control-plane/bin/console",
        "apps/control-plane/docker/Dockerfile",
        "apps/control-plane/migrations/Version20260924000100.php",
        "apps/control-plane/src/Kernel.php",
        "apps/control-plane/tests/Unit/IdentifierPolicyTest.php"
    )) {
        if ($entries -notcontains $requiredEntry) {
            throw "Archive is missing required test source/configuration: $requiredEntry"
        }
    }

    Write-Host "Project ZIP security regression test passed." -ForegroundColor Green
}
finally {
    # Cleanup regression fixtures
    foreach ($fixture in $fixtures) {
        if (Test-Path -LiteralPath $fixture) {
            Remove-Item -LiteralPath $fixture -Force -ErrorAction SilentlyContinue
        }
    }

    if (Test-Path -LiteralPath $temporaryDirectory) {
        Remove-Item -LiteralPath $temporaryDirectory -Recurse -Force -ErrorAction SilentlyContinue
    }
}
