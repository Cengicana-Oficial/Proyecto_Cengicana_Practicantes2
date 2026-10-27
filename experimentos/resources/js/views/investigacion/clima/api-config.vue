<template>
  <div class="ac">
    <div v-if="cargando" class="ac-vacio"><i class="fas fa-spinner fa-spin"></i> Cargando configuracion...</div>
    <form v-else class="ac-form" @submit.prevent="guardar" novalidate>
      <div class="ac-paso">
        <span class="ac-num">1</span>
        <div>
          <h6>Servidor</h6>
          <p>La direccion base que el ICC le dio para su API (sin la ruta). El metodo <code>datosdiarios</code> se agrega abajo.</p>
          <div class="form-row">
            <div class="col-md-7 form-group">
              <label>URL del servidor *</label>
              <input class="form-control" v-model.trim="f.url" placeholder="https://servidor.icc.org.gt" :class="{ 'is-invalid': err.url }">
              <span class="invalid-feedback" v-if="err.url">{{ err.url[0] }}</span>
            </div>
            <div class="col-md-5 form-group">
              <label>Ruta del metodo *</label>
              <input class="form-control ac-mono" v-model.trim="f.ruta_datos" :class="{ 'is-invalid': err.ruta_datos }">
              <span class="invalid-feedback" v-if="err.ruta_datos">{{ err.ruta_datos[0] }}</span>
            </div>
          </div>
          <p class="ac-url" v-if="f.url">Se llamara: <code>{{ urlEjemplo }}</code></p>
        </div>
      </div>

      <div class="ac-paso">
        <span class="ac-num">2</span>
        <div>
          <h6>Autenticacion</h6>
          <p>Como pide la API las credenciales. Pregunte al ICC si usa token (Bearer), una clave en un encabezado o usuario y contraseña.</p>
          <div class="ac-auth">
            <label v-for="a in auths" :key="a.id" :class="{ 'is-sel': f.auth === a.id }">
              <input type="radio" :value="a.id" v-model="f.auth"> <b>{{ a.label }}</b><small>{{ a.ayuda }}</small>
            </label>
          </div>
          <div class="form-row mt-2">
            <template v-if="['bearer', 'header', 'query'].includes(f.auth)">
              <div v-if="f.auth === 'header'" class="col-md-3 form-group">
                <label>Nombre del encabezado</label>
                <input class="form-control ac-mono" v-model.trim="f.header" placeholder="X-API-Key">
              </div>
              <div v-if="f.auth === 'query'" class="col-md-3 form-group">
                <label>Nombre del parametro</label>
                <input class="form-control ac-mono" v-model.trim="f.query_param" placeholder="api_key">
              </div>
              <div class="col-md-6 form-group">
                <label>Token / clave de API</label>
                <input type="password" class="form-control ac-mono" v-model="f.token" autocomplete="new-password"
                       :placeholder="actual.tiene_token ? '•••••••• (guardado; deje vacio para conservarlo)' : 'Pegue aqui el token'">
                <label v-if="actual.tiene_token" class="ac-check"><input type="checkbox" v-model="f.borrar_token"> Borrar el token guardado</label>
              </div>
            </template>
            <template v-else-if="f.auth === 'basic'">
              <div class="col-md-4 form-group">
                <label>Usuario</label>
                <input class="form-control" v-model.trim="f.usuario" autocomplete="off">
              </div>
              <div class="col-md-5 form-group">
                <label>Contraseña</label>
                <input type="password" class="form-control" v-model="f.clave" autocomplete="new-password"
                       :placeholder="actual.tiene_clave ? '•••••••• (guardada; deje vacio para conservarla)' : ''">
                <label v-if="actual.tiene_clave" class="ac-check"><input type="checkbox" v-model="f.borrar_clave"> Borrar la contraseña guardada</label>
              </div>
            </template>
          </div>
        </div>
      </div>

      <div class="ac-paso">
        <span class="ac-num">3</span>
        <div>
          <h6>Opciones</h6>
          <div class="form-row">
            <div class="col-md-3 form-group">
              <label>Dias por solicitud</label>
              <input type="number" min="1" max="366" class="form-control" v-model.number="f.dias_por_consulta">
              <small class="ac-muted">Rangos mayores se piden por partes.</small>
            </div>
            <div class="col-md-3 form-group">
              <label>Unidad de "vientoavg"</label>
              <select class="form-control" v-model="f.viento_unidad"><option value="kmh">km/h (verificado)</option><option value="ms">m/s</option></select>
            </div>
            <div class="col-md-6 form-group d-flex align-items-end">
              <label class="ac-check mb-2">
                <input type="checkbox" v-model="f.verificar_ssl"> Verificar certificado SSL
                <small class="ac-muted d-block">Desmarque solo si la prueba falla por el certificado (inspeccion del firewall de Cengicaña).</small>
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="ac-paso">
        <span class="ac-num">4</span>
        <div>
          <h6>Probar y guardar</h6>
          <p>La prueba pide a la API los datos de un dia con lo escrito arriba, sin guardar nada.</p>
          <div class="ac-acciones">
            <input type="date" class="form-control ac-fecha" v-model="fechaPrueba" :max="hoy" title="Dia a pedir en la prueba">
            <button type="button" class="btn btn-secondary" :disabled="probando || guardando" @click="probar">
              <i :class="probando ? 'fas fa-spinner fa-spin' : 'fas fa-vial'"></i> Probar conexion
            </button>
            <button type="submit" class="btn btn-success" :disabled="probando || guardando">
              <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i> Guardar conexion
            </button>
          </div>

          <div v-if="prueba" class="ac-prueba" :class="prueba.ok ? (prueba.reconocidos ? 'is-ok' : 'is-warn') : 'is-error'">
            <template v-if="!prueba.ok"><i class="fas fa-times-circle"></i> <b>No se pudo conectar.</b> {{ prueba.message }}</template>
            <template v-else>
              <div>
                <i :class="prueba.reconocidos ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'"></i>
                <b>Conexion correcta</b> ({{ prueba.ms }} ms) · {{ prueba.recibidos }} registro(s) recibido(s), {{ prueba.reconocidos }} reconocido(s),
                {{ prueba.estaciones.length }} estacion(es).
              </div>
              <div class="ac-mono ac-peq">{{ prueba.url }}</div>
              <div v-if="!prueba.recibidos" class="mt-1">La API respondio sin datos para ese dia; pruebe con otra fecha.</div>
              <div v-else-if="!prueba.reconocidos" class="mt-1">Llegaron datos pero con otros campos: <code>{{ prueba.campos.join(', ') }}</code>. Comparta este mensaje para ajustar la lectura.</div>
              <template v-else>
                <div class="mt-1"><b>Estaciones:</b> {{ prueba.estaciones.map((e) => `${e.codigo || e.id_api} (${e.nombre || 'id ' + e.id_api})`).join(', ') }}</div>
                <div class="mt-1"><b>Ejemplo {{ prueba.muestra.estacion.codigo }} · {{ fmt(prueba.muestra.fecha) }}:</b>
                  lluvia {{ v(prueba.muestra.lluvia) }} mm · T {{ v(prueba.muestra.tmin) }}–{{ v(prueba.muestra.tmax) }} °C ·
                  HR {{ v(prueba.muestra.hr_min, 0) }}–{{ v(prueba.muestra.hr_max, 0) }} % · viento {{ v(prueba.muestra.viento) }} km/h ·
                  radiacion {{ v(prueba.muestra.radiacion) }} MJ/m² · ETo {{ v(prueba.muestra.eto) }} mm
                </div>
              </template>
            </template>
          </div>
        </div>
      </div>
    </form>
  </div>
