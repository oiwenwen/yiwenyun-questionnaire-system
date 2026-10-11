# =============================================================================
# Yiwen Cloud Survey - fresh-database acceptance test
# -----------------------------------------------------------------------------
# Use this AFTER deploying to a new server (or after initialising an empty
# database locally) to verify the whole application works end to end.
#
# It assumes the database is EMPTY (no pre-existing surveys), so it creates its
# own test account and surveys, then deletes the test survey at the end.
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File e2e_test_fresh.ps1
#   powershell -ExecutionPolicy Bypass -File e2e_test_fresh.ps1 -BaseUrl http://your-domain.com
#   powershell -ExecutionPolicy Bypass -File e2e_test_fresh.ps1 -BaseUrl http://1.2.3.4 -MysqlExe "C:\path\to\mysql.exe"
#
# -MysqlExe is optional. When given (together with -DbName/-DbUser/-DbPass),
# the script also checks for orphan rows left by the delete and cleans up the
# test account.
#
# NOTE: keep this file ASCII-only. Windows PowerShell 5.1 reads .ps1 files as
# ANSI unless they carry a UTF-8 BOM, which mangles non-ASCII characters.
# =============================================================================

param(
    [string]$BaseUrl  = 'http://127.0.0.1:8080',
    [string]$MysqlExe = '',
    [string]$DbHost   = '127.0.0.1',
    [int]   $DbPort   = 3306,
    [string]$DbName   = 'yiwenyun',
    [string]$DbUser   = 'root',
    [string]$DbPass   = ''
)

$ErrorActionPreference = 'Continue'
$BaseUrl = $BaseUrl.TrimEnd('/')

# --- connection details for the optional DB-level checks ----------------------
# When -MysqlExe is given but no password, read the connection info from the
# project's config.php (this script is expected to live in <project>\tests\).
if ($MysqlExe -and $DbPass -eq '') {
    $cfgFile = Join-Path $PSScriptRoot '..\config.php'
    if (Test-Path $cfgFile) {
        $cfgText = Get-Content $cfgFile -Raw -Encoding UTF8
        if ($cfgText -match "'password'\s*=>\s*'([^']*)'") { $DbPass = $Matches[1] }
        if ($cfgText -match "'dbname'\s*=>\s*'([^']*)'")   { $DbName = $Matches[1] }
        if ($cfgText -match "'username'\s*=>\s*'([^']*)'") { $DbUser = $Matches[1] }
        if ($cfgText -match "'host'\s*=>\s*'([^']*)'")     { $DbHost = $Matches[1] }
        if ($cfgText -match "'port'\s*=>\s*'([0-9]+)'")    { $DbPort = [int]$Matches[1] }
        Write-Output ("DB connection from config.php: " + $DbUser + "@" + $DbHost + ":" + $DbPort + "/" + $DbName)
    } else {
        Write-Output ("config.php not found at " + $cfgFile + " - pass -DbPass explicitly")
    }
}

$pass = 0
$fail = 0
$skip = 0

function T($name, $cond, $extra = '') {
    if ($cond) { $script:pass++; Write-Output ("PASS  " + $name + "  " + $extra) }
    else       { $script:fail++; Write-Output ("FAIL  " + $name + "  " + $extra) }
}
function S($name, $why) {
    $script:skip++
    Write-Output ("SKIP  " + $name + "  (" + $why + ")")
}

function Req($uri, $body = $null, $sess = $null, $form = $null) {
    $p = @{ Uri = $uri; UseBasicParsing = $true; TimeoutSec = 30 }
    if ($sess) { $p.WebSession = $sess }
    if ($null -ne $body) { $p.Method = 'POST'; $p.ContentType = 'application/json'; $p.Body = $body }
    if ($null -ne $form) { $p.Method = 'POST'; $p.Body = $form }
    try {
        return Invoke-WebRequest @p
    } catch {
        $resp = $_.Exception.Response
        if ($resp) {
            $sr = New-Object System.IO.StreamReader($resp.GetResponseStream(), [System.Text.Encoding]::UTF8)
            return New-Object PSObject -Property @{ StatusCode = [int]$resp.StatusCode; Content = $sr.ReadToEnd() }
        }
        return New-Object PSObject -Property @{ StatusCode = 0; Content = ("EXCEPTION: " + $_.Exception.Message) }
    }
}
function AsJson($resp) { return ($resp.Content | ConvertFrom-Json) }

