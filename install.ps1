# install.ps1 - interactive TUI installer for App (Windows PowerShell)
# Usage (one line):
#   irm https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/install.ps1 | iex

$ErrorActionPreference = "Stop"
$Root = (Get-Location).Path
$EnvFile = Join-Path $Root ".env"
$ComposeFile = Join-Path $Root "docker-compose.yml"
$ComposeUrl = "https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/docker-compose.yml"

function Say($Text, [ConsoleColor]$Color = [ConsoleColor]::White) {
    Write-Host $Text -ForegroundColor $Color
}
function Title($Text) {
    Write-Host ""
    Say ("=" * 78) Cyan
    Say ("  " + $Text) Cyan
    Say ("=" * 78) Cyan
}
function Info($Text) { Write-Host ("  " + $Text) }
function Warn($Text) { Say ("  [warn] " + $Text) Yellow }
function Ok($Text) { Say ("  [ok]   " + $Text) Green }
function Err($Text) { Say ("  [error] " + $Text) Red }

function Read-Secret([string]$Prompt) {
    $secure = Read-Host $Prompt -AsSecureString
    return [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
}

function Ask([string]$Prompt, [string]$Default = "") {
    if ($Default) { $shown = "$Prompt [$Default]: " } else { $shown = "$Prompt`: " }
    $value = Read-Host $shown
    if ([string]::IsNullOrWhiteSpace($value)) { return $Default }
    return $value.Trim()
}

function Ask-Choice([string]$Prompt, [string]$Default, [string[]]$Choices) {
    Write-Host ("  " + $Prompt)
    for ($i = 0; $i -lt $Choices.Count; $i++) {
        $mark = ""
        if ($Choices[$i] -eq $Default) { $mark = " (default)" }
        Write-Host ("    " + ($i + 1) + ". " + $Choices[$i] + $mark)
    }
    $value = Read-Host "  Select"
    if ([string]::IsNullOrWhiteSpace($value)) { return $Default }
    if ($value -match '^\d+$' -and [int]$value -ge 1 -and [int]$value -le $Choices.Count) {
        return $Choices[[int]$value - 1]
    }
    if ($Choices -contains $value) { return $value }
    return $Default
}

function Yes-No([string]$Prompt, [string]$Default = "yes") {
    $ans = Read-Host ("  " + $Prompt + " [" + $Default + "]")
    if ([string]::IsNullOrWhiteSpace($ans)) { $ans = $Default }
    if ($ans -match '^(y|yes|true|1)$') { return "yes" }
    return "no"
}

function Command-Exists([string]$Name) {
    return ($null -ne (Get-Command $Name -ErrorAction SilentlyContinue))
}

function Test-Redis([string]$Host, [int]$Port, [string]$Password) {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $client.ReceiveTimeout = 3000
        $client.SendTimeout = 3000
        $client.Connect($Host, $Port)
        $stream = $client.GetStream()
        $writer = New-Object System.IO.StreamWriter($stream)
        $reader = New-Object System.IO.StreamReader($stream)
        $writer.AutoFlush = $true
        if ($Password -and $Password -ne "null") {
            $writer.WriteLine("AUTH $Password")
            [void]$reader.ReadLine()
        }
        $writer.WriteLine("PING")
        $resp = $reader.ReadLine()
        $client.Close()
        return ($resp -match "PONG")
    } catch {
        return $false
    }
}

function Test-Rpc([string]$Url) {
    if ([string]::IsNullOrWhiteSpace($Url)) { return @($null, "skipped") }
    try {
        $body = '{"jsonrpc":"2.0","method":"eth_chainId","params":[],"id":1}'
        $resp = Invoke-RestMethod -Uri $Url -Method Post -Body $body -ContentType "application/json" -TimeoutSec 8
        if ($resp.result) { return @($true, $resp.result) }
        return @($false, "FAIL")
    } catch {
        return @($false, $_.Exception.Message)
    }
}

function Gen-Key {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return "base64:" + [Convert]::ToBase64String($bytes)
}


