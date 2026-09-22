[CmdletBinding()]
param(
    [string]$OutputPath,
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# ------------------------------------------------------------
# Project
# ------------------------------------------------------------

$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$ProjectName = Split-Path $Root -Leaf

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutputPath = Join-Path $Root "$ProjectName-$stamp.zip"
}
elseif (-not [IO.Path]::IsPathRooted($OutputPath)) {
    $OutputPath = Join-Path $Root $OutputPath
}

$ArchivePath = [IO.Path]::GetFullPath($OutputPath)
$ArchiveDirectory = [IO.Path]::GetDirectoryName($ArchivePath)

if ([string]::IsNullOrWhiteSpace($ArchiveDirectory)) {
    throw "Archive directory cannot be resolved."
}

if (-not (Test-Path -LiteralPath $ArchiveDirectory -PathType Container)) {
    New-Item `
        -ItemType Directory `
        -Force `
        -Path $ArchiveDirectory |
    Out-Null
}

if (Test-Path -LiteralPath $ArchivePath) {
    if (-not $Force) {
        throw "Archive already exists: $ArchivePath. Use -Force to replace it."
    }

    Remove-Item -LiteralPath $ArchivePath -Force
}

# ------------------------------------------------------------
# Exclusions
# ------------------------------------------------------------

function Test-ExcludedProjectFile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RelativePath
    )

    $fileName = [IO.Path]::GetFileName($RelativePath)

    # Codeception failure diagnostics may contain raw credentials and tokens.
    if ($fileName.EndsWith(".fail.json", [StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    # Secrets: .env, .env.local, .env.*.local (never exclude .env.example)
    if (
        $fileName -eq ".env" -or
        $fileName -match '^\.env(\..+)?\.local$'
    ) {
        return $true
    }

    # Database dumps and compressed dumps
    if (
        $fileName.EndsWith(".dump", [StringComparison]::OrdinalIgnoreCase) -or
        $fileName.EndsWith(".sql.gz", [StringComparison]::OrdinalIgnoreCase)
    ) {
        return $true
    }

    # Any zip archives inside the project tree
    if ($fileName.EndsWith(".zip", [StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    # Generated log files
    if ($fileName.EndsWith(".log", [StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    # Excluded directories anywhere in the project
    $segments = @($RelativePath -split "[\\/]")

    for ($index = 0; $index -lt $segments.Count; $index++) {
        $segment = $segments[$index]

        if (
            $segment -eq "tests" -and
            $index + 1 -lt $segments.Count -and
            $segments[$index + 1] -eq "_output"
        ) {
            return $true
        }

        if (
            $segment -eq ".git" -or
            $segment -eq "vendor" -or
            $segment -eq "runtime" -or
            $segment -eq "node_modules" -or
            $segment -eq "dist" -or
            $segment -eq "backups"
        ) {
            return $true
        }
    }

    return $false
}

# ------------------------------------------------------------
# Paths
# ------------------------------------------------------------

$rootPrefix = $Root.TrimEnd(
    [IO.Path]::DirectorySeparatorChar,
    [IO.Path]::AltDirectorySeparatorChar
) + [IO.Path]::DirectorySeparatorChar

# ------------------------------------------------------------
# Collect files
# ------------------------------------------------------------

Write-Host ""
Write-Host "Preparing archive..." -ForegroundColor Cyan

Write-Progress `
    -Id 1 `
    -Activity "Creating project archive" `
    -Status "Scanning project files..."

$files = @(
    foreach ($file in Get-ChildItem -LiteralPath $Root -Recurse -Force -File) {
        $fullName = [IO.Path]::GetFullPath($file.FullName)

        # Never include archive currently being created
        if ($fullName -eq $ArchivePath) {
            continue
        }

        $relativePath = $fullName.Substring($rootPrefix.Length)

        if (Test-ExcludedProjectFile -RelativePath $relativePath) {
            continue
        }

        $file
    }
)

Write-Progress -Id 1 -Activity "Creating project archive" -Completed

$totalFiles = $files.Count

if ($totalFiles -eq 0) {
    throw "No files found to archive."
}

$totalBytes = ($files | Measure-Object -Property Length -Sum).Sum

Write-Host "Files found: $totalFiles"
Write-Host "Source size: $([math]::Round($totalBytes / 1MB, 2)) MB"
Write-Host ""

# ------------------------------------------------------------
# ZIP
# ------------------------------------------------------------

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$stopwatch = [Diagnostics.Stopwatch]::StartNew()

$stream = [IO.File]::Open(
    $ArchivePath,
    [IO.FileMode]::CreateNew,
    [IO.FileAccess]::Write,
    [IO.FileShare]::None
)

try {
    $archive = [IO.Compression.ZipArchive]::new(
        $stream,
        [IO.Compression.ZipArchiveMode]::Create,
        $false
    )

    try {
        $index = 0
        [long]$processedBytes = 0

        foreach ($file in $files) {
            $index++

            $relativePath = $file.FullName.Substring($rootPrefix.Length)

            # ZIP paths use /
            $zipPath = $relativePath.Replace('\', '/')

            if ($totalBytes -gt 0) {
                $percent = [math]::Min(
                    100,
                    [math]::Round(($processedBytes / $totalBytes) * 100)
                )
            }
            else {
                $percent = [math]::Round(($index / $totalFiles) * 100)
            }

            $processedMB = [math]::Round($processedBytes / 1MB, 1)
            $totalMB = [math]::Round($totalBytes / 1MB, 1)

            Write-Progress `
                -Id 1 `
                -Activity "Creating $ProjectName archive" `
                -Status "$index / $totalFiles files | $processedMB / $totalMB MB" `
                -CurrentOperation $relativePath `
                -PercentComplete $percent

            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file.FullName,
                $zipPath,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null

            $processedBytes += $file.Length
        }
    }
    finally {
        $archive.Dispose()
    }
}
finally {
    $stream.Dispose()
}

Write-Progress -Id 1 -Activity "Creating archive" -Completed

$stopwatch.Stop()

# ------------------------------------------------------------
# Result
# ------------------------------------------------------------

$archiveInfo = Get-Item -LiteralPath $ArchivePath

Write-Host ""
Write-Host "Archive created successfully!" -ForegroundColor Green
Write-Host ""
Write-Host "Path:  $($archiveInfo.FullName)"
Write-Host "Files: $totalFiles"
Write-Host "Size:  $([math]::Round($archiveInfo.Length / 1MB, 2)) MB"
Write-Host "Time:  $([math]::Round($stopwatch.Elapsed.TotalSeconds, 1)) sec"
Write-Host ""
