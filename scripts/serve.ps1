# Local PHP server for M-TELECOM SALES
# Usage: powershell -File scripts/serve.ps1
$root = Split-Path -Parent $PSScriptRoot
$php = $env:PHP_BIN
if (-not $php) {
  if (Test-Path "C:\Temp\php83\php.exe") { $php = "C:\Temp\php83\php.exe" } else { $php = "php" }
}
Write-Host "http://127.0.0.1:8080/"
& $php -S 127.0.0.1:8080 -t "$root\backend\public" "$root\backend\public\router.php"
