<template>
  <!-- Alta / edicion de un programa (usado en "Programas y proyectos"). -->
  <div class="modal fade" ref="modal" tabindex="-1" role="dialog" aria-labelledby="prg-modal-title">
    <div class="modal-dialog modal-dialog-centered" role="document">
      <div class="modal-content prg-modal">
        <form @submit.prevent="guardar" novalidate>
          <div class="modal-header">
            <h5 class="modal-title" id="prg-modal-title">
              <i class="fas fa-layer-group"></i>{{ editando ? 'Editar programa' : 'Nuevo programa' }}
            </h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="prg-preview" :style="estiloColor(form.color)">
              <div class="prg-avatar">{{ form.sigla || '?' }}</div>
              <div>
                <strong>{{ form.nombre.trim() || 'Nombre del programa' }}</strong>
                <small>{{ (form.lider || '').trim() || 'Lider del programa' }}</small>
              </div>
            </div>

            <div class="form-group">
              <label for="prg-nombre">Nombre del programa <span class="prg-req">*</span></label>
              <input
                id="prg-nombre" ref="nombre" type="text" maxlength="150"
                class="form-control" :class="{ 'is-invalid': errors.nombre }"
                v-model="form.nombre" placeholder="Ej. Agronomia" @input="sugerirSigla"
              >
              <span class="invalid-feedback" v-if="errors.nombre">{{ errors.nombre[0] }}</span>
            </div>
            <div class="form-group">
              <label for="prg-sigla">Sigla <span class="prg-req">*</span></label>
              <input
                id="prg-sigla" type="text" maxlength="4"
                class="form-control prg-sigla" :class="{ 'is-invalid': errors.sigla }"
                v-model="form.sigla" placeholder="Ej. AGR" @input="editarSigla"
              >
              <span class="invalid-feedback" v-if="errors.sigla">{{ errors.sigla[0] }}</span>
              <small class="form-text text-muted">
                Encabeza el codigo de proyectos y ensayos: <b>{{ form.sigla || 'SIGLA' }}-{{ anioCorto }}-01</b> · ensayo <b>{{ form.sigla || 'SIGLA' }}-{{ anioCorto }}-01-E01</b>
                <template v-if="editando && editando.sigla && form.sigla !== editando.sigla && editando.proyectos_count">
                  <br><span class="prg-aviso"><i class="fas fa-exclamation-triangle"></i> Al cambiarla se recodifican sus {{ editando.proyectos_count }} proyecto(s), sus ensayos y los ID de sus muestras.</span>
                </template>
              </small>
            </div>
            <div class="form-group">
              <label for="prg-lider">Lider del programa</label>
              <input
                id="prg-lider" type="text" maxlength="150"
                class="form-control" :class="{ 'is-invalid': errors.lider }"
                v-model="form.lider" placeholder="Ej. Ing. Carlos Mejia"
              >
              <span class="invalid-feedback" v-if="errors.lider">{{ errors.lider[0] }}</span>
            </div>
            <div class="form-group">
              <label for="prg-desc">Descripcion</label>
              <textarea
                id="prg-desc" rows="3" maxlength="1000"
                class="form-control" :class="{ 'is-invalid': errors.descripcion }"
                v-model="form.descripcion" placeholder="Breve descripcion del programa"
              ></textarea>
              <small class="prg-counter">{{ (form.descripcion || '').length }}/1000</small>
              <span class="invalid-feedback" v-if="errors.descripcion">{{ errors.descripcion[0] }}</span>
            </div>
            <div class="form-group mb-0">
              <label>Color del programa</label>
              <div class="prg-swatches" role="radiogroup" aria-label="Color del programa">
                <button
                  v-for="c in paleta" :key="c" type="button" role="radio"
                  class="prg-swatch" :class="{ 'is-active': form.color === c }"
                  :style="{ background: c }" :aria-checked="form.color === c ? 'true' : 'false'"
                  :title="tono(c).nombre" @click="form.color = c"
                >
                  <i class="fas fa-check" v-if="form.color === c"></i>
                </button>
              </div>
              <span class="text-danger small" v-if="errors.color">{{ errors.color[0] }}</span>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-success" :disabled="guardando">
              <i :class="guardando ? 'fas fa-spinner fa-spin' : 'fas fa-save'"></i>
              {{ guardando ? 'Guardando...' : (editando ? 'Guardar cambios' : 'Crear programa') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script>
// Tono oscuro legible sobre cada color de marca (para texto/iconos sobre el tinte).
export const TONOS = {
  '#73BC25': { nombre: 'Verde principal', oscuro: '#3f7a12' },
  '#A3D300': { nombre: 'Verde secundario', oscuro: '#5c7700' },
  '#FFCC00': { nombre: 'Amarillo', oscuro: '#8a6a00' },
  '#FF6B00': { nombre: 'Naranja', oscuro: '#b84d00' },
  '#CED2D5': { nombre: 'Gris', oscuro: '#5b6267' },
};

export default {
  name: 'ProgramaModal',
  props: {
    // Programas existentes: para sugerir una sigla libre y avisar nombres repetidos.
    programas: { type: Array, default: () => [] },
    paleta: { type: Array, default: () => Object.keys(TONOS) },
  },
  data() {
    return { editando: null, form: this.formVacio(), errors: {}, guardando: false };
  },
  computed: {
    anioCorto() { return String(new Date().getFullYear()).slice(2); },
  },
  mounted() {
    window.$(this.$refs.modal).on('shown.bs.modal', () => this.$refs.nombre && this.$refs.nombre.focus());
  },
  beforeDestroy() {
    window.$(this.$refs.modal).off('shown.bs.modal');
  },
  methods: {
    normalizar(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); },
    tono(color) { return TONOS[String(color || '').toUpperCase()] || TONOS['#CED2D5']; },
    estiloColor(color) {
      const c = TONOS[String(color || '').toUpperCase()] ? color : '#CED2D5';
      return { '--prg-color': c, '--prg-oscuro': this.tono(c).oscuro };
    },
    formVacio() {
      const n = this.programas.length;
      return { nombre: '', sigla: '', lider: '', descripcion: '', color: this.paleta[n % this.paleta.length], siglaManual: false };
    },
    // Misma regla que App\Services\Codificacion::siglaSugerida: iniciales o 3 primeras letras.
    sugerirSigla() {
      if (this.form.siglaManual) return;
      const vacias = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'para', 'por', 'a', 'al', 'con'];
      const palabras = this.normalizar(this.form.nombre).toUpperCase().split(/[^A-Z0-9]+/)
        .filter((p) => p && !vacias.includes(p.toLowerCase()));
      let base = palabras.length >= 2 ? palabras.slice(0, 4).map((p) => p[0]).join('') : (palabras[0] || '').slice(0, 3);
      if (!base) { this.form.sigla = ''; return; }
      base = base.padEnd(2, 'X').slice(0, 4);
      const usadas = this.programas.filter((i) => !this.editando || i.id !== this.editando.id).map((i) => i.sigla);
      let sigla = base;
      for (let n = 2; usadas.includes(sigla); n += 1) sigla = base.slice(0, 3) + n;
      this.form.sigla = sigla;
    },
    editarSigla() {
      this.form.sigla = this.form.sigla.toUpperCase().replace(/[^A-Z0-9]/g, '');
      this.form.siglaManual = this.form.sigla !== '';
    },
    abrirCrear() {
      this.editando = null;
      this.form = this.formVacio();
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    abrirEditar(p) {
      this.editando = p;
      this.form = {
        nombre: p.nombre || '',
        sigla: p.sigla || '',
        siglaManual: !!p.sigla,
        lider: p.lider || '',
        descripcion: p.descripcion || '',
        color: TONOS[String(p.color || '').toUpperCase()] ? p.color.toUpperCase() : this.paleta[0],
      };
      this.errors = {};
      window.$(this.$refs.modal).modal('show');
    },
    guardar() {
      if (!this.form.nombre.trim()) {
        this.errors = { nombre: ['El nombre del programa es obligatorio.'] };
        this.$refs.nombre.focus();
        return;
      }
      if (!/^[A-Z][A-Z0-9]{1,3}$/.test(this.form.sigla)) {
        this.errors = { sigla: ['La sigla debe tener de 2 a 4 letras o numeros y empezar con una letra.'] };
        return;
      }
      const duplicado = this.programas.find((i) => (!this.editando || i.id !== this.editando.id)
        && this.normalizar(i.nombre) === this.normalizar(this.form.nombre.trim()));
      if (duplicado) {
        this.errors = { nombre: ['Ya existe un programa con ese nombre.'] };
        return;
      }

      this.guardando = true;
      this.errors = {};
      const payload = {
        nombre: this.form.nombre.trim(),
        sigla: this.form.sigla.trim(),
        lider: this.form.lider.trim(),
        descripcion: this.form.descripcion.trim(),
        color: this.form.color,
      };
      const editando = this.editando;
      const peticion = editando
        ? window.axios.put(`/investigacion/programas/${editando.id}`, payload)
        : window.axios.post('/investigacion/programas', payload);

      peticion
        .then(({ data }) => {
          window.$(this.$refs.modal).modal('hide');
          this.$emit('guardado', data, !!editando);
          this.$swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: editando ? 'Programa actualizado' : 'Programa creado',
            text: data.recodificados ? `${data.nombre}: ${data.recodificados} codigo(s) actualizados` : data.nombre,
            timer: 2200,
            showConfirmButton: false,
          });
        })
        .catch((err) => {
          if (err.response && err.response.status === 422) {
            this.errors = err.response.data.errors || {};
            if (!err.response.data.errors && err.response.data.message) {
              this.$swal.fire({ icon: 'warning', title: 'No se cambio la sigla', text: err.response.data.message });
            }
          } else if (err.response && err.response.status === 403) {
            this.$swal.fire({ icon: 'error', title: 'Acceso denegado', text: 'Solo el Administrador puede gestionar programas.' });
          } else {
            this.$swal.fire({ icon: 'error', title: 'Ocurrio un error', text: 'No se pudo guardar el programa. Intente de nuevo.' });
          }
        })
        .finally(() => { this.guardando = false; });
    },
  },
};
</script>

