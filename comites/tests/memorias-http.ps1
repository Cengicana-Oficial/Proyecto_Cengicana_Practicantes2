param([Parameter(Mandatory=$true)][PSCredential]$Credential,[string]$BaseUrl='http://localhost:8085')
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Net.Http
$login=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/login/login.php') -Method Post -Body @{correo=$Credential.UserName;password=$Credential.GetNetworkCredential().Password} -SessionVariable session
$endpoint=$BaseUrl+'/comites/index.php?view=memorias&memorias_api=1'
$initial=Invoke-RestMethod -Uri $endpoint -WebSession $session
if(-not $initial.ok){throw 'No se pudo autenticar.'}
$prefix='Prueba HTTP memorias '+[Guid]::NewGuid().ToString('N')
$handler=New-Object System.Net.Http.HttpClientHandler
$handler.CookieContainer=$session.Cookies
$client=New-Object System.Net.Http.HttpClient($handler)
$bytes=[Text.Encoding]::UTF8.GetBytes('%PDF-1.4 Documento temporal de prueba')
function Send-Mem($Action,$Data,$Files=@()){
    $parts=New-Object System.Net.Http.MultipartFormDataContent
    try{
        $parts.Add((New-Object System.Net.Http.StringContent($Action)),'mem_accion')
        $parts.Add((New-Object System.Net.Http.StringContent($initial.datos.csrf)),'csrf')
        $parts.Add((New-Object System.Net.Http.StringContent(($Data|ConvertTo-Json -Depth 10 -Compress),[Text.Encoding]::UTF8)),'datos')
        foreach($key in $Files){$parts.Add((New-Object System.Net.Http.ByteArrayContent(,$bytes)),$key,($key+'.pdf'))}
        $response=$client.PostAsync($endpoint,$parts).GetAwaiter().GetResult()
        $result=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult()|ConvertFrom-Json
        if(-not $result.ok){throw $result.error}
        return $result.datos
    }finally{$parts.Dispose()}
}
try{
    $data=Send-Mem 'revisor_nuevo' @{nombre=$prefix;email=($prefix.Replace('Prueba HTTP memorias ','')+'@example.invalid')}
    $reviewer=$data.revisores|Where-Object {$_.nombre -eq $prefix}
    $data=Send-Mem 'entregar' @{edicion_id=$initial.datos.ediciones[0].id;titulo=$prefix;autor='Autor de prueba';autor_email='autor@example.invalid';area='Pruebas'} @('archivo')
    $article=$data.articulos|Where-Object {$_.titulo -eq $prefix}
    $id=$article.id
    if($article.flujo -ne 'entregado'){throw 'Entrega incorrecta'}
    $data=Send-Mem 'asignar' @{id=$id;revision=$article.revision_control;revisor_id=$reviewer.id;limite_revision=$initial.datos.hoy}
    $data=Send-Mem 'revisar' @{id=$id;recomendacion='Cambios menores';observaciones='Ajustar conclusiones'} @('revision_archivo','criterios')
    $article=$data.articulos|Where-Object {$_.id -eq $id}
    if($article.flujo -ne 'observaciones'){throw 'Revisión incorrecta'}
    $data=Send-Mem 'corregir' @{id=$id;observaciones='Conclusiones corregidas'} @('archivo')
    $article=$data.articulos|Where-Object {$_.id -eq $id}
    if($article.flujo -ne 'listo' -or $article.version -ne 2){throw 'Corrección no incrementó versión'}
    $data=Send-Mem 'diagramar' @{id=$id}
    $article=$data.articulos|Where-Object {$_.id -eq $id}
    if($article.flujo -ne 'diagramacion'){throw 'Descarga sin transición'}
    foreach($key in @('actual','revision','criterios','historial')){
        $download=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/comites/index.php?view=memorias&descarga=1&id='+$id+'&archivo='+$key+'&indice=0&adjunto=0') -WebSession $session
        if($download.RawContentLength -ne $bytes.Length){throw ('Descarga incorrecta: '+$key)}
    }
    Write-Output 'OK: entrega, asignación, dos archivos de revisión, corrección v2, diagramación y cuatro descargas autenticadas'
    try{Invoke-WebRequest -UseBasicParsing -Uri $endpoint -WebSession $session -Method Post -Body @{mem_accion='eliminar';datos=('{"id":'+$id+'}');csrf='incorrecto'}|Out-Null;throw 'Aceptó CSRF inválido'}catch{if(-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 403){throw}}
    Write-Output 'OK: protección CSRF'
}finally{
    & docker exec cengicana-ui-new-app php /var/www/html/comites/tests/memorias-limpiar.php $prefix
    if($LASTEXITCODE -ne 0){throw 'No se pudo limpiar la prueba'}
    $client.Dispose();$handler.Dispose()
}
