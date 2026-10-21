/**
 * Clínica Vida Salud · mejoras de interfaz y validación en el navegador.
 * La validación real se hace SIEMPRE en el servidor; esto solo ayuda al usuario.
 */
(function () {
    'use strict';

    // Menú móvil
    const boton = document.querySelector('.menu-toggle');
    if (boton) {
        boton.addEventListener('click', function () {
            const abierto = document.body.classList.toggle('menu-abierto');
            boton.setAttribute('aria-expanded', String(abierto));
        });
    }

    // Confirmación antes de acciones que cambian datos importantes
    document.querySelectorAll('form[data-confirmar]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!window.confirm(form.dataset.confirmar)) {
                ev.preventDefault();
            }
        });
    });

    // Validación de formularios marcados con data-validar
    document.querySelectorAll('form[data-validar]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            // El botón "Ver horarios" solo recarga las horas: no necesita validar todo
            if (ev.submitter && ev.submitter.name === 'ver') { return; }
            let valido = true;
            form.querySelectorAll('.error-campo').forEach(function (n) { n.remove(); });
            form.querySelectorAll('input, select').forEach(function (campo) {
                campo.classList.remove('invalido');
                let error = '';
                if (campo.required && !campo.value.trim()) {
                    error = 'Este campo es obligatorio.';
                } else if (campo.name === 'clave' || campo.name === 'nueva') {
                    if (campo.value && (campo.value.length < 8 || !/[A-Za-z]/.test(campo.value) || !/\d/.test(campo.value))) {
                        error = 'Mínimo 8 caracteres, con al menos una letra y un número.';
                    }
                } else if (campo.name === 'repite' && campo.value !== (form.querySelector('[name="nueva"]') || {}).value) {
                    error = 'Las contraseñas no coinciden.';
                } else if (campo.name === 'telefono' && campo.value && !/^\+?[0-9 ]{7,15}$/.test(campo.value)) {
                    error = 'Entre 7 y 15 dígitos.';
                } else if (campo.name === 'ci' && campo.value && !/^[A-Za-z0-9-]{5,15}$/.test(campo.value)) {
                    error = 'Entre 5 y 15 caracteres (letras, números o guion).';
                }
                if (error) {
                    valido = false;
                    campo.classList.add('invalido');
                    const aviso = document.createElement('span');
                    aviso.className = 'error-campo';
                    aviso.textContent = error;
                    campo.parentNode.appendChild(aviso);
                }
            });
            if (!valido) {
                ev.preventDefault();
            }
        });
    });

    // Filtrar médicos según la especialidad elegida al programar una cita
    const esp = document.querySelector('select[data-filtra-medicos]');
    const medicoSel = document.querySelector('select[data-medicos]');
    if (esp && medicoSel) {
        const filtrar = function () {
            const id = esp.value;
            Array.from(medicoSel.options).forEach(function (op) {
                if (!op.value) { return; }
                const ids = (op.dataset.especialidades || '').split(',');
                op.hidden = id !== '' && ids.indexOf(id) === -1;
            });
            if (medicoSel.selectedOptions[0] && medicoSel.selectedOptions[0].hidden) {
                medicoSel.value = '';
            }
        };
        esp.addEventListener('change', filtrar);
        filtrar();
    }

    // Cargar las horas libres cuando cambian médico o fecha
    const form = document.querySelector('form[data-horarios]');
    const horaSel = document.querySelector('select[data-hora]');
    const fechaInp = document.querySelector('input[data-fecha]');
    if (form && horaSel && fechaInp && medicoSel) {
        const cargarHoras = function () {
            if (!medicoSel.value || !fechaInp.value) { return; }
            const url = form.dataset.horarios + '?medico=' + encodeURIComponent(medicoSel.value)
                      + '&fecha=' + encodeURIComponent(fechaInp.value);
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (datos) {
                    horaSel.innerHTML = '';
                    const inicial = document.createElement('option');
                    inicial.value = '';
                    inicial.textContent = (datos.horas && datos.horas.length) ? 'Seleccione una hora' : 'Sin horarios libres';
                    horaSel.appendChild(inicial);
                    (datos.horas || []).forEach(function (h) {
                        const op = document.createElement('option');
                        op.value = h.valor;
                        op.textContent = h.valor;
                        horaSel.appendChild(op);
                    });
                })
                .catch(function () { /* si falla, la página sigue funcionando con "Ver horarios" */ });
        };
        medicoSel.addEventListener('change', cargarHoras);
        fechaInp.addEventListener('change', cargarHoras);
    }
})();