</template>

<script>
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export default {
  name: 'ClimaApiConfig',
  data() {
    return {
      cargando: true,
      actual: {},
      f: {},
      err: {},
      probando: false,
      guardando: false,
      prueba: null,
      hoy: iso(new Date()),
      fechaPrueba: iso(new Date(Date.now() - 86400000)),
      auths: [
        { id: 'bearer', label: 'Token Bearer', ayuda: 'Authorization: Bearer <token>' },
        { id: 'header', label: 'Clave en encabezado', ayuda: 'p. ej. X-API-Key: <clave>' },
        { id: 'basic', label: 'Usuario y contraseña', ayuda: 'HTTP Basic' },
        { id: 'query', label: 'Clave en la URL', ayuda: '?api_key=<clave>' },
        { id: 'none', label: 'Sin autenticacion', ayuda: 'API abierta' },
      ],
    };
  },
  computed: {
    urlEjemplo() {
      const r = (this.f.ruta_datos || '').replace('{desde}', this.fechaPrueba).replace('{hasta}', this.fechaPrueba);
      return `${(this.f.url || '').replace(/\/+$/, '')}/${r.replace(/^\/+/, '')}`;
    },
  },
  mounted() {
    window.axios.get('/investigacion/clima/icc/config')
      .then(({ data }) => {
        this.actual = data;
        this.f = {
          url: data.url || '', auth: data.auth || 'bearer', token: '', header: data.header || 'X-API-Key', query_param: data.query_param || 'api_key',
          usuario: data.usuario || '', clave: '', ruta_datos: data.ruta_datos || '/api/agromet/datosdiarios?fecha_inicial={desde}&fecha_final={hasta}',
          dias_por_consulta: Number(data.dias_por_consulta) || 31, verificar_ssl: data.verificar_ssl !== false && data.verificar_ssl !== '0',
          viento_unidad: data.viento_unidad || 'kmh', borrar_token: false, borrar_clave: false,
        };
      })
      .finally(() => { this.cargando = false; });
  },
  methods: {
    payload() { return { ...this.f }; },
    probar() {
      this.probando = true;
      this.prueba = null;
      this.err = {};
      window.axios.post('/investigacion/clima/icc/probar', { ...this.payload(), fecha: this.fechaPrueba })
        .then(({ data }) => { this.prueba = data; })
        .catch(this.errores)
        .finally(() => { this.probando = false; });
    },
    guardar() {
      this.guardando = true;
      this.err = {};
      window.axios.put('/investigacion/clima/icc/config', this.payload())
        .then(({ data }) => {
          this.actual = data;
          this.f.token = '';
          this.f.clave = '';
          this.f.borrar_token = false;
          this.f.borrar_clave = false;
          this.$swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Conexion guardada', text: 'SIGEC usara estos datos para la API del ICC', timer: 2600, showConfirmButton: false });
          this.$emit('guardado');
        })
        .catch(this.errores)
        .finally(() => { this.guardando = false; });
    },
    errores(e) {
      const r = e.response;
      if (r && r.status === 422 && r.data.errors) {
        this.err = r.data.errors;
        this.prueba = { ok: false, message: Object.values(r.data.errors).flat().join(' ') };
      } else {
        this.prueba = { ok: false, message: (r && r.data && r.data.message) || 'Error inesperado.' };
      }
    },
    v(x, d = 1) { return x === null || x === undefined ? '—' : Number(x).toFixed(d); },
    fmt(s) { const [y, m, d] = String(s).split('-'); return `${d}/${m}/${y}`; },
  },
};
</script>

