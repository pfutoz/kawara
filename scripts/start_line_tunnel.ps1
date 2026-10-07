# 院内かわら版 - Cloudflare Tunnel 起動 ＆ LINE Webhook 自動同期スクリプト
$ErrorActionPreference = "Stop"

$binPath = "C:\Apache24\htdocs\kawara\bin\cloudflared.exe"
$logPath = "C:\Apache24\htdocs\kawara\bin\tunnel.log"

if (!(Test-Path $binPath)) {
    Write-Error "cloudflared.exe not found at $binPath"
    exit 1
}

# 既存のトンネルプロセスの確認（ポート 20241）
$isPortOpen = $false
try {
    $tcp = New-Object System.Net.Sockets.TcpClient
    $iar = $tcp.BeginConnect("127.0.0.1", 20241, $null, $null)
    $success = $iar.AsyncWaitHandle.WaitOne(200, $false)
    if ($success) {
        $tcp.EndConnect($iar)
        $isPortOpen = $true
    }
    $tcp.Close()
} catch {}

if ($isPortOpen) {
    Write-Host "Cloudflare tunnel is already running on port 20241."
} else {
    Write-Host "Starting Cloudflare tunnel..."
    if (Test-Path $logPath) {
        Remove-Item $logPath -Force -ErrorAction SilentlyContinue
    }
    
    # バックグラウンドで起動
    Start-Process -FilePath $binPath -ArgumentList "tunnel --url http://localhost:80 --metrics 127.0.0.1:20241 --logfile `"$logPath`"" -WindowStyle Hidden
    
    # URL生成を最大12秒待機
    $url = $null
    for ($i = 0; $i -lt 24; $i++) {
        Start-Sleep -Milliseconds 500
        if (Test-Path $logPath) {
            $content = Get-Content $logPath -Raw -ErrorAction SilentlyContinue
            if ($content -match "https://[a-zA-Z0-9\-]+\.trycloudflare\.com") {
                $url = $matches[0]
                break
            }
        }
    }

    if (!$url) {
        Write-Error "Failed to obtain trycloudflare.com URL within timeout."
        exit 1
    }

    Write-Host "Tunnel URL obtained: $url"
}

# PHPスクリプトを実行してLINE Webhookを最新URLに自動更新
php "C:\Apache24\htdocs\kawara\scripts\sync_line_webhook.php"
