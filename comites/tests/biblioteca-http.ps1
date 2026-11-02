param(
    [Parameter(Mandatory=$true)][PSCredential]$Credential,
    [string]$BaseUrl='http://localhost:8085',
    [ValidateSet('documentos','biblioteca_interna')][string]$View='documentos'
)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Net.Http
$login=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/login/login.php') -Method Post -Body @{correo=$Credential.UserName;password=$Credential.GetNetworkCredential().Password} -SessionVariable bibSession
$endpoint=$BaseUrl+'/comites/index.php?view='+$View+'&biblioteca_api=1'
$initial=Invoke-RestMethod -Uri $endpoint -WebSession $bibSession
if(-not $initial.ok){throw 'No se pudo autenticar en la biblioteca.'}
$folder=$initial.datos.carpetas | Select-Object -First 1
if(-not $folder){throw 'Se requiere una carpeta existente.'}
$prefix='Prueba HTTP biblioteca '+[Guid]::NewGuid().ToString('N')
$handler=New-Object System.Net.Http.HttpClientHandler
$handler.CookieContainer=$bibSession.Cookies
$client=New-Object System.Net.Http.HttpClient($handler)
$testBytes=[Text.Encoding]::UTF8.GetBytes('Documento temporal para validar carga y descarga.')
function Send-BibMultipart($Rows,$FileCount){
    $parts=New-Object System.Net.Http.MultipartFormDataContent
    try{
        $parts.Add((New-Object System.Net.Http.StringContent('lote')),'bib_accion')
        $parts.Add((New-Object System.Net.Http.StringContent($initial.datos.csrf)),'csrf')
        $payload=@{carpeta_id=[int]$folder.id;fecha='2026-09-01';requiere_aprobacion=$true;filas=$Rows} | ConvertTo-Json -Depth 8 -Compress
        $parts.Add((New-Object System.Net.Http.StringContent($payload,[Text.Encoding]::UTF8)),'datos')
        for($i=0;$i -lt $FileCount;$i++){
            $file=New-Object System.Net.Http.ByteArrayContent(,$testBytes)
            $parts.Add($file,('archivo_'+$i),('biblioteca_prueba_'+$i+'.txt'))
        }
        $response=$client.PostAsync($endpoint,$parts).GetAwaiter().GetResult()
        $body=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult() | ConvertFrom-Json
        return @{Status=[int]$response.StatusCode;Body=$body}
    }finally{$parts.Dispose()}
}
$rows=@(@{titulo=$prefix+' A';tipo='Otro'},@{titulo=$prefix+' B';tipo='Otro'})
try{
    $failed=Send-BibMultipart $rows 1
    if($failed.Status -ne 422){throw 'Un lote incompleto fue aceptado.'}
    $check=Invoke-RestMethod -Uri $endpoint -WebSession $bibSession
    if(@($check.datos.documentos | Where-Object {$_.titulo.StartsWith($prefix)}).Count -ne 0){throw 'El lote fallido dejó documentos parciales.'}
    Write-Output 'OK: lote incompleto rechazado sin documentos parciales'
    $success=Send-BibMultipart $rows 2
    if(-not $success.Body.ok){throw $success.Body.error}
    $created=@($success.Body.datos.documentos | Where-Object {$_.titulo.StartsWith($prefix)})
    if($created.Count -ne 2){throw 'La carga múltiple no creó los dos documentos.'}
    foreach($doc in $created){
        $expected=if($View -eq 'biblioteca_interna'){'informativo'}else{'borrador'}
        if($doc.flujo -ne $expected){throw 'Estado incorrecto después de la carga múltiple.'}
        $download=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/comites/index.php?view='+$View+'&descarga='+$doc.id) -WebSession $bibSession
        if($download.RawContentLength -ne $testBytes.Length){throw 'La descarga no coincide con el archivo cargado.'}
    }
    Write-Output 'OK: dos archivos guardados y descargados con autenticación'
    Write-Output ('OK: documentos de lote respetan el flujo de '+$View)
}finally{
    # Solo se eliminan los registros y archivos creados con el identificador único de esta ejecución.
    $current=Invoke-RestMethod -Uri $endpoint -WebSession $bibSession
    $workspace=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
    $allowed=[IO.Path]::GetFullPath((Join-Path $workspace 'uploads/comites/documentos'))+[IO.Path]::DirectorySeparatorChar
    foreach($doc in @($current.datos.documentos | Where-Object {$_.titulo.StartsWith($prefix)})){
        $result=Invoke-RestMethod -Uri $endpoint -WebSession $bibSession -Method Post -Body @{bib_accion='eliminar';csrf=$current.datos.csrf;datos=(@{id=$doc.id;revision=$doc.revision}|ConvertTo-Json -Compress)}
        if(-not $result.ok){throw 'No se pudo limpiar un documento de prueba.'}
        if($doc.archivo_ruta){
            $path=[IO.Path]::GetFullPath((Join-Path (Join-Path $workspace 'comites') $doc.archivo_ruta))
            if(-not $path.StartsWith($allowed,[StringComparison]::OrdinalIgnoreCase)){throw 'Ruta de limpieza fuera del directorio de pruebas esperado.'}
            if(Test-Path -LiteralPath $path){Remove-Item -LiteralPath $path}
        }
    }
    $client.Dispose();$handler.Dispose()
    Write-Output 'Datos y archivos de prueba eliminados; no se enviaron correos.'
}
