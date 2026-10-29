[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ($env:CI -ne 'true') { throw 'This regression entry point runs in CI; use create-project-zip.ps1 for an archive.' }
$root = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$temp = Join-Path ([IO.Path]::GetTempPath()) ('platform-zip-test-' + [guid]::NewGuid().ToString('N'))
$archive = Join-Path $temp 'changes.zip'
$unicodeName = -join ([char[]]@(0x0444, 0x0430, 0x0439, 0x043B))
$oldZipPath = 'unicode dir/old ' + $unicodeName + '.txt'
$newZipPath = 'unicode dir/new ' + $unicodeName + '.txt'
$newFileName = 'new ' + $unicodeName + '.txt'

function Invoke-TestGit([string[]]$Arguments) {
    & (Get-Command git -CommandType Application).Source -C $temp @Arguments
    if ($LASTEXITCODE -ne 0) { throw "git $($Arguments -join ' ') failed" }
}

function ArchiveEntries([string]$Path) {
    $zip = [IO.Compression.ZipFile]::OpenRead($Path)
    try { return @($zip.Entries | ForEach-Object FullName) } finally { $zip.Dispose() }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
New-Item -ItemType Directory -Path $temp | Out-Null
try {
    Invoke-TestGit @('init', '--quiet')
    Invoke-TestGit @('config', 'user.email', 'zip-test@example.invalid')
    Invoke-TestGit @('config', 'user.name', 'ZIP test')
    Set-Content -LiteralPath (Join-Path $temp '.gitignore') -Value 'ignored.txt' -NoNewline
    Set-Content -LiteralPath (Join-Path $temp 'tracked.txt') -Value 'base' -NoNewline
    Set-Content -LiteralPath (Join-Path $temp 'delete.txt') -Value 'delete' -NoNewline
    New-Item -ItemType Directory -Path (Join-Path $temp 'unicode dir') | Out-Null
    Set-Content -LiteralPath (Join-Path $temp $oldZipPath) -Value 'old' -NoNewline
    Invoke-TestGit @('add', '.')
    Invoke-TestGit @('commit', '--quiet', '-m', 'base')
    Set-Content -LiteralPath (Join-Path $temp 'ignored.txt') -Value 'ignored' -NoNewline

    & (Join-Path $root 'scripts\create-project-zip.ps1') -RepositoryPath $temp -OutputPath $archive
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archive)) { throw 'Clean repository did not create a full project archive.' }
    $cleanEntries = ArchiveEntries $archive
    foreach ($entry in @('.gitignore', 'tracked.txt', 'delete.txt', $oldZipPath)) {
        if ($cleanEntries -notcontains $entry) { throw "Clean archive is missing Git-visible path: $entry" }
    }
    if ($cleanEntries.Count -ne 4) { throw "Clean archive did not contain exactly the Git-visible project files: $($cleanEntries -join ', ')" }
    if ($cleanEntries -contains 'ignored.txt') { throw 'Clean archive contains ignored content.' }

    Set-Content -LiteralPath (Join-Path $temp 'tracked.txt') -Value 'staged' -NoNewline
    Invoke-TestGit @('add', 'tracked.txt')
    Set-Content -LiteralPath (Join-Path $temp 'tracked.txt') -Value 'worktree wins' -NoNewline
    Set-Content -LiteralPath (Join-Path $temp 'untracked space.txt') -Value 'untracked' -NoNewline
    Remove-Item -LiteralPath (Join-Path $temp 'delete.txt')
    Rename-Item -LiteralPath (Join-Path $temp $oldZipPath) -NewName $newFileName
    Invoke-TestGit @('add', '-A')
    Set-Content -LiteralPath (Join-Path $temp $newZipPath) -Value 'renamed' -NoNewline

    & (Join-Path $root 'scripts\create-project-zip.ps1') -RepositoryPath $temp -OutputPath $archive -Force
    if ($LASTEXITCODE -ne 0) { throw 'Changed repository was not archived.' }
    $entries = ArchiveEntries $archive
    $expected = @('tracked.txt', 'untracked space.txt', $newZipPath)
    foreach ($entry in $expected) { if ($entries -notcontains $entry) { throw "Archive is missing changed path: $entry" } }
    foreach ($entry in @('delete.txt', 'ignored.txt', '.gitignore')) { if ($entries -contains $entry) { throw "Archive contains non-current path: $entry" } }
    if ($entries.Count -ne $expected.Count) { throw "Archive contains unchanged files: $($entries -join ', ')" }
    Write-Host 'Changed-only ZIP regression test passed.' -ForegroundColor Green
}
finally {
    if (Test-Path -LiteralPath $temp) {
        $resolvedTemp = (Resolve-Path -LiteralPath $temp).Path
        $tempParent = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd([IO.Path]::DirectorySeparatorChar)
        if ([IO.Path]::GetDirectoryName($resolvedTemp) -ne $tempParent -or [IO.Path]::GetFileName($resolvedTemp) -notmatch '^platform-zip-test-[0-9a-f]{32}$') { throw 'Unexpected test cleanup target.' }
        Remove-Item -LiteralPath $resolvedTemp -Recurse -Force
    }
}