<style scoped>
.ac-vacio { padding: 30px; color: #6b7568; text-align: center; }
.ac-form { display: flex; flex-direction: column; gap: 12px; max-width: 980px; }
.ac-paso { display: flex; gap: 14px; padding: 14px 16px; border: 1px solid #e3e7e1; border-radius: 12px; background: #fff; }
.ac-paso > div { flex: 1; min-width: 0; }
.ac-num { flex: 0 0 28px; height: 28px; border-radius: 50%; background: #73BC25; color: #fff; font-weight: 700; line-height: 28px; text-align: center; }
.ac-paso h6 { margin: 3px 0 4px; color: #1f5632; font-size: 14px; font-weight: 700; }
.ac-paso p { margin-bottom: 8px; color: #6b7568; font-size: 12.5px; }
.ac-paso label { margin-bottom: 3px; font-size: 12px; font-weight: 600; }
.ac-mono { font-family: SFMono-Regular, Consolas, monospace; font-size: 12.5px; }
.ac-url { margin: -4px 0 0; font-size: 12px; word-break: break-all; }
.ac-auth { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; }
.ac-auth label { display: flex; flex-direction: column; margin: 0; padding: 8px 10px; border: 1px solid #e3e7e1; border-radius: 8px; cursor: pointer; font-weight: 400; }
.ac-auth label.is-sel { border-color: #73BC25; background: #f1f8e9; }
.ac-auth input { display: none; }
.ac-auth b { color: #26331f; font-size: 12.5px; }
.ac-auth small { color: #6b7568; font-size: 11px; }
.ac-check { display: block; margin-top: 4px; font-weight: 400 !important; font-size: 12px; }
.ac-muted { color: #6b7568; font-size: 11px; }
.ac-acciones { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ac-fecha { width: 170px; }
.ac-prueba { margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 12.5px; line-height: 1.5; }
.ac-prueba.is-ok { background: #eef8df; color: #2f5a14; }
.ac-prueba.is-warn { background: #fff8ec; color: #7a4a00; }
.ac-prueba.is-error { background: #fff1e6; color: #a03f00; }
.ac-prueba i { margin-right: 4px; }
.ac-peq { font-size: 11px; opacity: .8; word-break: break-all; }
</style>
