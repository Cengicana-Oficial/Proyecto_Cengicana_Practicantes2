param([Parameter(Mandatory=$true)][PSCredential]$Credential,[string]$BaseUrl='http://localhost:8085')
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Net.Http
$login=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/login/login.php') -Method Post -Body @{correo=$Credential.UserName;password=$Credential.GetNetworkCredential().Password} -SessionVariable session
$endpoint=$BaseUrl+'/comites/index.php?view=informes&informes_api=1'
$initial=Invoke-RestMethod -Uri $endpoint -WebSession $session
if(-not $initial.ok){throw 'No se pudo autenticar'}
$prefix='Prueba HTTP informes '+[Guid]::NewGuid().ToString('N')
$handler=New-Object System.Net.Http.HttpClientHandler
$handler.CookieContainer=$session.Cookies
$client=New-Object System.Net.Http.HttpClient($handler)
$bytes=[Text.Encoding]::UTF8.GetBytes('%PDF-1.4 Prueba temporal de informes')
function Send-Inf($Action,$Data,$Files=@()){
    $parts=New-Object System.Net.Http.MultipartFormDataContent
    try{
        $parts.Add((New-Object System.Net.Http.StringContent($Action)),'inf_accion')
        $parts.Add((New-Object System.Net.Http.StringContent($initial.datos.csrf)),'csrf')
        $parts.Add((New-Object System.Net.Http.StringContent(($Data|ConvertTo-Json -Depth 12 -Compress),[Text.Encoding]::UTF8)),'datos')
        foreach($key in $Files){$parts.Add((New-Object System.Net.Http.ByteArrayContent(,$bytes)),$key,($key+'.pdf'))}
        $response=$client.PostAsync($endpoint,$parts).GetAwaiter().GetResult()
        $body=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult()|ConvertFrom-Json
        return @{Status=[int]$response.StatusCode;Body=$body}
    }finally{$parts.Dispose()}
}
function Require-Result($r){if(-not $r.Body.ok){throw $r.Body.error};return $r.Body.datos}
try{
    $data=Require-Result (Send-Inf 'areas' @{revision=$initial.datos.revision_areas;nombre=$prefix;areas=@()})
    $area=$data.areas|Where-Object {$_.nombre -eq $prefix}
    $slot=@{anio=2026;cuatri=2;area_id=$area.id;revision=0}
    $fail=Send-Inf 'entregar' $slot @('plan')
    if($fail.Status -ne 422){throw 'Entrega incompleta aceptada'}
    $check=Invoke-RestMethod -Uri $endpoint -WebSession $session
    if(@($check.datos.informes|Where-Object {$_.area_id -eq $area.id}).Count){throw 'Entrega incompleta dejó registros'}
    Write-Output 'OK: entrega incompleta revertida'
    $data=Require-Result (Send-Inf 'entregar' $slot @('plan','informe','soporte_0'))
    $r=$data.informes|Where-Object {$_.area_id -eq $area.id};$slot.revision=$r.revision
    foreach($key in @('plan','informe')){$download=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/comites/index.php?view=informes&descarga=1&id='+$r.id+'&archivo='+$key) -WebSession $session;if($download.RawContentLength -ne $bytes.Length){throw 'Descarga diferente al archivo original'}}
    $data=Require-Result (Send-Inf 'revisar' ($slot+@{estado='observado';nuevos=@('Adjuntar evidencia adicional');observaciones='Revisar soporte'}))
    $r=$data.informes|Where-Object {$_.area_id -eq $area.id};$slot.revision=$r.revision;$answers=@{};$answers[$r.pendientes[0].id]='Se adjunta evidencia adicional'
    $data=Require-Result (Send-Inf 'atender' ($slot+@{respuestas=$answers;observaciones='Pendiente atendido'}) @('soporte_0'))
    $r=$data.informes|Where-Object {$_.area_id -eq $area.id};$slot.revision=$r.revision
    if($r.flujo -ne 'atendido' -or $r.soportes.Count -ne 2){throw 'Respuesta o respaldos incorrectos'}
    $data=Require-Result (Send-Inf 'revisar' ($slot+@{estado='revisado';nuevos=@()}))
    $r=$data.informes|Where-Object {$_.area_id -eq $area.id}
    if($r.flujo -ne 'revisado'){throw 'Revisión no finalizó'}
    foreach($support in $r.soportes){$download=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/comites/index.php?view=informes&descarga=1&id='+$r.id+'&archivo=soporte&adjunto='+$support.id) -WebSession $session;if($download.RawContentLength -ne $bytes.Length){throw 'Soporte incorrecto'}}
    $download=Invoke-WebRequest -UseBasicParsing -Uri ($BaseUrl+'/comites/index.php?view=informes&descarga=1&id='+$r.id+'&archivo=historial&indice=0&adjunto=0') -WebSession $session
    if($download.RawContentLength -ne $bytes.Length){throw 'Historial sin archivo'}
    Write-Output 'OK: carga de plan, informe y soportes; revisión, atención, aprobación y descargas del historial'
    try{Invoke-WebRequest -UseBasicParsing -Uri $endpoint -WebSession $session -Method Post -Body @{inf_accion='guardar';csrf='incorrecto';datos='{}'}|Out-Null;throw 'Aceptó CSRF inválido'}catch{if(-not $_.Exception.Response -or [int]$_.Exception.Response.StatusCode -ne 403){throw}}
    Write-Output 'OK: CSRF rechazado'
}finally{
    & docker exec cengicana-ui-new-app php /var/www/html/comites/tests/informes-limpiar.php $prefix
    if($LASTEXITCODE -ne 0){throw 'No se pudo limpiar la prueba'}
    $client.Dispose();$handler.Dispose()
}
