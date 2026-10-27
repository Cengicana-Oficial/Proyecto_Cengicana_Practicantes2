# API módulo Laboratorio ↔ SIGEC

Contrato para que el **módulo Laboratorio**:

1. **consulte** en SIGEC los datos de una muestra a partir del ID leído en la etiqueta QR (ensayo, parcela, tratamiento, repetición, fecha de muestreo…) y los guarde en su recepción;
2. **envíe** a SIGEC el avance y los resultados de esas muestras.

En ambos casos el Laboratorio decide **qué** y **cuándo**. SIGEC nunca escribe en la base de datos del Laboratorio por esta vía.

## Resumen

| | Consulta de muestras (QR) | Envío de resultados |
|---|---|---|
| Método y ruta | `POST http://sigec/api/laboratorio/muestras` | `POST http://sigec/api/laboratorio/resultados` |
| Cambia datos en SIGEC | No (solo lectura) | Sí |
| Cuándo | Al escanear la etiqueta `SIGEC-…` en la recepción | Al firmar el informe (y opcionalmente otros momentos) |

| Común a ambas | |
|---|---|
| Red | Solo red interna de Docker (servicio `sigec`). Desde el portal público responde `403`. |
| Formato | `Content-Type: application/json`, UTF-8 |
| Autenticación | Firma HMAC-SHA256 con secreto compartido (`X-Lab-Timestamp` y `X-Lab-Firma`) |
| Ejemplo de código | `sigec/docs/ejemplos/enviar_resultados_laboratorio.php` (PHP + cURL, sin dependencias): `sigec_consultar_muestras()` y `sigec_enviar_resultados()` |

## 1. Consulta de muestras por QR

La etiqueta de SIGEC lleva en el QR solo el ID (por ejemplo `SIGEC-01-SU-003-26`). Al escanearlo en la recepción o en la identificación del lote, el Laboratorio pide a SIGEC los datos y los guarda en sus propias tablas. Así no hay que digitar nada.

Cuerpo (de 1 a 200 IDs; no distingue mayúsculas de minúsculas):

```json
{ "ids": ["SIGEC-01-SU-002-26", "SIGEC-01-AG-001-26"] }
```

Respuesta `200`:

```json
{
  "ok": true,
  "solicitadas": 2,
  "encontradas": 1,
  "muestras": [
    {
      "id_sigec": "SIGEC-01-SU-002-26",
      "tipo": "Suelo",
      "tipo_lab": "suelos",
      "fecha_muestreo": "2026-10-20",
      "nombre_ensayo": "01",
      "ensayo": { "codigo": "01", "nombre": null, "diseno": "Bloques", "cultivo": "Caña", "variedad": "CG02-163" },
      "proyecto": { "codigo": "001", "nombre": "Ensayo de brotacion" },
      "institucion": null,
      "finca": "Ilusiones",
      "lote": "16-186978",
      "parcela": "P-01",
      "tratamiento": { "codigo": "T1", "descripcion": "Estimulante 1" },
      "repeticion": 1,
      "observaciones": null,
      "campos": [ { "clave": "profundidad", "etiqueta": "Profundidad", "valor": "0-20", "unidad": "cm" } ],
      "identificacion": {
        "lectura_codigo": "SIGEC-01-SU-002-26", "parcela": "P-01", "bloque": null,
        "tratamiento": "T1 - Estimulante 1", "repeticion": "1", "variedad": "CG02-163", "observaciones": null
      },
      "campos_lab": {
        "fecha": "2026-10-20", "ensayo": "01", "finca": "Ilusiones", "loteCampo": "16-186978",
        "parcela": "P-01", "tratamiento": "T1 - Estimulante 1", "repeticion": "1", "variedad": "CG02-163", "profundidad": "0-20"
      },
      "estado_sigec": "Recibida"
    }
  ],
  "no_encontradas": ["SIGEC-01-AG-001-26"]
}
```

Cómo usar cada bloque en el Laboratorio:

| Bloque | Destino sugerido en el Laboratorio |
|---|---|
| `tipo_lab` | `tipo_muestra.nombre` (`suelos`, `agua`, `foliares`, `cañas`, `mieles`); `null` si el tipo no tiene equivalente |
| `nombre_ensayo`, `fecha_muestreo`, `institucion` | `solicitud.codigo_muestreo` ("Nombre del ensayo"), `solicitud.fecha_muestreo`, `solicitud.institucion` |
| `identificacion` | Columnas del mismo nombre en `muestra_identificacion` |
| `campos_lab` | Valores por clave de `tipo_muestra_campo` (`fecha`, `finca`, `loteCampo`, `ensayo`, `parcela`…); incluye los campos personalizados de SIGEC con la misma clave (por ejemplo `profundidad`, `pante`) |
| `campos` | Todos los campos personalizados del muestreador con etiqueta y unidad, aunque estén vacíos |

