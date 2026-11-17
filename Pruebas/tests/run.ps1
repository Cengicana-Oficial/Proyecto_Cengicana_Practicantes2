param([int]$Port = 8766)
$ErrorActionPreference = 'Stop'
$workspace = Split-Path -Parent $PSScriptRoot
$testDirectory = Join-Path $PSScriptRoot ('.runtime\' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testDirectory | Out-Null
$previousDsn = $env:TECH_DB_DSN
$previousStorage = $env:TECH_STORAGE_DIR
$previousUrl = $env:TEST_URL
$previousUploads = $env:TECH_UPLOAD_DIR
$previousModules = $env:TEST_MODULES_DSN
$previousRoot = $env:TEST_ROOT
$previousPhp = $env:TEST_PHP
$phpExecutable = 'C:\xampp\php\php.exe'
$testServer = $null
try {
    $env:TECH_DB_DSN = 'sqlite:' + (Join-Path $testDirectory 'primary.sqlite')
    $env:TECH_STORAGE_DIR = Join-Path $testDirectory 'private'
    $env:TECH_UPLOAD_DIR = Join-Path $testDirectory 'uploads'
    $env:TEST_ROOT = Join-Path $testDirectory 'site'
    $env:TEST_MODULES_DSN = 'sqlite:' + (Join-Path $testDirectory 'modules.sqlite')
    $env:TEST_PHP = $phpExecutable
    $env:TEST_URL = 'http://127.0.0.1:' + $Port + '/Pruebas/'
    New-Item -ItemType Directory -Path (Join-Path $env:TEST_ROOT 'Pruebas'), (Join-Path $env:TEST_ROOT 'login\config'), (Join-Path $testDirectory 'sessions') -Force | Out-Null
    Get-ChildItem -LiteralPath $workspace -File -Filter '*.php' | Copy-Item -Destination (Join-Path $env:TEST_ROOT 'Pruebas')
    Copy-Item -LiteralPath (Join-Path (Split-Path -Parent $workspace) 'login\config\session.php') -Destination (Join-Path $env:TEST_ROOT 'login\config\session.php')
    & $phpExecutable (Join-Path $PSScriptRoot 'modules-fixture.php')
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la sesión de Módulos de prueba.' }
    & $phpExecutable (Join-Path $PSScriptRoot 'fixture.php')
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la base de prueba.' }
    $serverArguments = @('-d', ('session.save_path="' + (Join-Path $testDirectory 'sessions') + '"'), '-d', ('upload_tmp_dir="' + $testDirectory + '"'), '-S', ('127.0.0.1:' + $Port), '-t', ('"' + $env:TEST_ROOT + '"'))
    $testServer = Start-Process -FilePath $phpExecutable -ArgumentList $serverArguments -WorkingDirectory $workspace -WindowStyle Hidden -RedirectStandardOutput (Join-Path $testDirectory 'server.log') -RedirectStandardError (Join-Path $testDirectory 'errors.log') -PassThru
    Start-Sleep -Milliseconds 600
    if ($testServer.HasExited) { throw 'El servidor de prueba no pudo iniciarse. Comprueba el puerto.' }
    node (Join-Path $PSScriptRoot 'integration.mjs')
    if ($LASTEXITCODE -ne 0) { throw 'Fallaron las pruebas.' }
    Write-Output ('Registros de prueba: ' + $testDirectory)
} finally {
    if ($testServer -and -not $testServer.HasExited) { Stop-Process -Id $testServer.Id }
    $env:TECH_DB_DSN = $previousDsn
    $env:TECH_STORAGE_DIR = $previousStorage
    $env:TEST_URL = $previousUrl
    $env:TECH_UPLOAD_DIR = $previousUploads
    $env:TEST_MODULES_DSN = $previousModules
    $env:TEST_ROOT = $previousRoot
    $env:TEST_PHP = $previousPhp
}
