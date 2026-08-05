document.addEventListener("DOMContentLoaded", function () {

    const form = document.getElementById("formInscripcion");
    const btn = document.getElementById("btnEnviar");

    form.addEventListener("submit", function () {

        btn.innerHTML = `
            <span class="material-symbols-outlined animate-spin">
                sync
            </span>
            Enviando...
        `;

        btn.disabled = true;

    });

});
