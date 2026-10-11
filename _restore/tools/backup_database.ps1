# =============================================================================
# Yiwen Cloud Survey - database backup script
# -----------------------------------------------------------------------------
# Dumps the yiwenyun database to a timestamped .sql file and keeps the newest N.
# Reads connection info from the project's config.php, so it always follows
# whatever environment it runs in.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File backup_database.ps1
#   powershell -ExecutionPolicy Bypass -File backup_database.ps1 -BackupDir D:\db_backup -Keep 30
#
# Schedule it (Task Scheduler, daily), or just run it manually before upgrades.
# Keep the comments in this file ASCII-only: Windows PowerShell 5.1 reads
# .ps1 files as ANSI unless they carry a UTF-8 BOM.
# =============================================================================

param(
    [string]$DumpExe   = '',
    [string]$BackupDir = 'E:\yiwenyun_backup',
    [int]   $Keep      = 14
)

$ErrorActionPreference = 'Stop'

# --- locate config.php (this script lives in <project>\_restore\tools\) -------
$configFile = Join-Path $PSScriptRoot '..\..\config.php'
if (-not (Test-Path $configFile)) {
    throw "config.php not found at: $configFile"
}
$configText = Get-Content $configFile -Raw -Encoding UTF8

function Get-ConfigValue([string]$key, [string]$default) {
    if ($configText -match ("'" + [regex]::Escape($key) + "'\s*=>\s*'([^']*)'")) {
        return $Matches[1]
    }
    return $default
}

$dbHost = Get-ConfigValue 'host'     '127.0.0.1'
$dbPort = Get-ConfigValue 'port'     '3306'
$dbName = Get-ConfigValue 'dbname'   'yiwenyun'
$dbUser = Get-ConfigValue 'username' 'root'
$dbPass = Get-ConfigValue 'password' ''

# --- locate mysqldump ---------------------------------------------------------
if (-not $DumpExe) {
    # Conventional install locations, plus a portable stack sitting two levels
    # above the project directory (..\..\_tools\mysql). Pass -DumpExe to override.
    $projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    $portable    = Join-Path $projectRoot '..\..\_tools\mysql\bin\mysqldump.exe'
    $candidates  = @(
        $portable,
        'D:\phpstudy_pro\Extensions\MySQL5.7.26\bin\mysqldump.exe',
        'C:\phpstudy_pro\Extensions\MySQL5.7.26\bin\mysqldump.exe',
        'C:\xampp\mysql\bin\mysqldump.exe'
    )
    $DumpExe = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
}
if (-not $DumpExe -or -not (Test-Path $DumpExe)) {
    $onPath = Get-Command mysqldump -ErrorAction SilentlyContinue
    if ($onPath) { $DumpExe = $onPath.Source }
}
if (-not $DumpExe -or -not (Test-Path $DumpExe)) {
    throw "mysqldump.exe not found. Pass it explicitly: -DumpExe <full path>"
}

# --- dump ---------------------------------------------------------------------
if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
}

$stamp   = Get-Date -Format 'yyyyMMdd_HHmmss'
$outFile = Join-Path $BackupDir ("{0}_{1}.sql" -f $dbName, $stamp)

# Use a temporary defaults file so the password never appears in the command line
# (avoids the "Using a password on the command line can be insecure" warning and
# keeps it out of the process list).
$cnf = Join-Path $env:TEMP ("yiwenyun_backup_{0}.cnf" -f $PID)
try {
    @(
        '[client]',
        "user=$dbUser",
        "password=$dbPass",
        "host=$dbHost",
        "port=$dbPort"
    ) | Set-Content -Path $cnf -Encoding ASCII

    & $DumpExe "--defaults-extra-file=$cnf" '--databases' $dbName '--routines' '--triggers' "--result-file=$outFile"
    if ($LASTEXITCODE -ne 0) { throw "mysqldump failed with exit code $LASTEXITCODE" }
}
finally {
    Remove-Item $cnf -Force -ErrorAction SilentlyContinue
}

$sizeKb = [math]::Round((Get-Item $outFile).Length / 1KB, 1)
Write-Host ("Backup written: {0}  ({1} KB)" -f $outFile, $sizeKb)

# --- prune old backups --------------------------------------------------------
$all = Get-ChildItem -Path $BackupDir -Filter ("{0}_*.sql" -f $dbName) |
       Sort-Object LastWriteTime -Descending
if ($all.Count -gt $Keep) {
    $all | Select-Object -Skip $Keep | ForEach-Object {
        Write-Host ("Removing old backup: " + $_.Name)
        Remove-Item $_.FullName -Force
    }
}
Write-Host ("Kept {0} backup file(s) in {1}" -f ([math]::Min($all.Count, $Keep)), $BackupDir)
