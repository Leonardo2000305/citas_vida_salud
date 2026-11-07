/* ARCHIVO: voz_ui.js | CAPA: FRONTEND (panel de reserva por voz en inicio.html)
   SIRVE PARA: el botón de micrófono y el formulario "Esto entendí" donde la persona revisa y confirma.
   QUÉ HACE: escucha, interpreta con VozReserva (js/voz.js), llena el formulario, pide las horas libres
             y, al confirmar, busca al paciente por CI y crea la cita con backend/citas.php.
             Si el navegador no tiene reconocimiento de voz, se puede escribir la orden en la casilla.
   CONECTA CON: js/voz.js, js/app.js, backend/pacientes.php, backend/especialidades_medicos.php,
                backend/disponibilidad.php, backend/citas.php */
(function () {
    'use strict';

    const panel = document.getElementById('panelVoz');
    if (!panel) return;

    const $ = id => document.getElementById(id);
    const estado = $('estadoVoz');
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    let medicos = [];
    let reconocimiento = null;   // se usa para parar la escucha si la persona vuelve a tocar el micrófono

    function hablar(texto) {
        try {
            if (!('speechSynthesis' in window)) return;
            speechSynthesis.cancel();
            const u = new SpeechSynthesisUtterance(texto);
            u.lang = 'es-419';
            u.rate = 0.95;   // un poco más lento, para que se entienda bien
            speechSynthesis.speak(u);
        } catch (e) { /* sin voz: no pasa nada, el texto sigue en pantalla */ }
    }

    function decir(texto, tipo = '') {
        estado.textContent = texto;
        estado.className = 'small ms-2 ' + (tipo === 'error' ? 'text-danger' : 'text-success');
    }

    function mostrarAviso(id, texto, tipo = 'warning') {
        const b = $(id);
        b.className = `alert alert-${tipo} mt-3`;
        b.innerHTML = texto;
        b.classList.remove('d-none');
    }

    // ---------- Formulario "Esto entendí" ----------
    function llenarMedicos(seleccion) {
        $('vMedico').innerHTML = '<option value="">Seleccione…</option>' + medicos.map(m =>
            `<option value="${m.id_medico}">Dr. ${Clinica.esc(m.nombres)} ${Clinica.esc(m.apellidos)}</option>`).join('');
        if (seleccion) $('vMedico').value = seleccion;
    }

    function llenarEspecialidades(preferida) {
        const med = medicos.find(m => String(m.id_medico) === $('vMedico').value);
        const lista = med ? med.especialidades : [];
        $('vEspecialidad').innerHTML = lista.length
            ? lista.map(e => `<option value="${e.id_especialidad}">${Clinica.esc(e.nombre)}</option>`).join('')
            : '<option value="">Elija un médico</option>';
        if (preferida && lista.some(e => e.id_especialidad === preferida.id_especialidad)) {
            $('vEspecialidad').value = preferida.id_especialidad;
        }
    }

    async function cargarHoras(horaDeseada) {
        const med = $('vMedico').value, fecha = $('vFecha').value;
        if (!med || !fecha) { $('vHora').innerHTML = '<option value="">Elija médico y día</option>'; return; }
        $('vHora').innerHTML = '<option value="">Buscando horas libres…</option>';
        try {
            const d = await Clinica.api(`disponibilidad.php?medico=${med}&fecha=${fecha}`);
            const opts = d.horas.map(h => `<option value="${h.valor}">${Clinica.esc(h.texto)}</option>`).join('');
            $('vHora').innerHTML = d.horas.length
                ? '<option value="">Seleccione una hora…</option>' + opts
                : '<option value="">No hay horas libres ese día</option>';
            if (horaDeseada && d.horas.some(h => h.valor === horaDeseada)) {
                $('vHora').value = horaDeseada;
            } else if (horaDeseada) {
                mostrarAviso('vAviso', `A las <strong>${Clinica.esc(horaDeseada)}</strong> no hay cupo. Elija otra hora de la lista.`);
            }
        } catch (e) {
            $('vHora').innerHTML = `<option value="">${Clinica.esc(e.message)}</option>`;
        }
    }

    function mostrarFormulario(r) {
        $('formVoz').classList.remove('d-none');
        $('vCi').value = r.ci || '';
        llenarMedicos(r.medico ? r.medico.id_medico : '');
        llenarEspecialidades(r.especialidad);
        $('vFecha').value = r.fecha || '';
        $('vFecha').min = new Date().toLocaleDateString('en-CA');
        $('vMotivo').value = '';
        $('vAviso').classList.add('d-none');
        $('vResumen').textContent = r.texto ? `Usted dijo: «${r.texto}»` : '';
        cargarHoras(r.hora);

        if (r.faltantes.length) {
            mostrarAviso('vAviso', `Me faltó: <strong>${Clinica.esc(r.faltantes.join(', '))}</strong>. Complételo abajo.`);
            hablar('Me falta: ' + r.faltantes.join(', ') + '. Complete el formulario y confirme.');
        } else {
            hablar(`Entendí: cita con el doctor ${r.medico.apellidos}, para el día que indicó, a las ${r.hora.replace(':', ' y ')}. Revise y confirme.`);
        }
    }

    function procesar(texto) {
        const r = VozReserva.interpretar(texto, medicos);
        mostrarFormulario(r);
    }

    // ---------- Escuchar ----------
    function escuchar() {
        if (!SR) return;
        const rec = new SR();
        reconocimiento = rec;
        rec.lang = 'es-419';
        rec.interimResults = true;
        rec.continuous = false;
        rec.maxAlternatives = 1;
        let final = '';
        $('btnMicro').classList.add('escuchando');
        $('btnMicro').innerHTML = '<i class="fa-solid fa-microphone"></i> Escuchando… (toque para parar)';
        decir('Hable ahora…');
        rec.onresult = (ev) => {
            let parcial = '';
            for (const res of ev.results) {
                if (res.isFinal) final += res[0].transcript + ' ';
                else parcial += res[0].transcript;
            }
            $('textoVoz').value = (final + parcial).trim();
        };
        rec.onerror = (ev) => {
            const msg = ev.error === 'not-allowed'
                ? 'Permita el uso del micrófono en el navegador o escriba la orden.'
                : 'No la escuché bien. Intente de nuevo o escriba la orden.';
            decir(msg, 'error');
        };
        rec.onend = () => {
            reconocimiento = null;
            $('btnMicro').classList.remove('escuchando');
            $('btnMicro').innerHTML = '<i class="fa-solid fa-microphone"></i> Hablar';
            const texto = $('textoVoz').value.trim();
            if (texto) { decir('Listo, esto escuché.'); procesar(texto); }
        };
        rec.start();
    }

    // ---------- Confirmar reserva ----------
    async function reservar(ev) {
        ev.preventDefault();
        $('vAviso').classList.add('d-none');
        const ci = $('vCi').value.trim();
        const medico = $('vMedico').value, especialidad = $('vEspecialidad').value;
        const fecha = $('vFecha').value, hora = $('vHora').value;
        if (!/^\d{5,15}$/.test(ci)) return mostrarAviso('vAviso', 'El CI debe tener entre 5 y 15 números.', 'danger');
        if (!medico || !fecha || !hora) return mostrarAviso('vAviso', 'Falta elegir médico, día u hora.', 'danger');

        const boton = $('vConfirmar');
        boton.disabled = true;
        try {
            const d = await Clinica.api('pacientes.php?q=' + encodeURIComponent(ci));
            const p = d.pacientes.find(x => x.ci === ci);
            if (!p) {
                return mostrarAviso('vAviso',
                    `No encontré un paciente con el CI <strong>${Clinica.esc(ci)}</strong>. ` +
                    `<a href="pacientes.html?nuevo=1">Regístrelo primero</a> y vuelva a intentarlo.`, 'danger');
            }
            if (!p.activo) return mostrarAviso('vAviso', 'Ese paciente está inactivo. Actívelo antes de reservar.', 'danger');

            const r = await Clinica.api('citas.php', { metodo: 'POST', datos: {
                accion: 'crear', paciente: p.id_paciente, medico, especialidad, fecha, hora,
                motivo: $('vMotivo').value.trim(),
            }});
            const med = medicos.find(m => String(m.id_medico) === medico);
            hablar(`Cita reservada para ${p.nombres}, con el doctor ${med ? med.apellidos : ''}, el día ${fecha.slice(8)} a las ${hora.replace(':', ' y ')}.`);
            Clinica.toast('Cita reservada por voz.');
            $('formVoz').classList.add('d-none');
            $('textoVoz').value = '';
            decir('Cita reservada. Puede dictar otra.');
        } catch (e) {
            mostrarAviso('vAviso', Clinica.esc(e.message), 'danger');
        } finally {
            boton.disabled = false;
        }
    }

    // ---------- Arranque ----------
    document.addEventListener('DOMContentLoaded', async () => {
        const usuario = await Clinica.requerirSesion();   // también obtiene el token CSRF
        const esPersonal = ['Administrador', 'Recepcionista'].includes(usuario.rol);
        if (!esPersonal) { panel.classList.add('d-none'); return; }

        if (!SR) {
            $('btnMicro').disabled = true;
            decir('Este navegador no tiene micrófono para voz. Use Chrome o Edge, o escriba la orden abajo.');
        } else {
            $('btnMicro').addEventListener('click', () => {
                if (reconocimiento) reconocimiento.stop(); else escuchar();
            });
        }
        $('btnInterpretar').addEventListener('click', () => {
            const t = $('textoVoz').value.trim();
            if (t) procesar(t); else decir('Escriba o diga la orden primero.', 'error');
        });
        $('vMedico').addEventListener('change', () => { llenarEspecialidades(); cargarHoras(); });
        $('vFecha').addEventListener('change', () => cargarHoras());
        $('formVoz').addEventListener('submit', reservar);

        try {
            const d = await Clinica.api('especialidades_medicos.php?tipo=medicos');
            medicos = d.medicos.filter(m => m.activo);
        } catch (e) {
            decir('No pude cargar los médicos: ' + e.message, 'error');
        }
    });
})();