function Sql($query) {
    if (-not $MysqlExe) { return $null }
    # NOTE: do not name this variable $args - that is a PowerShell automatic
    # variable and assigning to it silently breaks the command line.
    $cliArgs = @('-u', $DbUser)
    if ($DbPass -ne '') { $cliArgs += ("-p" + $DbPass) }
    $cliArgs += @('-h', $DbHost, '-P', $DbPort, '-N', '-B', '-e', $query)
    return (& $MysqlExe @cliArgs 2>$null)
}

Write-Output ("Target: " + $BaseUrl)
Write-Output ''

# --- reachability -------------------------------------------------------------
$r = Req "$BaseUrl/index.html"
if ($r.StatusCode -ne 200) {
    Write-Output ("Cannot reach " + $BaseUrl + "/index.html  ->  " + $r.Content)
    exit 2
}

$sess  = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$stamp = Get-Date -Format 'yyMMddHHmmss'
$testUser = "e2e_$stamp"

Write-Output '===== A. static pages ====='
T 'index.html reachable' ($r.StatusCode -eq 200) ("len=" + $r.Content.Length)
$r = Req "$BaseUrl/fill.html"
T 'fill.html reachable' ($r.StatusCode -eq 200)

Write-Output '===== B. registration and session ====='
$r = AsJson (Req "$BaseUrl/api/register.php" (@{ username = $testUser; password = 'e2ePass123' } | ConvertTo-Json) $sess)
T 'register new account' ($r.success -eq $true) ("msg=" + $r.msg)
if ($r.success -ne $true) {
    Write-Output ''
    Write-Output 'Registration failed. If this is a deployed server, check that'
    Write-Output "config.php has allow_register => true (needed for this test), then rerun."
    exit 1
}

$r = AsJson (Req "$BaseUrl/api/check_login.php" $null $sess)
T 'session persists' ($r.success -eq $true)

$r = AsJson (Req "$BaseUrl/api/register.php" (@{ username = $testUser; password = 'e2ePass123' } | ConvertTo-Json))
T 'duplicate registration rejected' ($r.success -eq $false) ("msg=" + $r.msg)

$r = AsJson (Req "$BaseUrl/api/register.php" (@{ username = "x_$stamp"; password = '123' } | ConvertTo-Json))
T 'short password rejected' ($r.success -eq $false) ("msg=" + $r.msg)

Write-Output '===== C. draft survey and draft protection ====='
$draft = @{
    title = "E2E-draft-$stamp"; description = 'draft guard'; status = 'draft'
    questions = @(@{ title = 'E2E_draft_q'; type = 'text'; required = $false; options = @() })
} | ConvertTo-Json -Depth 6
$r = AsJson (Req "$BaseUrl/api/create_survey.php" $draft $sess)
T 'create draft survey' ($r.success -eq $true) ("id=" + $r.survey_id)
$draftId = $r.survey_id

$r = AsJson (Req "$BaseUrl/api/get_survey.php?id=$draftId")
T 'anonymous cannot read draft' ($r.success -eq $false) ("msg=" + $r.msg)
$r = AsJson (Req "$BaseUrl/api/get_survey.php?id=$draftId" $null $sess)
T 'owner can read own draft' ($r.success -eq $true)

Write-Output '===== D. published survey ====='
$pub = @{
    title = "E2E-pub-$stamp"; description = 'acceptance test'; status = 'published'
    questions = @(
        @{ title = 'Q1 recommend'; type = 'radio'; required = $true; options = @('yes','no') },
        @{ title = 'Q2 comment';   type = 'text';  required = $false; options = @() }
    )
} | ConvertTo-Json -Depth 6
$r = AsJson (Req "$BaseUrl/api/create_survey.php" $pub $sess)
T 'create published survey' ($r.success -eq $true) ("id=" + $r.survey_id)
$pubId = $r.survey_id

$r = AsJson (Req "$BaseUrl/api/get_surveys.php" $null $sess)
T 'survey list loads' ($r.success -eq $true) ("count=" + $r.data.Count)

$detail = AsJson (Req "$BaseUrl/api/get_survey.php?id=$pubId")
T 'anonymous can read published survey' ($detail.success -eq $true) ("questions=" + $detail.questions.Count)
$q1 = $detail.questions[0]; $q2 = $detail.questions[1]
T 'radio options returned' (($q1.options | Measure-Object).Count -eq 2) ("opt=" + ($q1.options.text -join '/'))
T 'required flag preserved' ([int]$q1.required -eq 1)

$raw = (Req "$BaseUrl/api/get_survey.php?id=$pubId").Content
T 'get_survey returns exactly one JSON object' (($raw -split '\}\s*\{').Count -eq 1)

Write-Output '===== E. submission ====='
$form = "survey_id=$pubId&q$($q2.id)=only-optional"
$r = AsJson (Req "$BaseUrl/api/submit_response.php" $null $null $form)
T 'missing required answer rejected' ($r.success -eq $false) ("msg=" + $r.msg)