$Update = ($args -contains "--update" -or $args -contains "-Update")
if ($Update) {
    Title "App Update"
    if (-not (Test-Path $EnvFile)) {
        Err "No .env found in $Root. Run the installer first."
        return
    }
    if (-not (Test-Path $ComposeFile)) {
        Info "Downloading docker-compose.yml ..."
        try { Invoke-WebRequest -Uri $ComposeUrl -OutFile $ComposeFile -UseBasicParsing } catch { Err $_.Exception.Message; return }
    }
    $DB_USER = (Select-String -Path $EnvFile -Pattern '^DB_USERNAME=' | Select-Object -Last 1).Line.Split('=',2)[1].Trim('"')
    if ([string]::IsNullOrWhiteSpace($DB_USER)) { $DB_USER = "app" }
    $DB_NAME = (Select-String -Path $EnvFile -Pattern '^DB_DATABASE=' | Select-Object -Last 1).Line.Split('=',2)[1].Trim('"')
    if ([string]::IsNullOrWhiteSpace($DB_NAME)) { $DB_NAME = "app" }

    $backupDir = Join-Path $Root "backups"
    $dbCid = (docker compose ps -q db 2>$null)
    if ($dbCid) {
        New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
        $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
        $backupFile = Join-Path $backupDir ("app-" + $stamp + ".sql")
        try {
            docker compose exec -T db sh -c ("pg_dump -U '" + $DB_USER + "' '" + $DB_NAME + "'") | Out-File -FilePath $backupFile -Encoding utf8
            Ok ("Database backup written to " + $backupFile)
        } catch {
            Warn "Database backup failed; continuing update."
        }
    }

    Title "Pulling latest images"
    & docker compose pull
    if ($LASTEXITCODE -ne 0) { Err "docker compose pull failed."; return }

    Title "Starting updated containers"
    & docker compose up -d
    if ($LASTEXITCODE -ne 0) { Err "docker compose up failed."; return }

    $healthy = $false
    for ($i = 0; $i -lt 24; $i++) {
        $status = docker inspect --format '{{.State.Health.Status}}' app 2>$null
        if ($status -eq "healthy") { $healthy = $true; break }
        if ($status -eq "unhealthy") { break }
        Start-Sleep -Seconds 5
    }
    if ($healthy) { Ok "Application is healthy." } else { Warn "Application did not report healthy yet; see docker logs app." }

    $appUrlLine = Select-String -Path $EnvFile -Pattern '^APP_URL=' | Select-Object -Last 1
    $APP_URL = if ($appUrlLine) { $appUrlLine.Line.Split('=',2)[1].Trim('"') } else { "http://localhost:8000" }
    Info ("App URL: " + $APP_URL)
    Info "Note: database migrations run automatically on container start."
    Ok "Update complete."
    return
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Err "Docker is required but was not found. Install Docker first."
    exit 1
}
Ok "Docker found."

if (Test-Path $EnvFile) {
    Copy-Item $EnvFile (Join-Path $Root ".env.previous") -Force
    Warn "Existing .env backed up to .env.previous"
}

Title "App TUI Installer (PowerShell)"
Info "Interactive setup for Docker-based self-hosting."
Info "Press Enter to accept a suggested default."

# Application
Title "Application"
$APP_NAME = Ask "Application name" "OpenSinop"
$APP_ENV = "production"
$APP_DEBUG = "false"
$APP_PORT = Ask "Published HTTP port" "8000"
$APP_URL = Ask "Public URL" ("http://localhost:" + $APP_PORT)
$APP_KEY = Gen-Key
Ok "Generated APP_KEY"

# Database
Title "Database"
$DB_CONNECTION = Ask-Choice "Database driver" "pgsql" @("pgsql", "mysql", "sqlite")
if ($DB_CONNECTION -eq "sqlite") {
    $DB_HOST = ""; $DB_PORT = ""; $DB_DATABASE = "database/database.sqlite"; $DB_USERNAME = ""; $DB_PASSWORD = ""
} else {
    $DB_HOST = Ask "DB host" "db"
    $DB_PORT = Ask "DB port" ($DB_CONNECTION -eq "pgsql" ? "5432" : "3306")
    $DB_DATABASE = Ask "DB name" "app"
    $DB_USERNAME = Ask "DB username" "app"
    $DB_PASSWORD = Read-Secret "DB password"
    if ([string]::IsNullOrWhiteSpace($DB_PASSWORD)) { $DB_PASSWORD = "app" }
}

# Cache/session/queue
Title "Cache, session and queue"
# Common environment defaults (production)
$CACHE_STORE = "database"
$SESSION_DRIVER = "database"
$QUEUE_CONNECTION = "database"
$REDIS_HOST = "127.0.0.1"
$REDIS_PORT = "6379"
$REDIS_PASSWORD = "null"

# Mail
Title "Mail"
# Mail defaults
$MAIL_MAILER = "log"
$MAIL_HOST = "127.0.0.1"
$MAIL_PORT = "2525"
$MAIL_USERNAME = ""
$MAIL_PASSWORD = ""
$MAIL_SCHEME = "null"
$MAIL_FROM_ADDRESS = "hello@example.com"
$MAIL_FROM_NAME = $APP_NAME