Los IDs que no existen se listan en `no_encontradas` (no es un error). Los códigos HTTP son los mismos de la tabla [Respuestas](#respuestas), excepto `409`: si se repite una consulta con exactamente la misma firma, basta firmarla de nuevo.

## 2. Envío de resultados

### Qué muestras se envían

Solo las muestras cuyo código de etiqueta empieza con `SIGEC-` (por ejemplo `SIGEC-01-SU-003-26`). En el módulo Laboratorio ese código queda en `muestra_identificacion.lectura_codigo` al escanear la etiqueta QR durante la identificación del lote.

Momentos recomendados para enviar:

| Momento en el Laboratorio | `estado` a enviar | Resultados |
|---|---|---|
| Identificación del lote (etiqueta escaneada) | `RECIBIDA` | No |
| Inicio del análisis | `EN_ANALISIS` | No |
| Informe devuelto al técnico | `DEVUELTO` | No |
| **Informe aprobado y firmado** | `FIRMADO` | **Sí, obligatorios** |
| Informe anulado | `ANULADO` | No (SIGEC retira los resultados) |

El único envío indispensable es `FIRMADO`. Los demás son opcionales y solo muestran el avance en SIGEC.

## Seguridad

1. **Secreto compartido.** El administrador de SIGEC lo genera y lo entrega por un canal seguro (no por correo ni chat). En el Laboratorio se guarda en su `.env` (por ejemplo `SIGEC_API_SECRET`) y **nunca** en el código ni en git.
2. **Firma de cada envío.**
   - `X-Lab-Timestamp`: hora actual en segundos Unix (por ejemplo `1761170000`).
   - `X-Lab-Firma`: `sha256=` seguido del HMAC-SHA256 en hexadecimal de: `timestamp + "\n" + cuerpo`.
   - El cuerpo firmado debe ser **exactamente** el que se envía: mismos bytes, sin volver a serializar el JSON después de firmar.
3. **Vigencia.** SIGEC rechaza envíos con más de 5 minutos de diferencia de reloj (los servidores deben tener la hora sincronizada).
4. **Anti-repetición.** Un mismo envío firmado (misma firma) solo se procesa una vez; una repetición responde `409`.
5. **Solo red interna.** Las llamadas que pasan por el portal público se rechazan con `403`.
6. **Bitácora.** SIGEC registra cada envío y cada consulta (estado `CONSULTA`), aceptados o rechazados, en su tabla `lab_envios`.

PHP:

```php
$cuerpo = json_encode($envio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ts = (string) time();
$firma = 'sha256=' . hash_hmac('sha256', $ts . "\n" . $cuerpo, $secreto);
// Cabeceras: X-Lab-Timestamp: $ts   X-Lab-Firma: $firma
```

### Cuerpo del envío

```json
{
  "informe": {
    "codigo": "INF-2026-0007",
    "version": 1,
    "estado": "FIRMADO",
    "firmado_por": "Jefe de laboratorio",
    "fecha_firma": "2026-10-14T22:52:49-06:00"
  },
  "muestras": [
    {
      "id_sigec": "SIGEC-01-JU-001-26",
      "codigo_lab": "C-005-10-26",
      "id_muestra_lab": 220,
      "resultados": [
        { "clave": "Brix|brix", "nombre": "Brix jugo", "unidad": "%", "valor": "19.89" },
        { "clave": "Pol|pol",   "nombre": "Pol jugo",  "unidad": "%", "valor": "17.95" }
      ]
    }
  ]
}
```

#### `informe`

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `codigo` | texto (≤60) | Sí | Código del informe del lote (`lab_informe.codigo`) |
| `version` | entero ≥1 | Sí | Versión del informe; se incrementa al corregir y volver a firmar |
| `estado` | `RECIBIDA` · `EN_ANALISIS` · `DEVUELTO` · `FIRMADO` · `ANULADO` | Sí | Ver tabla de momentos |
| `firmado_por` | texto (≤150) | No | Nombre de quien firma |
| `fecha_firma` | fecha ISO 8601 | Sí si `FIRMADO` | Fecha y hora de la firma |

#### `muestras[]` (1 a 1000 por envío)

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `id_sigec` | texto | Sí | ID de la etiqueta de SIGEC (no distingue mayúsculas de minúsculas) |
| `codigo_lab` | texto (≤60) | No | Código interno del Laboratorio (por ejemplo `C-005-10-26`) |
| `id_muestra_lab` | entero | No | `muestra.id_muestra` del Laboratorio, para trazabilidad |
| `resultados` | lista (≤200) | Sí si `FIRMADO` | Valores del informe firmado |

#### `resultados[]`

| Campo | Tipo | Obligatorio | Descripción |
|---|---|---|---|
| `clave` | texto | Sí | Identificador estable del resultado; se recomienda `análisis|campo`, como en `lab_informe.resultados_json` |
| `nombre` | texto | Sí | Nombre como aparece en el informe (por ejemplo "Brix jugo") |
| `unidad` | texto | No | Unidad del informe (por ejemplo `%`, `mg/L`, `dS/m`) |
| `valor` | texto o número | Sí | Valor mostrado en el informe; los vacíos se ignoran |

## Respuestas

| HTTP | Significado | ¿Reintentar? |
|---|---|---|
| `200` | Recibido. Ver `aplicadas` y `rechazadas` | No |
| `401` | Falta la firma, la firma no es válida o el envío está vencido | No: revise el secreto y el reloj |
| `403` | Llamada desde fuera de la red interna o desde el portal | No |
| `409` | Este envío (misma firma) ya fue recibido | No: trátelo como recibido |
| `413` | Cuerpo mayor a 2 MB | Divida el envío |
| `422` | Formato no válido; `detalle` indica los campos | No: corrija el envío |
| `429` | Demasiadas solicitudes | Sí, después de unos segundos |
| `5xx` o sin respuesta | Error temporal de SIGEC | Sí, con espera creciente (1, 5, 15 min…) |

Ejemplo `200`:

```json
{
  "ok": true,
  "informe": "INF-2026-0007",
  "version": 1,
  "recibidas": 3,
  "aplicadas": 2,
  "rechazadas": [
    { "id_sigec": "SIGEC-01-JU-009-26", "motivo": "El ID no existe en SIGEC." }
  ]
}
```

Una muestra rechazada no impide que se apliquen las demás del mismo envío. Motivos posibles:

- `El ID no existe en SIGEC.`
- `Version N anterior a la ya registrada (M).`: llegó una versión vieja después de una corrección; SIGEC conserva la más reciente.
- `Informe firmado sin resultados para esta muestra.`

## Reglas que aplica SIGEC

- **La versión más reciente manda.** Para un mismo `codigo` de informe, una versión menor que la ya registrada se rechaza. Volver a enviar la misma versión actualiza los datos.
- **Resultados solo con `FIRMADO`.** Con otros estados SIGEC actualiza el avance y retira resultados previos (por ejemplo al `ANULADO`).
- **Estado en SIGEC:** `FIRMADO` → muestra *Completado*; los demás → *En proceso*.
- **Prioridad del envío.** Lo recibido por esta API tiene prioridad sobre cualquier otra forma de vínculo en SIGEC.

## Prueba rápida

Desde el contenedor del Laboratorio:

```bash
# Consulta de datos por QR
LAB_API_SECRET='<secreto>' php enviar_resultados_laboratorio.php consultar SIGEC-01-SU-002-26
# Envío de resultados (JSON de ejemplo)
LAB_API_SECRET='<secreto>' php enviar_resultados_laboratorio.php enviar envio.json
```

`SIGEC_API_BASE` cambia la dirección base (por defecto `http://sigec/api/laboratorio`).

Casos que se verificaron:
- consulta válida con IDs existentes e inexistentes (`200`, `no_encontradas`);
- envío válido (`200`);
- corrección con versión mayor;
- versión anterior rechazada;
- ID inexistente rechazado;
- firma con secreto equivocado (`401`);
- cuerpo alterado (`401`);
- envío vencido (`401`);
- repetición (`409`);
- llamada desde el portal público (`403`).

## Lista de implementación para el Laboratorio

1. Guardar el secreto en el `.env` del Laboratorio.
2. **Recepción / identificación del lote:** al escanear una etiqueta que empieza con `SIGEC-`, llamar a `sigec_consultar_muestras()` y prellenar con la respuesta el tipo de muestra, "Nombre del ensayo", la fecha de muestreo y los campos de identificación (`identificacion` y `campos_lab`). El técnico los revisa y guarda en el Laboratorio como siempre. Si SIGEC no responde, se digitan a mano.
3. **Al firmar un informe:** reunir del lote las muestras cuyo `lectura_codigo` empieza con `SIGEC-` y enviar `FIRMADO` con sus resultados y la `version` del informe (`sigec_enviar_resultados()`).
4. Opcional: enviar `RECIBIDA` al identificar el lote y `DEVUELTO` o `ANULADO` cuando corresponda.
5. Registrar la respuesta. Reintentar solo `429` o `5xx`/tiempo agotado, con espera creciente. Si el envío falla del todo, permitir reenviarlo con un botón ("Enviar a SIGEC").
6. Si un informe se corrige y se vuelve a firmar, enviar con `version` + 1.
