# Velocity launcher: starts the database, the app server and the live (Vite) server,
# opens Velocity in its own Chrome window, and stops everything it started when the last
# Velocity window is closed. Run it through Velocity.bat (a copy sits on the Desktop).
#
# A small "Starting Velocity" box shows at once, so one click is enough. Clicking again
# while it runs brings the open window to the front instead of opening another one.
# Anything already running (say, started by hand) is left alone: used, but not stopped.

$Project = Split-Path $PSScriptRoot -Parent
$Xampp   = 'C:\xampp'
$Port    = 8200
$Url     = "http://localhost:$Port"
$State   = Join-Path $env:LOCALAPPDATA 'Velocity'
$BrowserData = Join-Path $State 'browser'        # its own browser profile: its windows are Velocity's and nothing else
$Log     = Join-Path $State 'launcher.log'
$Dots    = [char]0x2026
New-Item -ItemType Directory -Force $State | Out-Null

Add-Type -AssemblyName System.Windows.Forms, System.Drawing
Add-Type -Namespace Velocity -Name Win -MemberDefinition @'
[DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr h);
[DllImport("user32.dll")] public static extern bool ShowWindowAsync(IntPtr h, int cmd);
[DllImport("user32.dll")] public static extern bool IsIconic(IntPtr h);
[DllImport("user32.dll")] public static extern bool SetProcessDPIAware();
'@

function Log($text) { Add-Content -Path $Log -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $text" }
function Listening($port) { [bool](Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue) }
# Waiting, while keeping the "Starting" box alive.
function Pause($ms) {
    $until = (Get-Date).AddMilliseconds($ms)
    do { if ($script:splash) { [System.Windows.Forms.Application]::DoEvents() }; Start-Sleep -Milliseconds 50 } while ((Get-Date) -lt $until)
}
function WaitFor($port, $seconds) {
    $until = (Get-Date).AddSeconds($seconds)
    while ((Get-Date) -lt $until) { if (Listening $port) { return $true }; Pause 300 }
    return $false
}
function WaitExit($process, $seconds) {
    $until = (Get-Date).AddSeconds($seconds)
    while (-not $process.HasExited -and (Get-Date) -lt $until) { Pause 200 }
    return $process.HasExited
}
function Hidden($file, $arguments, $name) {
    $p = Start-Process -FilePath $file -ArgumentList $arguments -WorkingDirectory $Project -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $State "$name.out.log") -RedirectStandardError (Join-Path $State "$name.err.log")
    $null = $p.Handle # without this, PowerShell forgets the exit code once the process ends
    $p
}
function StopTree($process) { if ($process -and -not $process.HasExited) { & taskkill.exe /PID $process.Id /T /F 2>&1 | Out-Null } }
function Message($text) {
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show($text, 'Velocity') | Out-Null
}

# Velocity's windows: the browser running with Velocity's own profile (one main process, however many windows).
function VelocityBrowser {
    Get-CimInstance Win32_Process -Filter "Name='chrome.exe' OR Name='msedge.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine.Contains($BrowserData) -and $_.CommandLine -notmatch '--type=' }
}
# Brings a Velocity window to the front (restored if minimised). False when there is none.
function BringForward {
    foreach ($b in VelocityBrowser) {
        $h = (Get-Process -Id $b.ProcessId -ErrorAction SilentlyContinue).MainWindowHandle
        if ($h -and $h -ne [IntPtr]::Zero) {
            if ([Velocity.Win]::IsIconic($h)) { [void][Velocity.Win]::ShowWindowAsync($h, 9) }
            [void][Velocity.Win]::SetForegroundWindow($h)
            return $true
        }
    }
    return $false
}

# The "Starting Velocity" box: shown at once, with the step it is on, until the window is up.
$splash = $null; $splashText = $null
function Splash($text) {
    if (-not $script:splash) {
        [void][Velocity.Win]::SetProcessDPIAware()
        [System.Windows.Forms.Application]::EnableVisualStyles()
        $f = New-Object System.Windows.Forms.Form
        $k = $f.CreateGraphics().DpiX / 96
        $px = { param($n) [int]($n * $k) }
        $f.Text = 'Velocity'
        $f.FormBorderStyle = 'None'
        $f.StartPosition = 'CenterScreen'
        $f.ClientSize = New-Object System.Drawing.Size((& $px 340), (& $px 112))
        $f.TopMost = $true
        $f.BackColor = [System.Drawing.Color]::FromArgb(28, 28, 32)
        $title = New-Object System.Windows.Forms.Label
        $title.Text = 'Velocity'
        $title.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 14)
        $title.ForeColor = [System.Drawing.Color]::White
        $title.AutoSize = $true
        $title.Location = New-Object System.Drawing.Point((& $px 24), (& $px 18))
        $label = New-Object System.Windows.Forms.Label
        $label.Font = New-Object System.Drawing.Font('Segoe UI', 10)
        $label.ForeColor = [System.Drawing.Color]::FromArgb(170, 170, 180)
        $label.AutoSize = $true
        $label.Location = New-Object System.Drawing.Point((& $px 24), (& $px 54))
        $bar = New-Object System.Windows.Forms.ProgressBar
        $bar.Style = 'Marquee'
        $bar.MarqueeAnimationSpeed = 25
        $bar.Location = New-Object System.Drawing.Point((& $px 24), (& $px 86))
        $bar.Size = New-Object System.Drawing.Size((& $px 292), (& $px 6))
        $f.Controls.AddRange(@($title, $label, $bar))
        $f.Show()
        $script:splash = $f; $script:splashText = $label
    }
    $script:splashText.Text = $text
    [System.Windows.Forms.Application]::DoEvents()
}
function CloseSplash { if ($script:splash) { $script:splash.Close(); $script:splash.Dispose(); $script:splash = $null } }

