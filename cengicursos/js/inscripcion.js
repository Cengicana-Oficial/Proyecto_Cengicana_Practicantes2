document.addEventListener("DOMContentLoaded", function () {

    const form = document.getElementById("formInscripcion");
    const btn = document.getElementById("btnEnviar");

    if (form && btn) {
        form.addEventListener("submit", function () {

            btn.innerHTML = `
                <span class="material-symbols-outlined animate-spin">
                    sync
                </span>
                Enviando...
            `;

            btn.disabled = true;

        });
    }

    const modal = document.getElementById("modalCargaMasiva");
    const btnAbrir = document.getElementById("btnAbrirCargaMasiva");
    const btnCerrar = document.getElementById("btnCerrarCargaMasiva");
    const formMasiva = document.getElementById("formCargaMasiva");
    const btnEnviarMasiva = document.getElementById("btnEnviarCargaMasiva");

    function abrirModal() {
        modal.classList.remove("hidden");
        modal.classList.add("flex");
    }

    function cerrarModal() {
        modal.classList.add("hidden");
        modal.classList.remove("flex");
    }

    if (!modal || !btnAbrir || !btnCerrar || !formMasiva || !btnEnviarMasiva) {
        return;
    }

    btnAbrir.addEventListener("click", abrirModal);
    btnCerrar.addEventListener("click", cerrarModal);

    modal.addEventListener("click", function (event) {
        if (event.target === modal) {
            cerrarModal();
        }
    });

    formMasiva.addEventListener("submit", function () {

        btnEnviarMasiva.innerHTML = `
            <span class="material-symbols-outlined animate-spin">
                sync
            </span>
            Cargando...
        `;

        btnEnviarMasiva.disabled = true;

    });

});