# Institution
Title "School identity"
$EDUFLOW_INSTITUTION = Ask "Institution name" "My School"
$EDUFLOW_COUNTRY = (Ask "Country (2-letter code)" "US").ToUpper()
$EDUFLOW_TIMEZONE = Ask "Timezone" "UTC"
$EDUFLOW_CURRENCY = (Ask "Accounting currency" "USD").ToUpper()

# Lepton
Title "Lepton agent (Circle + Arc)"
$LEPTON_DRIVER = Ask-Choice "Lepton driver" "fake" @("fake", "circle")
$LEPTON_CHAIN = Ask-Choice "Chain" "ARC-TESTNET" @("ARC-TESTNET", "ARC-MAINNET")
$LEPTON_DRY_RUN = Ask-Choice "Dry run (no real transfers)" "true" @("true", "false")
$LEPTON_TREASURY_ADDRESS = Ask "Treasury wallet address (empty for fake)" ""
$LEPTON_RPC_URL = Ask "Arc RPC URL (empty for default)" ""

# AI
Title "Optional AI advisory"
$AI_PROVIDER = Ask-Choice "AI provider" "none" @("none", "local", "openai", "anthropic")
$AI_LOCAL_URL = ""; $AI_LOCAL_MODEL = ""; $AI_LOCAL_API_KEY = ""; $OPENAI_API_KEY = ""; $ANTHROPIC_API_KEY = ""
if ($AI_PROVIDER -eq "local") {
    $AI_LOCAL_URL = Ask "Local OpenAI-compatible URL" "http://127.0.0.1:11434/v1"
    $AI_LOCAL_MODEL = Ask "Local model" "llama3.1"
    $AI_LOCAL_API_KEY = Read-Secret "Local API key (optional)"
} elseif ($AI_PROVIDER -eq "openai") {
    $OPENAI_API_KEY = Read-Secret "OpenAI API key"
} elseif ($AI_PROVIDER -eq "anthropic") {
    $ANTHROPIC_API_KEY = Read-Secret "Anthropic API key"
}
if ($AI_PROVIDER -eq "none") { $AI_PROVIDER = "local" }

# Live checks
Title "Live network checks"
$rpc = Test-Rpc $LEPTON_RPC_URL
if ($rpc[0] -eq $null) {
    Info "Arc RPC check skipped (no URL supplied)."
} elseif ($rpc[0]) {
    Ok ("Arc RPC reachable: chainId=" + $rpc[1])
} else {
    Warn ("Arc RPC check failed: " + $rpc[1])
}

if (Command-Exists "circle") { Ok "Circle CLI found on PATH." } else { Warn "Circle CLI not found on PATH (needed only for circle driver)." }

# Review
Title "Review"
Write-Host ("  APP_NAME=" + $APP_NAME)
Write-Host ("  APP_ENV=" + $APP_ENV + " APP_DEBUG=" + $APP_DEBUG)
Write-Host ("  DB_CONNECTION=" + $DB_CONNECTION)
Write-Host ("  CACHE_STORE=" + $CACHE_STORE + " SESSION_DRIVER=" + $SESSION_DRIVER + " QUEUE_CONNECTION=" + $QUEUE_CONNECTION)
Write-Host ("  LEPTON_DRIVER=" + $LEPTON_DRIVER + " CHAIN=" + $LEPTON_CHAIN)
Write-Host ""
if ((Yes-No "Write this configuration and start Docker?" "yes") -ne "yes") {
    Warn "Aborted. No changes were made."
    return
}

if (-not (Test-Path $ComposeFile)) {
    Info "Downloading docker-compose.yml ..."
    try {
        Invoke-WebRequest -Uri $ComposeUrl -OutFile $ComposeFile -UseBasicParsing
        Ok "Downloaded docker-compose.yml"
    } catch {
        Err ("Could not download compose file: " + $_.Exception.Message)
        exit 1
    }
}

