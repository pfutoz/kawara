$ErrorActionPreference = "Stop"

$prodRoot = "\\192.168.1.16\c$\Apache24\htdocs\kawara"
$localRoot = "C:\Apache24\htdocs\kawara"

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
Write-Host "=== Deploying to Production (192.168.1.16) [$timestamp] ==="

$filesToDeploy = @(
    "doctor_schedule.php",
    "includes\doctor_schedule_helper.php",
    "includes\auth_helper.php",
    "includes\line_helper.php",
    "login.php",
    "master_mente.php",
    "api\device_auth.php",
    "line_link.php",
    "line_guide.php",
    "index.php",
    "kawara_list.php",
    "jimucho_dashboard.php",
    "safety_contacts.php",
    "assets\richmenu\richmenu_small_3split.png",
    "assets\richmenu\richmenu_small_3split_1200x405.png",
    "assets\richmenu\richmenu_kawara_yotei.png",
    "assets\richmenu\richmenu_small_1btn_2500x843.png",
    "assets\richmenu\richmenu_small_1btn_1200x405.png",
    "開発仕様\index.html",
    "開発仕様\管理者運用マニュアル_クラウドインフラ・認証基盤.html",
    "開発仕様\LINE登録手順書.html",
    "開発仕様\API仕様書.html"
)

foreach ($relPath in $filesToDeploy) {
    $src = Join-Path $localRoot $relPath
    $dest = Join-Path $prodRoot $relPath
    $destDir = Split-Path $dest -Parent

    if (!(Test-Path $src)) {
        Write-Warning "Source not found, skipping: $src"
        continue
    }

    if (!(Test-Path $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }

    if (Test-Path $dest) {
        $bak = "$dest.bak_$timestamp"
        Copy-Item -Path $dest -Destination $bak -Force
        Write-Host "  [Backup] $relPath.bak_$timestamp"
    }

    Copy-Item -Path $src -Destination $dest -Force
    Write-Host "  [Copied] $relPath"
}

Write-Host "=== Deployment Completed Successfully ==="