$browser = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1
$window = @("--app=$Url", "--user-data-dir=`"$BrowserData`"", '--no-first-run', '--no-default-browser-check', '--disable-background-mode', '--window-size=1440,900')

# Only one launcher looks after the servers. Another click brings its window to the front; while it is
# still starting (its box is up) or closing down, this one waits: for the window, or to start afresh.
$created = $false
$mutex = New-Object System.Threading.Mutex($true, 'Local\VelocityLauncher', [ref]$created)
if (-not $created) {
    $until = (Get-Date).AddSeconds(120)
    while ($true) {
        if (BringForward) { exit }
        try { if ($mutex.WaitOne(500)) { break } } catch [System.Threading.AbandonedMutexException] { break }
        if ((Get-Date) -gt $until) { exit }
    }
}

$db = $null; $app = $null; $vite = $null; $build = $null; $startedDb = $false
try {
    Log '--- starting'
    if (-not $browser) { throw 'Chrome or Edge was not found on this computer.' }
    Splash "Starting$Dots"

    # A window left over from a launcher that was stopped shows nothing: close it first.
    if (-not (Listening $Port) -and (VelocityBrowser)) {
        foreach ($b in VelocityBrowser) { & taskkill.exe /PID $b.ProcessId 2>&1 | Out-Null }
        $until = (Get-Date).AddSeconds(5)
        while ((VelocityBrowser) -and (Get-Date) -lt $until) { Pause 300 }
        foreach ($b in VelocityBrowser) { & taskkill.exe /PID $b.ProcessId /T /F 2>&1 | Out-Null }
        Log 'closed a window left from before'
    }

    # 1. Database (XAMPP MariaDB)
    if (-not (Listening 3306)) {
        Splash "Starting the database$Dots"
        $db = Hidden "$Xampp\mysql\bin\mysqld.exe" @("--defaults-file=$Xampp\mysql\bin\my.ini", '--standalone') 'database'
        $startedDb = $true
        if (-not (WaitFor 3306 30)) { throw "The database did not start. See $State\database.err.log" }
        Log 'database started'
    } else { Log 'database was already running' }

    # New tables and columns from updates (only ever adds; your data stays as it is).
    Splash "Updating the database$Dots"
    $migrate = Hidden "$Xampp\php\php.exe" @('artisan', 'migrate', '--force') 'migrate'
    if ((WaitExit $migrate 120) -and $migrate.ExitCode -eq 0) { Log 'database up to date' } else { StopTree $migrate; Log "database update failed; see $State\migrate.err.log" }

    # 2. Live server (Vite): changes to the code show up in the open window by themselves.
    #    Started before the app, so the first page already uses it.
    if (-not (Listening 5173)) {
        Splash "Starting the live server$Dots"
        Remove-Item (Join-Path $Project 'public\hot') -ErrorAction SilentlyContinue # left by a live server that was stopped
        $vite = Hidden 'cmd.exe' @('/c', 'npm run dev') 'vite'
        if (-not (WaitFor 5173 40)) {
            # Not fatal: without it the app uses the last build.
            StopTree $vite; $vite = $null
            Remove-Item (Join-Path $Project 'public\hot') -ErrorAction SilentlyContinue
            Log 'live server did not start; using the last build'
        } else { Log 'live server started' }
    } else { Log 'live server was already running' }

    # 3. App server, open to this computer and to phones on the same Wi-Fi
    if (-not (Listening $Port)) {
        Splash "Starting the app$Dots"
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

    # 4. The window (or the one already open, brought to the front). The box goes once it is up.
    Splash "Opening$Dots"
    if (-not (BringForward)) {
        Start-Process $browser -ArgumentList $window | Out-Null
        $until = (Get-Date).AddSeconds(15)
        while (-not (BringForward) -and (Get-Date) -lt $until) { Pause 300 }
        # The first page takes a few seconds to build: the box stays until it is drawn (its title then gets a middle dot: "Up next, dot, Velocity").
        $ids = @(VelocityBrowser | ForEach-Object { $_.ProcessId })
        $drawn = { $ids | Where-Object { "$((Get-Process -Id $_ -ErrorAction SilentlyContinue).MainWindowTitle)".Contains([char]0x00B7) } }
        $until = (Get-Date).AddSeconds(25)
        while (-not (& $drawn) -and (Get-Date) -lt $until) { Pause 300 }
    }
    CloseSplash
    Log "window opened ($([IO.Path]::GetFileName($browser)))"

    # 5. Phones can't reach the live server, so they get the built app: build it now, behind the window.
    if ($vite) { $build = Hidden 'cmd.exe' @('/c', 'npm run build') 'build' }

    # Wait until the last Velocity window is closed (checked every 2 seconds; twice, to be sure).
    $gone = 0
    while ($gone -lt 2) {
        Start-Sleep -Seconds 2
        if ($build -and $build.HasExited) {
            if ($build.ExitCode -eq 0) { Log 'app built for phones' } else { Log "build failed; phones get the last build (see $State\build.err.log)" }
            $build = $null
        }
        if (VelocityBrowser) { $gone = 0 } else { $gone++ }
    }
    Log 'window closed'
}
catch {
    CloseSplash
    Log "ERROR: $($_.Exception.Message)"
    Message "Velocity could not start.`n`n$($_.Exception.Message)"
}
finally {
    CloseSplash
    # Stop only what this launcher started.
    # A build half done would leave phones with no app: let it finish first (a minute at most).
    if ($build) { if (-not $build.WaitForExit(60000)) { StopTree $build; Log 'build stopped' } }
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
