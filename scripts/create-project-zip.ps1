[CmdletBinding()]
param(
    [string]$OutputPath,
    [switch]$Force,
    [ValidateSet('Fastest', 'Optimal')]
    [string]$Compression = 'Fastest',
    [switch]$IncludeIgnoredStats
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# ============================================================
# PLATFORM — project archive
#
# Files are selected by Git:
#
#   git status --porcelain=v1 -z
#
# Git automatically applies:
#
#   - root .gitignore
#   - nested .gitignore files
#   - .git/info/exclude
#   - global Git excludes
#
# There is intentionally no duplicated exclusion blacklist here.
# ============================================================


# ------------------------------------------------------------
# Helpers
# ------------------------------------------------------------

function Format-ByteSize {
    param(
        [Parameter(Mandatory = $true)]
        [long]$Bytes
    )

    if ($Bytes -ge 1GB) {
        return "{0:N2} GB" -f ($Bytes / 1GB)
    }

    if ($Bytes -ge 1MB) {
        return "{0:N2} MB" -f ($Bytes / 1MB)
    }

    if ($Bytes -ge 1KB) {
        return "{0:N2} KB" -f ($Bytes / 1KB)
    }

    return "$Bytes B"
}


function ConvertTo-ProcessArgument {
    param(
        [Parameter(Mandatory = $true)]
        [AllowEmptyString()]
        [string]$Argument
    )

    # No quoting required.
    if (
        $Argument.Length -gt 0 -and
        $Argument -notmatch '[\s"]'
    ) {
        return $Argument
    }

    # Windows command-line compatible quoting.
    #
    # Backslashes before a quote must be doubled.
    # Trailing backslashes before the closing quote must also be doubled.

    $builder = New-Object System.Text.StringBuilder

    [void]$builder.Append('"')

    $backslashes = 0

    foreach ($character in $Argument.ToCharArray()) {
        if ($character -eq '\') {
            $backslashes++
            continue
        }

        if ($character -eq '"') {
            if ($backslashes -gt 0) {
                [void]$builder.Append(
                    ('\' * ($backslashes * 2))
                )
            }

            [void]$builder.Append('\"')

            $backslashes = 0
            continue
        }

        if ($backslashes -gt 0) {
            [void]$builder.Append(
                ('\' * $backslashes)
            )

            $backslashes = 0
        }

        [void]$builder.Append($character)
    }

    if ($backslashes -gt 0) {
        [void]$builder.Append(
            ('\' * ($backslashes * 2))
        )
    }

    [void]$builder.Append('"')

    return $builder.ToString()
}


function Invoke-GitCapture {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments,

        [Parameter(Mandatory = $true)]
        [string]$WorkingDirectory
    )

    $gitCommand = Get-Command git `
        -CommandType Application `
        -ErrorAction SilentlyContinue

    if ($null -eq $gitCommand) {
        throw @"
Git was not found.

Make sure Git is installed and available in PATH.

Try:

    git --version
"@
    }


    $argumentLine = (
        $Arguments |
        ForEach-Object {
            ConvertTo-ProcessArgument -Argument $_
        }
    ) -join ' '


    $startInfo = New-Object System.Diagnostics.ProcessStartInfo

    $startInfo.FileName = $gitCommand.Source
    $startInfo.Arguments = $argumentLine
    $startInfo.WorkingDirectory = $WorkingDirectory

    $startInfo.UseShellExecute = $false
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $startInfo.CreateNoWindow = $true


    $process = New-Object System.Diagnostics.Process

    [void]($process.StartInfo = $startInfo)


    try {
        if (-not $process.Start()) {
            throw "Failed to start Git."
        }

        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()

        $process.WaitForExit()

        if ($process.ExitCode -ne 0) {
            $message = $stderr.Trim()

            if ([string]::IsNullOrWhiteSpace($message)) {
                $message = "Git exited with code $($process.ExitCode)."
            }

            throw $message
        }

        # Preserve NUL-delimited porcelain output as one string; otherwise
        # PowerShell can enumerate it while returning from this helper.
        return ,$stdout
    }
    finally {
        $process.Dispose()
    }
}


# ------------------------------------------------------------
# Locate project directory
# ------------------------------------------------------------

$CandidateRoot = (
    Resolve-Path (Join-Path $PSScriptRoot "..")
).Path


Write-Host ""
Write-Host "Resolving Git repository..." -ForegroundColor Cyan


try {
    $GitRootRaw = Invoke-GitCapture `
        -WorkingDirectory $CandidateRoot `
        -Arguments @(
            "rev-parse",
            "--show-toplevel"
        )
}
catch {
    throw @"
Unable to locate Git repository.

Expected project directory:

    $CandidateRoot

Git error:

$($_.Exception.Message)
"@
}


$Root = $GitRootRaw.Trim()


if ([string]::IsNullOrWhiteSpace($Root)) {
    throw "Git returned an empty repository root."
}


$Root = [IO.Path]::GetFullPath($Root)


if (-not (Test-Path -LiteralPath $Root -PathType Container)) {
    throw "Repository root does not exist: $Root"
}


$ProjectName = Split-Path $Root -Leaf


# ------------------------------------------------------------
# Verify repository
# ------------------------------------------------------------

$InsideWorkTree = (
    Invoke-GitCapture `
        -WorkingDirectory $Root `
        -Arguments @(
            "rev-parse",
            "--is-inside-work-tree"
        )
).Trim()


if ($InsideWorkTree -ne "true") {
    throw "Path is not inside a Git working tree: $Root"
}


$GitDirectory = (
    Invoke-GitCapture `
        -WorkingDirectory $Root `
        -Arguments @(
            "rev-parse",
            "--git-dir"
        )
).Trim()


if ([string]::IsNullOrWhiteSpace($GitDirectory)) {
    throw "Unable to resolve Git metadata directory."
}


# ------------------------------------------------------------
# Resolve output archive
# ------------------------------------------------------------

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"

    $OutputPath = Join-Path `
        $Root `
        "$ProjectName-$stamp.zip"
}
elseif (-not [IO.Path]::IsPathRooted($OutputPath)) {
    $OutputPath = Join-Path `
        $Root `
        $OutputPath
}


$ArchivePath = [IO.Path]::GetFullPath($OutputPath)

$ArchiveDirectory = [IO.Path]::GetDirectoryName(
    $ArchivePath
)


if ([string]::IsNullOrWhiteSpace($ArchiveDirectory)) {
    throw "Archive directory cannot be resolved."
}


if (-not (
    Test-Path `
        -LiteralPath $ArchiveDirectory `
        -PathType Container
)) {
    New-Item `
        -ItemType Directory `
        -Path $ArchiveDirectory `
        -Force |
    Out-Null
}


if (Test-Path -LiteralPath $ArchivePath) {
    if (-not $Force) {
        throw @"
Archive already exists:

    $ArchivePath

Use -Force to replace it.
"@
    }

    Remove-Item `
        -LiteralPath $ArchivePath `
        -Force
}


# ------------------------------------------------------------
# Read archive file list from Git
# ------------------------------------------------------------

Write-Host "Repository: $Root"

Write-Host ""
Write-Host "Reading files using Git ignore rules..." `
    -ForegroundColor Cyan


Write-Progress `
    -Id 1 `
    -Activity "Preparing project archive" `
    -Status "Reading Git file list..." `
    -PercentComplete 0


# Porcelain is the single source of truth for current changes.  In -z form a
# rename/copy record is followed by its old path; the first path is the current
# physical destination and is the one that belongs in the archive.
$GitStatusOutputItems = @(Invoke-GitCapture `
    -WorkingDirectory $Root `
    -Arguments @(
        "-c",
        "core.quotePath=false",
        "status",
        "--porcelain=v1",
        "-z"
    ))
# ProcessStartInfo (or another non-string object) must never become a porcelain
# record if a host writes it to the success stream.
[string]$GitStatusOutput = @($GitStatusOutputItems | Where-Object { $_ -is [string] } | Select-Object -Last 1)[0]

$StatusFields = @($GitStatusOutput.Split([char]0) | Where-Object { -not [string]::IsNullOrEmpty($_) })
$RelativePaths = New-Object 'System.Collections.Generic.List[string]'
$DeletedPaths = New-Object 'System.Collections.Generic.List[string]'
$ArchiveMode = 'current Git changes'
for ($statusIndex = 0; $statusIndex -lt $StatusFields.Count; $statusIndex++) {
    $record = $StatusFields[$statusIndex]
    if ($record.Length -lt 4) { throw "Git returned an invalid porcelain record." }
    $status = $record.Substring(0, 2)
    $path = $record.Substring(3)
    if ($status[0] -in @('R','C') -or $status[1] -in @('R','C')) { $statusIndex++ } # consume old source path
    if ($status[0] -eq 'D' -or $status[1] -eq 'D') { $DeletedPaths.Add($path); continue }
    $RelativePaths.Add($path)
}


if ($DeletedPaths.Count -gt 0) {
    foreach ($deletedPath in $DeletedPaths) { Write-Host "Deleted (not archived): $deletedPath" -ForegroundColor DarkYellow }
}

if ($RelativePaths.Count -eq 0) {
    # A clean worktree is intentionally useful too: create a full, Git-visible
    # project archive, while retaining Git's ignore rules as the source of truth.
    Write-Host "No current Git changes; archiving the full Git-visible project." -ForegroundColor Yellow
    [string]$GitProjectOutput = Invoke-GitCapture `
        -WorkingDirectory $Root `
        -Arguments @(
            "-c",
            "core.quotePath=false",
            "ls-files",
            "--cached",
            "--others",
            "--exclude-standard",
            "--full-name",
            "-z"
        )
    foreach ($path in ($GitProjectOutput.Split([char]0) | Where-Object { -not [string]::IsNullOrEmpty($_) })) {
        $RelativePaths.Add($path)
    }
    $ArchiveMode = 'full Git-visible project (clean worktree fallback)'
}


# ------------------------------------------------------------
# Resolve physical files
# ------------------------------------------------------------

$RootPrefix = $Root.TrimEnd(
    [IO.Path]::DirectorySeparatorChar,
    [IO.Path]::AltDirectorySeparatorChar
) + [IO.Path]::DirectorySeparatorChar


$Files = New-Object 'System.Collections.Generic.List[object]'

$totalCandidates = $RelativePaths.Count
$currentCandidate = 0


foreach ($relativePath in $RelativePaths) {
    $currentCandidate++


    if ([string]::IsNullOrWhiteSpace($relativePath)) {
        continue
    }


    # Git always returns repository paths using "/".
    # Convert them to the current operating-system separator.

    $nativeRelativePath = $relativePath.Replace(
        '/',
        [IO.Path]::DirectorySeparatorChar
    )


    $fullPath = [IO.Path]::GetFullPath(
        (Join-Path $Root $nativeRelativePath)
    )


    # --------------------------------------------------------
    # Path traversal protection
    # --------------------------------------------------------

    if (-not $fullPath.StartsWith(
        $RootPrefix,
        [StringComparison]::OrdinalIgnoreCase
    )) {
        throw "Git returned unsafe path outside repository: $relativePath"
    }


    # --------------------------------------------------------
    # Never include the archive currently being generated
    # --------------------------------------------------------

    if ($fullPath.Equals(
        $ArchivePath,
        [StringComparison]::OrdinalIgnoreCase
    )) {
        continue
    }


    # --------------------------------------------------------
    # A tracked path may currently be deleted from worktree.
    # There is then no physical file to archive.
    # --------------------------------------------------------

    if (-not (
        Test-Path `
            -LiteralPath $fullPath `
            -PathType Leaf
    )) {
        continue
    }


    $fileInfo = Get-Item `
        -LiteralPath $fullPath `
        -Force


    $Files.Add(
        [PSCustomObject]@{
            FullName     = $fileInfo.FullName
            RelativePath = $relativePath
            Length       = [long]$fileInfo.Length
        }
    )


    if (
        ($currentCandidate % 100) -eq 0 -or
        $currentCandidate -eq $totalCandidates
    ) {
        $percent = [math]::Min(
            100,
            [math]::Round(
                ($currentCandidate / $totalCandidates) * 100
            )
        )


        Write-Progress `
            -Id 1 `
            -Activity "Preparing project archive" `
            -Status "$currentCandidate / $totalCandidates files" `
            -CurrentOperation $relativePath `
            -PercentComplete $percent
    }
}


Write-Progress `
    -Id 1 `
    -Activity "Preparing project archive" `
    -Completed


# ------------------------------------------------------------
# Validate result
# ------------------------------------------------------------

$totalFiles = $Files.Count


if ($totalFiles -eq 0) {
    throw "No physical files found to archive."
}


[long]$totalBytes = 0


foreach ($file in $Files) {
    $totalBytes += $file.Length
}


Write-Host ""
Write-Host "Archive source prepared." `
    -ForegroundColor Green

Write-Host "Files:       $totalFiles"
Write-Host "Source size: $(Format-ByteSize -Bytes $totalBytes)"
Write-Host ""


# ------------------------------------------------------------
# Optional ignored-file statistics. This Git walk is expensive in large working
# trees, so it is deliberately opt-in and never affects file selection.
# ------------------------------------------------------------

if ($IncludeIgnoredStats) {
try {
    $IgnoredOutput = Invoke-GitCapture `
        -WorkingDirectory $Root `
        -Arguments @(
            "-c",
            "core.quotePath=false",
            "ls-files",
            "--others",
            "--ignored",
            "--exclude-standard",
            "-z"
        )


    $IgnoredPaths = @(
        $IgnoredOutput.Split(
            [char]0,
            [StringSplitOptions]::RemoveEmptyEntries
        )
    )


    Write-Host `
        "Ignored by Git: $($IgnoredPaths.Count) files" `
        -ForegroundColor DarkGray
}
catch {
    # Informational only.
    # Failure here must not prevent archive creation.

    Write-Host `
        "Ignored-file statistics unavailable." `
        -ForegroundColor DarkGray
}
}


Write-Host ""


# ------------------------------------------------------------
# Create ZIP
# ------------------------------------------------------------

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$ZipCompression = if ($Compression -eq 'Fastest') {
    [IO.Compression.CompressionLevel]::Fastest
} else {
    [IO.Compression.CompressionLevel]::Optimal
}


$stopwatch = [Diagnostics.Stopwatch]::StartNew()


$stream = [IO.File]::Open(
    $ArchivePath,
    [IO.FileMode]::CreateNew,
    [IO.FileAccess]::Write,
    [IO.FileShare]::None
)


try {
    $archive = New-Object System.IO.Compression.ZipArchive(
        $stream,
        [IO.Compression.ZipArchiveMode]::Create,
        $false
    )


    try {
        $index = 0

        [long]$processedBytes = 0


        foreach ($file in $Files) {
            $index++


            # ZIP paths always use "/".

            $zipPath = $file.RelativePath.Replace(
                '\',
                '/'
            )


            if ($totalBytes -gt 0) {
                $percent = [math]::Min(
                    100,
                    [math]::Round(
                        ($processedBytes / $totalBytes) * 100
                    )
                )
            }
            else {
                $percent = [math]::Min(
                    100,
                    [math]::Round(
                        ($index / $totalFiles) * 100
                    )
                )
            }


            Write-Progress `
                -Id 1 `
                -Activity "Creating $ProjectName archive" `
                -Status (
                    "$index / $totalFiles files | " +
                    "$(Format-ByteSize -Bytes $processedBytes) / " +
                    "$(Format-ByteSize -Bytes $totalBytes)"
                ) `
                -CurrentOperation $file.RelativePath `
                -PercentComplete $percent


            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file.FullName,
                $zipPath,
                $ZipCompression
            ) |
            Out-Null


            $processedBytes += $file.Length
        }
    }
    finally {
        $archive.Dispose()
    }
}
catch {
    # Remove partial archive on any failure.

    if (Test-Path -LiteralPath $ArchivePath) {
        try {
            Remove-Item `
                -LiteralPath $ArchivePath `
                -Force
        }
        catch {
            # Preserve the original exception.
        }
    }

    throw
}
finally {
    $stream.Dispose()


    Write-Progress `
        -Id 1 `
        -Activity "Creating archive" `
        -Completed
}


$stopwatch.Stop()


# ------------------------------------------------------------
# Verify ZIP
# ------------------------------------------------------------

if (-not (
    Test-Path `
        -LiteralPath $ArchivePath `
        -PathType Leaf
)) {
    throw "Archive was not created."
}


$archiveInfo = Get-Item `
    -LiteralPath $ArchivePath


if ($archiveInfo.Length -eq 0) {
    throw "Created archive is empty."
}


$verificationArchive = [IO.Compression.ZipFile]::OpenRead(
    $ArchivePath
)


try {
    $archiveEntryCount = $verificationArchive.Entries.Count
}
finally {
    $verificationArchive.Dispose()
}


if ($archiveEntryCount -ne $totalFiles) {
    throw @"
Archive verification failed.

Expected entries: $totalFiles
Actual entries:   $archiveEntryCount
"@
}


# ------------------------------------------------------------
# Result
# ------------------------------------------------------------

Write-Host ""

Write-Host "============================================" `
    -ForegroundColor DarkGray

Write-Host "Archive created successfully!" `
    -ForegroundColor Green

Write-Host "============================================" `
    -ForegroundColor DarkGray

Write-Host ""

Write-Host "Project: $ProjectName"
Write-Host "Path:    $($archiveInfo.FullName)"
Write-Host "Files:   $archiveEntryCount"
Write-Host "Size:    $(Format-ByteSize -Bytes $archiveInfo.Length)"
Write-Host "Time:    $([math]::Round($stopwatch.Elapsed.TotalSeconds, 1)) sec"
Write-Host "Compression: $Compression"

Write-Host ""

Write-Host "File selection:" `
    -ForegroundColor Cyan

if ($ArchiveMode -eq 'current Git changes') {
    Write-Host "  git status --porcelain=v1 -z"
}
else {
    Write-Host "  git ls-files --cached --others --exclude-standard -z (clean-worktree fallback)"
}

Write-Host ""

Write-Host "Ignore rules:" `
    -ForegroundColor Cyan

Write-Host "  root .gitignore"
Write-Host "  nested .gitignore files"
Write-Host "  .git/info/exclude"
Write-Host "  global Git excludes"

Write-Host ""

Write-Host "Archive verified successfully." `
    -ForegroundColor Green

Write-Host ""
