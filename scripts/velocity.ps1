# Velocity launcher: starts the database, the app server and the live (Vite) server,
# opens Velocity in its own Chrome window, and stops everything it started when that
# window is closed. Run it through Velocity.bat (a copy sits on the Desktop).
#
# Anything already running (say, started by hand) is left alone: used, but not stopped.

$Project = Split-Path $PSScriptRoot -Parent
$Xampp   = 'C:\xampp'
$Port    = 8200
$Url     = "http://localhost:$Port"
$State   = Join-Path $env:LOCALAPPDATA 'Velocity'
$BrowserData = Join-Path $State 'browser'        # its own browser profile, so closing the window can be noticed
$Log     = Join-Path $State 'launcher.log'
New-Item -ItemType Directory -Force $State | Out-Null

function Log($text) { Add-Content -Path $Log -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $text" }
function Listening($port) { [bool](Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) }
function WaitFor($port, $seconds) {
    $until = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $until) { if (Listening $port) { return $true }; Start-Sleep -Milliseconds 300 }
    return $false
}
function Hidden($file, $arguments, $name) {
    Start-Process -FilePath $file -ArgumentList $arguments -WorkingDirectory $Project -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $State "$name.out.log") -RedirectStandardError (Join-Path $State "$name.err.log")
}
function StopTree($process) { if ($process -and -not $process.HasExited) { & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null } }
function Message($text) {
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show($text, 'Velocity') | Out-Null
}

$browser = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
$window = @("--app=$Url", "--user-data-dir=`"$BrowserData`"", '--no-first-run', '--no-default-browser-check', '--disable-background-mode', '--window-size=1440,900')

# Only one launcher looks after the servers. A second double-click just brings up the window.
$created = $false
$mutex = New-Object System.Threading.Mutex($true, 'Local\VelocityLauncher', [ref]$created)
if (-not $created) {
    if ($browser) { Start-Process $browser -ArgumentList $window }
    exit
}

$db = $null; $app = $null; $vite = $null; $startedDb = $false
try {
    Log '--- starting'
    if (-not $browser) { throw 'Chrome or Edge was not found on this computer.' }

    # 1. Database (XAMPP MariaDB)
    if (-not (Listening 3306)) {
        $db = Hidden "$Xampp\mysql\bin\mysqld.exe" @("--defaults-file=$Xampp\mysql\bin\my.ini", '--standalone') 'database'
        $startedDb = $true
        if (-not (WaitFor 3306 30)) { throw "The database did not start. See $State\database.err.log" }
        Log 'database started'
    } else { Log 'database was already running' }

    # 2. App server, open to this computer and to phones on the same Wi-Fi
    if (-not (Listening $Port)) {
        $app = Hidden "$Xampp\php\php.exe" @('artisan', 'serve', '--host=0.0.0.0', "--port=$Port", '--no-reload') 'app'
        if (-not (WaitFor $Port 30)) { throw "The app server did not start. See $State\app.err.log" }
        Log 'app server started'
    } else { Log 'app server was already running' }
    # The address a phone on the same Wi-Fi uses (it can change when the router hands out a new one).
    $lan = Get-NetIPConfiguration -ErrorAction SilentlyContinue | Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } |
        Select-Object -First 1 -ExpandProperty IPv4Address | Select-Object -First 1 -ExpandProperty IPAddress
    if ($lan) {
        Set-Content -Path (Join-Path $State 'phone-link.txt') -Value "http://${lan}:$Port"
        Log "on your phone (same Wi-Fi): http://${lan}:$Port"
    }

    # 3. Live server (Vite): changes to the code show up in the open window by themselves.
    #    Phones can't reach it, so they get the built app: build it first, so it's up to date.
    if (-not (Listening 5173)) {
        Remove-Item (Join-Path $Project 'public\hot') -ErrorAction SilentlyContinue
        $build = Hidden 'cmd.exe' @('/c', 'npm run build') 'build'
        if ($build.WaitForExit(120000) -and $build.ExitCode -eq 0) { Log 'app built for phones' } else { StopTree $build; Log 'build failed or took too long; phones get the last build' }
        $vite = Hidden 'cmd.exe' @('/c', 'npm run dev') 'vite'
        if (-not (WaitFor 5173 40)) {
            # Not fatal: without it the app uses the last build.
            StopTree $vite; $vite = $null
            Remove-Item (Join-Path $Project 'public\hot') -ErrorAction SilentlyContinue
            Log 'live server did not start; using the last build'
        } else { Log 'live server started' }
    } else { Log 'live server was already running' }

    # 4. The window. The script waits here until it is closed.
    $win = Start-Process $browser -ArgumentList $window -PassThru
    Log "window opened ($([IO.Path]::GetFileName($browser)))"
    $win.WaitForExit()
    Log 'window closed'
}
catch {
    Log "ERROR: $($_.Exception.Message)"
    Message "Velocity could not start.`n`n$($_.Exception.Message)"
}
finally {
    # Stop only what this launcher started.
    if ($vite) { StopTree $vite; Remove-Item (Join-Path $Project 'public\hot') -ErrorAction SilentlyContinue; Log 'live server stopped' }
    if ($app) { StopTree $app; Log 'app server stopped' }
    if ($startedDb) {
        # Ask the database to shut down cleanly; only force it if that fails.
        & "$Xampp\mysql\bin\mysqladmin.exe" -u root shutdown 2>&1 | Out-Null
        if ($db -and -not $db.WaitForExit(15000)) { StopTree $db }
        Log 'database stopped'
    }
    $mutex.ReleaseMutex()
    Log '--- done'
}
