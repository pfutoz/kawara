param(
    [Parameter(Mandatory=$true)]
    [string]$TargetUrl,

    [Parameter(Mandatory=$true)]
    [string]$PrimaryPrinter,

    [Parameter(Mandatory=$false)]
    [string]$FallbackPrinter = "",

    [Parameter(Mandatory=$false)]
    [string]$DeptName = "",

    [Parameter(Mandatory=$false)]
    [string]$PaperOrientation = "portrait",

    [Parameter(Mandatory=$false)]
    [string]$TempDir = "C:\Apache24\htdocs\kawara\uploads\temp_print"
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$result = @{
    status        = "failed"
    dept_name     = $DeptName
    target_url    = $TargetUrl
    printer_used  = ""
    is_fallback   = $false
    message       = ""
    timestamp     = (Get-Date -Format "yyyy-MM-dd HH:mm:ss")
}

$logDir = "C:\Apache24\htdocs\kawara\logs"
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force | Out-Null }
if (-not (Test-Path $TempDir)) { New-Item -ItemType Directory -Path $TempDir -Force | Out-Null }

function Write-PrintLog([string]$msg) {
    $line = "[" + (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + "] " + $msg
    Add-Content -Path (Join-Path $logDir "print_dispatch.log") -Value $line -Encoding UTF8
}

Write-PrintLog ("Start print dispatch: Dept=[" + $DeptName + "] Primary=[" + $PrimaryPrinter + "] Fallback=[" + $FallbackPrinter + "]")

try {
    # 1. プリンタの状態チェック
    $chosenPrinter = ""
    $primaryObj = Get-Printer -Name $PrimaryPrinter -ErrorAction SilentlyContinue

    if ($null -ne $primaryObj -and $primaryObj.PrinterStatus -ne 128 -and $primaryObj.PrinterStatus -ne "Offline") {
        $chosenPrinter = $PrimaryPrinter
        $result["is_fallback"] = $false
        Write-PrintLog ("Primary printer [" + $PrimaryPrinter + "] Normal (Status=" + $primaryObj.PrinterStatus + ")")
    } else {
        $pStatus = if ($null -ne $primaryObj) { $primaryObj.PrinterStatus } else { "Not found" }
        Write-PrintLog ("Warning: Primary printer [" + $PrimaryPrinter + "] unavailable (Status=" + $pStatus + ")")
        if (![string]::IsNullOrEmpty($FallbackPrinter)) {
            $fallbackObj = Get-Printer -Name $FallbackPrinter -ErrorAction SilentlyContinue
            if ($null -ne $fallbackObj -and $fallbackObj.PrinterStatus -ne 128 -and $fallbackObj.PrinterStatus -ne "Offline") {
                $chosenPrinter = $FallbackPrinter
                $result["is_fallback"] = $true
                Write-PrintLog ("Switching to fallback printer [" + $FallbackPrinter + "]")
            } else {
                Write-PrintLog ("Fallback printer [" + $FallbackPrinter + "] also offline or not found")
            }
        }
    }

    if ([string]::IsNullOrEmpty($chosenPrinter)) {
        $result["status"] = "failed"
        $result["message"] = "指定されたメインプリンタ [" + $PrimaryPrinter + "] および代替プリンタ [" + $FallbackPrinter + "] が共にオフラインまたは利用できません。"
        Write-PrintLog ("Print aborted: " + $result["message"])
        $result | ConvertTo-Json -Compress
        exit 0
    }

    $result["printer_used"] = $chosenPrinter

    # 2. ヘッドレスEdgeによるPDF生成
    $edgePath = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
    if (-not (Test-Path $edgePath)) {
        $edgePath = "C:\Program Files\Microsoft\Edge\Application\msedge.exe"
    }

    $uniqueId = [System.Guid]::NewGuid().ToString()
    $pdfPath = Join-Path $TempDir ("poster_" + $uniqueId + ".pdf")

    $argString = "--headless=new --disable-gpu --no-pdf-header-footer --print-to-pdf=`"$pdfPath`" `"$TargetUrl`""
    Write-PrintLog ("Edge rendering: " + $argString)
    $process = Start-Process -FilePath $edgePath -ArgumentList $argString -Wait -PassThru -WindowStyle Hidden

    for ($i = 0; $i -lt 15; $i++) {
        if (Test-Path $pdfPath) {
            Start-Sleep -Milliseconds 300
            break
        }
        Start-Sleep -Milliseconds 500
    }

    if (-not (Test-Path $pdfPath)) {
        $result["status"] = "failed"
        $result["message"] = "ポスターのPDFレンダリングに失敗しました。"
        Write-PrintLog ("Error: PDF not found: " + $pdfPath)
        $result | ConvertTo-Json -Compress
        exit 0
    }

    # 3. プリンタへの排紙ジョブ送信（3段階フォールバック）
    $printed = $false

    # [エンジン1] SumatraPDF（軽量・高速・完全サイレント・プロセス即終了）
    $sumatraPaths = @(
        (Join-Path $PSScriptRoot "bin\SumatraPDF.exe"),
        "C:\Apache24\htdocs\kawara\scripts\bin\SumatraPDF.exe"
    )
    $sumatraExe = $sumatraPaths | Where-Object { Test-Path $_ } | Select-Object -First 1

    if ($sumatraExe) {
        Write-PrintLog ("Using dedicated CLI print engine (SumatraPDF): " + $sumatraExe)
        $argList = @(
            "-print-to", "`"$chosenPrinter`"",
            "-print-settings", "`"fit`"",
            "-silent",
            "`"$pdfPath`""
        )
        $p = Start-Process -FilePath $sumatraExe -ArgumentList ($argList -join " ") -PassThru -WindowStyle Hidden
        
        # Windowsサービス（Session 0）での-Waitハングを回避し、スプーラ投入完了を3秒待機
        Start-Sleep -Seconds 3
        
        Write-PrintLog ("Print job successfully sent to spooler via SumatraPDF for: " + $chosenPrinter)
        $printed = $true
    }

    # [エンジン2] Adobe Acrobat Reader
    if (-not $printed) {
        $acrobatPaths = @(
            "C:\Program Files\Adobe\Acrobat DC\Acrobat\Acrobat.exe",
            "C:\Program Files\Adobe\Acrobat Reader DC\Reader\AcroRd32.exe",
            "C:\Program Files (x86)\Adobe\Acrobat Reader DC\Reader\AcroRd32.exe"
        )
        $acroExe = $acrobatPaths | Where-Object { Test-Path $_ } | Select-Object -First 1

        if ($acroExe) {
            Write-PrintLog ("Using Acrobat print engine: " + $acroExe)
            $p = Start-Process -FilePath $acroExe -ArgumentList "/t `"$pdfPath`" `"$chosenPrinter`"" -PassThru -WindowStyle Hidden
            Start-Sleep -Seconds 6
            if (-not $p.HasExited) {
                try { $p.Kill() } catch {}
            }
            Write-PrintLog ("Print job dispatched via Acrobat for: " + $chosenPrinter)
            $printed = $true
        }
    }

    # [エンジン3] Windows標準 Print Verb
    if (-not $printed) {
        $origDefault = (Get-CimInstance -ClassName Win32_Printer -Filter "Default=True").Name
        try {
            $net = New-Object -ComObject WScript.Network
            $null = $net.SetDefaultPrinter($chosenPrinter)
            Write-PrintLog ("Temp set default printer: " + $chosenPrinter)

            $pInfo = New-Object System.Diagnostics.ProcessStartInfo
            $pInfo.FileName = $pdfPath
            $pInfo.Verb = "print"
            $pInfo.CreateNoWindow = $true
            $pInfo.WindowStyle = [System.Diagnostics.ProcessWindowStyle]::Hidden
            
            $p = [System.Diagnostics.Process]::Start($pInfo)
            if ($null -ne $p) {
                $null = $p.WaitForExit(10000)
            }
            $printed = $true
        } catch {
            Write-PrintLog ("Print verb error: " + $_.Exception.Message)
        } finally {
            if (![string]::IsNullOrEmpty($origDefault)) {
                $net.SetDefaultPrinter($origDefault)
            }
        }
    }

    if ($printed) {
        if ($result["is_fallback"]) {
            $result["status"] = "fallback_used"
            $result["message"] = "メインプリンタ異常のため、代替プリンタ [" + $chosenPrinter + "] へ自動迂回排紙しました。"
        } else {
            $result["status"] = "success"
            $result["message"] = "[" + $chosenPrinter + "] へ正常に印刷ジョブを送信しました。"
        }
        $result["pdf_path"] = $pdfPath
    } else {
        $result["status"] = "failed"
        $result["message"] = "すべての印刷エンジン（SumatraPDF, Acrobat, Windows Print）で排紙ジョブの送信に失敗しました。"
    }

} catch {
    $result["status"] = "failed"
    $result["message"] = "予期しないエラー: " + $_.Exception.Message
    Write-PrintLog ("Fatal error: " + $_.Exception.Message)
}

Write-PrintLog ("Finish: status=" + $result["status"] + ", printer=" + $result["printer_used"])
$result | ConvertTo-Json -Compress