# Write .env
$lines = @()
$lines += 'APP_NAME="' + $APP_NAME + '"'
$lines += 'APP_ENV="' + $APP_ENV + '"'
$lines += 'APP_KEY="' + $APP_KEY + '"'
$lines += 'APP_DEBUG="' + $APP_DEBUG + '"'
$lines += 'APP_URL="' + $APP_URL + '"'
$lines += 'APP_LOCALE="en"'
$lines += 'APP_FALLBACK_LOCALE="en"'
$lines += 'BCRYPT_ROUNDS=12'
$lines += 'LOG_CHANNEL=stack'
$lines += 'LOG_STACK=single'
$lines += 'LOG_LEVEL=warning'
$lines += 'DB_CONNECTION="' + $DB_CONNECTION + '"'
$lines += 'DB_HOST="' + $DB_HOST + '"'
$lines += 'DB_PORT="' + $DB_PORT + '"'
$lines += 'DB_DATABASE="' + $DB_DATABASE + '"'
$lines += 'DB_USERNAME="' + $DB_USERNAME + '"'
$lines += 'DB_PASSWORD="' + $DB_PASSWORD + '"'
$lines += 'CACHE_STORE="' + $CACHE_STORE + '"'
$lines += 'SESSION_DRIVER="' + $SESSION_DRIVER + '"'
$lines += 'QUEUE_CONNECTION="' + $QUEUE_CONNECTION + '"'
$lines += 'BROADCAST_CONNECTION=log'
$lines += 'FILESYSTEM_DISK=local'
$lines += 'MAIL_MAILER="' + $MAIL_MAILER + '"'
$lines += 'MAIL_HOST="' + $MAIL_HOST + '"'
$lines += 'MAIL_PORT="' + $MAIL_PORT + '"'
$lines += 'MAIL_USERNAME="' + $MAIL_USERNAME + '"'
$lines += 'MAIL_PASSWORD="' + $MAIL_PASSWORD + '"'
$lines += 'MAIL_SCHEME="' + $MAIL_SCHEME + '"'
$lines += 'MAIL_FROM_ADDRESS="' + $MAIL_FROM_ADDRESS + '"'
$lines += 'MAIL_FROM_NAME="' + $MAIL_FROM_NAME + '"'
$lines += 'EDUFLOW_INSTITUTION="' + $EDUFLOW_INSTITUTION + '"'
$lines += 'EDUFLOW_COUNTRY="' + $EDUFLOW_COUNTRY + '"'
$lines += 'EDUFLOW_TIMEZONE="' + $EDUFLOW_TIMEZONE + '"'
$lines += 'EDUFLOW_CURRENCY="' + $EDUFLOW_CURRENCY + '"'
$lines += 'LEPTON_DRIVER="' + $LEPTON_DRIVER + '"'
$lines += 'LEPTON_CHAIN="' + $LEPTON_CHAIN + '"'
$lines += 'LEPTON_DRY_RUN="' + $LEPTON_DRY_RUN + '"'
$lines += 'LEPTON_TREASURY_ADDRESS="' + $LEPTON_TREASURY_ADDRESS + '"'
$lines += 'LEPTON_RPC_URL="' + $LEPTON_RPC_URL + '"'
$lines += 'AI_PROVIDER="' + $AI_PROVIDER + '"'
$lines += 'AI_LOCAL_URL="' + $AI_LOCAL_URL + '"'
$lines += 'AI_LOCAL_MODEL="' + $AI_LOCAL_MODEL + '"'
$lines += 'AI_LOCAL_API_KEY="' + $AI_LOCAL_API_KEY + '"'
$lines += 'OPENAI_API_KEY="' + $OPENAI_API_KEY + '"'
$lines += 'ANTHROPIC_API_KEY="' + $ANTHROPIC_API_KEY + '"'
$lines += 'REDIS_HOST="' + $REDIS_HOST + '"'
$lines += 'REDIS_PORT="' + $REDIS_PORT + '"'
$lines += 'REDIS_PASSWORD="' + $REDIS_PASSWORD + '"'
$lines += 'APP_PORT="' + $APP_PORT + '"'

$lines -join "`n" | Set-Content -Path $EnvFile -Encoding utf8
Ok ("Wrote " + $EnvFile)

Title "Starting containers"
Info "Running: docker compose up -d"
& docker compose up -d
if ($LASTEXITCODE -ne 0) {
    Err "docker compose up failed."
    exit 1
}
Ok "Containers started."

Title "Health check"
$healthy = $false
for ($i = 0; $i -lt 24; $i++) {
    $status = docker inspect --format '{{.State.Health.Status}}' app 2>$null
    if ($status -eq "healthy") { $healthy = $true; break }
    if ($status -eq "unhealthy") { break }
    Start-Sleep -Seconds 5
}
if ($healthy) { Ok "Application is healthy." } else { Warn "Application did not report healthy yet; see docker logs app." }

Title "Next steps"
Info ("Open the app at " + $APP_URL)
Info "Create the first administrator (interactive):"
Say '  docker exec -it app php artisan eduflow:bootstrap-admin --name="School Operator" --email="operator@example.org"' White
Info "Circle/Arc features require the Circle CLI and arc-canteen on your workflow host."
Ok "Installation complete."