$form = "survey_id=$draftId&q1=abc"
$r = AsJson (Req "$BaseUrl/api/submit_response.php" $null $null $form)
T 'submission to draft rejected' ($r.success -eq $false) ("msg=" + $r.msg)

$form = "survey_id=$pubId&q$($q1.id)=yes&q$($q2.id)=please-add-charts&q999999=dirty"
$r = AsJson (Req "$BaseUrl/api/submit_response.php" $null $null $form)
T 'valid submission accepted (submit_time column OK)' ($r.success -eq $true) ("msg=" + $r.msg)

$form = "survey_id=$pubId&q$($q1.id)=no"
$r = AsJson (Req "$BaseUrl/api/submit_response.php" $null $null $form)
T 'second submission accepted' ($r.success -eq $true)

Write-Output '===== F. results and export ====='
$r = AsJson (Req "$BaseUrl/api/get_responses.php?survey_id=$pubId" $null $sess)
T 'results endpoint works' ($r.success -eq $true) ("total=" + $r.stats.total_responses)
T 'exactly 2 responses recorded' ($r.stats.total_responses -eq 2)
T 'response carries submit_time' ($null -ne $r.data[0].submit_time) ("time=" + $r.data[0].submit_time)
$bogus = @($r.data | ForEach-Object { $_.answers.PSObject.Properties.Name } | Where-Object { $_ -eq '999999' })
T 'foreign question id was discarded' ($bogus.Count -eq 0)

$csv = (Req "$BaseUrl/api/export_results.php?survey_id=$pubId" $null $sess).Content
T 'CSV export works' ($csv -match 'Q1 recommend') ("len=" + $csv.Length)
T 'CSV has UTF-8 BOM' ($csv.Length -gt 0 -and [int][char]$csv[0] -eq 0xFEFF)

$r = AsJson (Req "$BaseUrl/api/get_responses.php?survey_id=$pubId")
T 'anonymous cannot read results' ($r.success -eq $false) ("msg=" + $r.msg)

Write-Output '===== G. delete and cascade ====='
$r = AsJson (Req "$BaseUrl/api/delete_survey.php" (@{ survey_id = $pubId } | ConvertTo-Json) $sess)
T 'delete survey succeeds' ($r.success -eq $true) ("msg=" + $r.msg)

if ($MysqlExe) {
    $sql = "SELECT (SELECT COUNT(*) FROM $DbName.questions WHERE survey_id=$pubId)" +
           "+(SELECT COUNT(*) FROM $DbName.responses WHERE survey_id=$pubId)" +
           "+(SELECT COUNT(*) FROM $DbName.options WHERE question_id NOT IN (SELECT id FROM $DbName.questions))" +
           "+(SELECT COUNT(*) FROM $DbName.answers WHERE response_id NOT IN (SELECT id FROM $DbName.responses));"
    $orphan = Sql $sql
    if ($null -eq $orphan -or ([string]$orphan).Trim() -eq '') {
        T 'no orphan rows after delete' $false 'mysql client returned nothing - check -MysqlExe/-DbPass'
    } else {
        T 'no orphan rows after delete' ([int]$orphan -eq 0) ("orphans=" + $orphan)
    }
} else {
    S 'no orphan rows after delete' 'no -MysqlExe given'
}

Write-Output '===== H. cleanup ====='
$null = Sql "DELETE FROM $DbName.surveys WHERE title LIKE 'E2E-%'; DELETE FROM $DbName.questions WHERE title='E2E_draft_q'; DELETE FROM $DbName.users WHERE username LIKE 'e2e_%' OR username LIKE 'x\_%';"
if ($MysqlExe) {
    $left = Sql "SELECT CONCAT('surveys=',(SELECT COUNT(*) FROM $DbName.surveys),' users=',(SELECT COUNT(*) FROM $DbName.users));"
    if ($null -eq $left -or ([string]$left).Trim() -eq '') {
        T 'test data cleaned up' $false 'mysql client returned nothing - check -MysqlExe/-DbPass'
    } else {
        Write-Output ("Remaining rows: " + $left)
        T 'test data cleaned up' ($left -match 'surveys=0 users=0')
    }
} else {
    S 'test data cleaned up' 'no -MysqlExe given; delete the e2e_* account manually'
}

Write-Output ''
Write-Output ("===== RESULT: PASS=" + $pass + "  FAIL=" + $fail + "  SKIP=" + $skip + " =====")
if ($fail -gt 0) { exit 1 } else { exit 0 }
