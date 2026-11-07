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
    let reconocimiento = null;
    let escuchando = false;   // se usa para parar la escucha si la persona vuelve a tocar el micrófono
    let esPaciente = false;   // el paciente reserva para sí mismo; el servidor lo identifica por su sesión

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
        // El paciente no dicta su CI: el servidor usa su propia cuenta
        if (esPaciente) r.faltantes = r.faltantes.filter(f => f !== 'CI del paciente');
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
        let final = '';
        let reintentos = 0;
        escuchando = true;

        const pintarBoton = (activo) => {
            $('btnMicro').classList.toggle('escuchando', activo);
            $('btnMicro').innerHTML = activo
                ? '<i class="fa-solid fa-microphone"></i> Escuchando… (toque para terminar)'
                : '<i class="fa-solid fa-microphone"></i> Hablar';
        };

        // Crea un reconocimiento nuevo. Se reinicia solo si hubo silencio, para dar tiempo a que alguien hable.
        function arrancar() {
            const rec = new SR();
            reconocimiento = rec;
            rec.lang = 'es-419';
            rec.interimResults = true;
            rec.continuous = true;          // no se corta al primer silencio
            rec.maxAlternatives = 1;

            rec.onresult = (ev) => {
                let parcial = '';
                for (let i = ev.resultIndex; i < ev.results.length; i++) {
                    const res = ev.results[i];
                    if (res.isFinal) final += res[0].transcript + ' ';
                    else parcial += res[0].transcript;
                }
                $('textoVoz').value = (final + parcial).trim();
                reintentos = 0;
            };

            rec.onerror = (ev) => {
                if (ev.error === 'no-speech' || ev.error === 'aborted') return;   // silencio o parada normal: no es error
                escuchando = false;
                const mensajes = {
                    'not-allowed': 'El navegador no tiene permiso para el micrófono. Toque el candado de la barra de direcciones y permítalo.',
                    'service-not-allowed': 'El navegador no permite el reconocimiento de voz en esta página. Use Chrome o Edge.',
                    'audio-capture': 'No encuentro un micrófono conectado. Revise que esté activo en Windows.',
                    'network': 'El reconocimiento de voz necesita internet. Revise su conexión e intente de nuevo.',
                    'aborted': 'Se detuvo la escucha. Toque Hablar para intentar otra vez.',
                };
                decir(mensajes[ev.error] || ('No pude escuchar (' + ev.error + '). Escriba la orden en la casilla.'), 'error');
            };

            rec.onend = () => {
                reconocimiento = null;
                if (escuchando && reintentos < 20) {
                    reintentos++;
                    arrancar();                 // sigue escuchando hasta que la persona toque el botón
                    reconocimiento.start();
                    return;
                }
                escuchando = false;
                pintarBoton(false);
                const texto = $('textoVoz').value.trim();
                if (texto) { decir('Listo, esto escuché.'); procesar(texto); }
                else if (!$('estadoVoz').classList.contains('text-danger')) decir('No escuché ninguna orden. Intente de nuevo.', 'error');
            };

            rec.start();
        }

        pintarBoton(true);
        decir('Hable ahora… Toque el botón cuando termine.');
        arrancar();
    }

    // ---------- Confirmar reserva ----------
    async function reservar(ev) {
        ev.preventDefault();
        $('vAviso').classList.add('d-none');
        const ci = $('vCi').value.trim();
        const medico = $('vMedico').value, especialidad = $('vEspecialidad').value;
        const fecha = $('vFecha').value, hora = $('vHora').value;
        if (!esPaciente && !/^\d{5,15}$/.test(ci)) return mostrarAviso('vAviso', 'El CI debe tener entre 5 y 15 números.', 'danger');
        if (!medico || !fecha || !hora) return mostrarAviso('vAviso', 'Falta elegir médico, día u hora.', 'danger');

        const boton = $('vConfirmar');
        boton.disabled = true;
        try {
            let idPaciente = null, nombre = '';
            if (!esPaciente) {
                const d = await Clinica.api('pacientes.php?q=' + encodeURIComponent(ci));
                const p = d.pacientes.find(x => x.ci === ci);
                if (!p) {
                    return mostrarAviso('vAviso',
                        `No encontré un paciente con el CI <strong>${Clinica.esc(ci)}</strong>. ` +
                        `<a href="pacientes.html?nuevo=1">Regístrelo primero</a> y vuelva a intentarlo.`, 'danger');
                }
                if (!p.activo) return mostrarAviso('vAviso', 'Ese paciente está inactivo. Actívelo antes de reservar.', 'danger');
                idPaciente = p.id_paciente;
                nombre = p.nombres;
            }

            // Si es paciente, el servidor pone su propia cuenta; no hace falta enviar el id
            const datos = { accion: 'crear', medico, especialidad, fecha, hora, motivo: $('vMotivo').value.trim() };
            if (idPaciente) datos.paciente = idPaciente;
            await Clinica.api('citas.php', { metodo: 'POST', datos });
            const med = medicos.find(m => String(m.id_medico) === medico);
            hablar(`Cita reservada${nombre ? ' para ' + nombre : ''}, con el doctor ${med ? med.apellidos : ''}, el día ${fecha.slice(8)} a las ${hora.replace(':', ' y ')}.`);
            Clinica.toast('Cita reservada por voz.');
            $('formVoz').classList.add('d-none');
            $('textoVoz').value = '';
            decir('Cita reservada. Puede dictar otra.');
            document.dispatchEvent(new Event('cita-reservada'));
        } catch (e) {
            mostrarAviso('vAviso', Clinica.esc(e.message), 'danger');
        } finally {
            boton.disabled = false;
        }
    }

    // ---------- Arranque ----------
    document.addEventListener('DOMContentLoaded', async () => {
        const usuario = await Clinica.requerirSesion();   // también obtiene el token CSRF
        // Personal y pacientes pueden reservar por voz; el médico no reserva
        const puede = ['Administrador', 'Recepcionista', 'Paciente'].includes(usuario.rol);
        if (!puede) { panel.classList.add('d-none'); return; }
        esPaciente = usuario.rol === 'Paciente';
        if (esPaciente) {
            $('vCi').closest('.col-md-3').classList.add('d-none');
        }

        if (!SR) {
            $('btnMicro').disabled = true;
            decir('Este navegador no tiene micrófono para voz. Use Chrome o Edge, o escriba la orden abajo.');
        } else {
            $('btnMicro').addEventListener('click', () => {
                if (escuchando && reconocimiento) { escuchando = false; reconocimiento.stop(); }
                else if (!escuchando) escuchar();
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
