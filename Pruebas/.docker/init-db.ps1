$ErrorActionPreference = 'Stop'
$privatePath = Join-Path $PSScriptRoot 'private'
$configPath = Join-Path $privatePath 'database.json'
$mysqlContainer = 'cengicana-ui-new-mysql'

function Invoke-LocalSql([string]$Sql) {
    $result = $Sql | & docker exec -i $mysqlContainer sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names'
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la base local de Servicios Tecnicos.' }
    return $result
}

if (Test-Path -LiteralPath $configPath) {
    $dbConfig = Get-Content -LiteralPath $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($dbConfig.user -ne 'servicios_tecnicos_app' -or $dbConfig.password -notmatch '^[a-f0-9]{64}$') {
        throw 'La configuracion existente necesita revision; no se reemplazaron sus credenciales.'
    }
} else {
    $exists = Invoke-LocalSql "SELECT COUNT(*) FROM mysql.user WHERE User='servicios_tecnicos_app';"
    if ([int]$exists -ne 0) { throw 'El usuario de la base ya existe. Se necesita su configuracion antes de continuar.' }
    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $dbConfig = [pscustomobject]@{
        dsn = 'mysql:host=mysql;port=3306;dbname=servicios_tecnicos;charset=utf8mb4'
        user = 'servicios_tecnicos_app'
        password = ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
    }
    New-Item -ItemType Directory -Path $privatePath -Force | Out-Null
    [IO.File]::WriteAllText($configPath, ($dbConfig | ConvertTo-Json), (New-Object System.Text.UTF8Encoding($false)))
}

$schema = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'schema.sql') -Raw -Encoding UTF8
$grants = @"
CREATE USER IF NOT EXISTS 'servicios_tecnicos_app'@'%' IDENTIFIED BY '$($dbConfig.password)';
GRANT SELECT, INSERT, UPDATE, DELETE ON servicios_tecnicos.* TO 'servicios_tecnicos_app'@'%';
"@
Invoke-LocalSql ($schema + "`n" + $grants) | Out-Null
Write-Output 'Base local servicios_tecnicos preparada. Tablas: Encuestas y Respuestas.'
Write-Output 'Las credenciales se conservaron en .docker/private/database.json.'