<style scoped>
.prg-modal .modal-title i { margin-right: 8px; color: #73BC25; }
.prg-preview {
  display: flex; align-items: center; gap: 12px; margin-bottom: 18px; padding: 12px 14px;
  border: 1px solid color-mix(in srgb, var(--prg-color) 40%, var(--cengi-border));
  border-left: 5px solid var(--prg-color); border-radius: var(--cengi-radius-sm);
  background: color-mix(in srgb, var(--prg-color) 7%, #fff);
}
.prg-preview strong { display: block; color: var(--cengi-primary-deep); font-size: 14px; }
.prg-preview small { color: var(--cengi-muted); font-size: 12px; }
.prg-avatar {
  display: grid; place-items: center; width: 44px; height: 44px; flex-shrink: 0; border-radius: 12px;
  background: color-mix(in srgb, var(--prg-color) 18%, #fff); color: var(--prg-oscuro);
  font-family: 'Space Grotesk', sans-serif; font-size: 15px; font-weight: 700; letter-spacing: .02em;
}
.prg-req { color: #FF6B00; }
.prg-sigla { text-transform: uppercase; font-weight: 700; letter-spacing: .06em; max-width: 140px; }
.prg-aviso { color: #b84d00; font-weight: 600; }
.prg-counter { display: block; margin-top: 4px; color: #8a9b8e; font-size: 11px; text-align: right; }
.prg-swatches { display: flex; flex-wrap: wrap; gap: 10px; }
.prg-swatch {
  display: grid; place-items: center; width: 36px; height: 36px; border: 3px solid #fff; border-radius: 50%;
  box-shadow: 0 0 0 1px #CED2D5; color: #1f2a1f; font-size: 13px; cursor: pointer; transition: transform .12s ease, box-shadow .12s ease;
}
.prg-swatch:hover { transform: scale(1.08); }
.prg-swatch.is-active { box-shadow: 0 0 0 2px var(--cengi-primary-deep); }
.prg-swatch:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(115, 188, 37, .45); }
</style>
