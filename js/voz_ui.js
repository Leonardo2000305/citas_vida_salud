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

    // ---------- Disponibilidad y sugerencias ----------
    function fechaLarga(iso) {
        return new Date(iso + 'T00:00:00').toLocaleDateString('es-BO', { weekday: 'long', day: '2-digit', month: 'long' });
    }

    async function horasLibres(idMedico, fecha) {
        const d = await Clinica.api(`disponibilidad.php?medico=${idMedico}&fecha=${fecha}`);
        return d.horas;
    }

    /** Busca el día más cercano al pedido (primero hacia adelante) con horas libres. */
    async function buscarCercana(idMedico, fecha, horaDeseada) {
        const base = new Date(fecha + 'T00:00:00');
        const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        for (const k of [1, 2, 3, 4, 5, 6, 7, -1, -2, -3]) {
            const d = new Date(base); d.setDate(d.getDate() + k);
            if (d < hoy) continue;
            const iso = d.toLocaleDateString('en-CA');
            const horas = await horasLibres(idMedico, iso).catch(() => []);
            if (horas.length) {
                const pref = horaDeseada && horas.find(h => h.valor === horaDeseada);
                return { fecha: iso, hora: (pref || horas[0]).valor };
            }
        }
        return null;
    }

    /** Muestra el formulario normal y oculta la sugerencia. */
    function mostrarForm() {
        $('formVoz').classList.remove('d-none');
        $('vSugerencia').classList.add('d-none');
    }

    /** Sin horas ese día: no se muestra el formulario, solo el aviso y la recomendación. */
    function mostrarSugerencia(medico, fecha, cerca) {
        $('formVoz').classList.add('d-none');
        const nombre = medico ? `Dr. ${Clinica.esc(medico.apellidos)}` : 'El médico';
        let html = `<strong>${nombre}</strong> no tiene horas libres el <strong>${fechaLarga(fecha)}</strong>.`;
        let frase = `${medico ? 'El doctor ' + medico.apellidos : 'El médico'} no tiene horas libres ese día.`;
        if (cerca) {
            html += `<br>Le recomendamos el <strong>${fechaLarga(cerca.fecha)}</strong> a las <strong>${cerca.hora}</strong>.`;
            frase += ` Le recomiendo el ${fechaLarga(cerca.fecha)} a las ${cerca.hora.replace(':', ' y ')}.`;
        } else {
            html += '<br>No encontré horas libres cerca de esa fecha. Elija otro día o médico.';
        }
        if (esPaciente) html += '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-info" id="vEspera">Anotarme en lista de espera</button></div>';
        html += `<div class="mt-2 d-flex gap-2 flex-wrap">
            ${cerca ? '<button type="button" class="btn btn-sm btn-primary" id="vAceptar">Reservar ese día</button>' : ''}
            <button type="button" class="btn btn-sm btn-outline-secondary" id="vOtroDia">Elegir otro día</button></div>`;
        $('vSugerencia').innerHTML = html;
        $('vSugerencia').classList.remove('d-none');
        hablar(frase);
        if (cerca) $('vAceptar').addEventListener('click', () => {
            $('vFecha').value = cerca.fecha;
            mostrarForm();
            cargarHoras(cerca.hora);
        });
        if (esPaciente) $('vEspera').addEventListener('click', async () => {
            try {
                const r = await Clinica.api('espera.php', { metodo: 'POST', datos: { accion: 'anotar', medico: medico.id_medico, fecha } });
                Clinica.toast(r.mensaje);
                $('vEspera').disabled = true;
            } catch (e) { Clinica.toast(e.message, 'error'); }
        });
        $('vOtroDia').addEventListener('click', () => {
            mostrarForm();
            $('vFecha').value = '';
            $('vHora').innerHTML = '<option value="">Elija otro día</option>';
            mostrarAviso('vAviso', 'Elija otro día para ver sus horas libres.', 'info');
        });
    }

    /** Al cambiar médico o día a mano, si no hay horas se muestra la sugerencia. */
    async function revisarManual() {
        const med = $('vMedico').value, fecha = $('vFecha').value;
        if (!med || !fecha) return cargarHoras();
        const horas = await horasLibres(med, fecha).catch(() => null);
        if (horas && horas.length === 0) {
            const m = medicos.find(x => String(x.id_medico) === med);
            return mostrarSugerencia(m, fecha, await buscarCercana(med, fecha, null));
        }
        mostrarForm();
        cargarHoras();
    }

    /** Próximos 7 días con horas libres del médico: botones para elegir sin saber sus horarios. */
    async function mostrarOpciones(medico) {
        const caja = $('vOpciones');
        caja.innerHTML = '<span class="small text-muted">Buscando horarios del doctor…</span>';
        caja.classList.remove('d-none');
        const hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        const dias = [];
        for (let k = 0; k < 7; k++) {
            const d = new Date(hoy); d.setDate(d.getDate() + k);
            dias.push(d.toLocaleDateString('en-CA'));
        }
        const resultados = await Promise.all(dias.map(f => horasLibres(medico.id_medico, f).then(h => [f, h]).catch(() => [f, []])));
        let html = `<div class="small mb-2">Horarios libres de <strong>Dr. ${Clinica.esc(medico.apellidos)}</strong> en los próximos 7 días. Toque el que le convenga:</div>`;
        let total = 0;
        for (const [f, horas] of resultados) {
            if (!horas.length) continue;
            const dia = new Date(f + 'T00:00:00').toLocaleDateString('es-BO', { weekday: 'short', day: '2-digit', month: '2-digit' });
            html += `<div class="mb-2"><span class="small fw-semibold me-2">${Clinica.esc(dia)}:</span>` +
                horas.map(h => `<button type="button" class="btn btn-sm btn-outline-primary m-1" data-fecha="${f}" data-hora="${h.valor}">${Clinica.esc(h.valor)}</button>`).join('') +
                '</div>';
            total += horas.length;
        }
        if (!total) html = `<div class="small">Dr. ${Clinica.esc(medico.apellidos)} no tiene horas libres en los próximos 7 días. Elija otro médico o pida ayuda en recepción.</div>`;
        caja.innerHTML = html;
        hablar(total ? 'Estos son los horarios libres del doctor. Toque el que le convenga.' : 'El doctor no tiene horas en los próximos días.');
    }

    $('vOpciones') && $('vOpciones').addEventListener('click', async (ev) => {
        const b = ev.target.closest('button[data-fecha]');
        if (!b) return;
        $('vFecha').value = b.dataset.fecha;
        mostrarForm();
        $('vOpciones').classList.add('d-none');
        await cargarHoras(b.dataset.hora);
        $('vHora').value = b.dataset.hora;
        mostrarAviso('vAviso', `Revise los datos: ${fechaLarga(b.dataset.fecha)} a las ${Clinica.esc(b.dataset.hora)}. Luego confirme.`, 'info');
    });

    function mostrarFormulario(r) {
        $('vOpciones').classList.add('d-none');
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

        // Sin día u hora: se muestran los horarios libres del médico para que elija
        if (r.medico && (!r.fecha || !r.hora)) {
            mostrarForm();
            if (r.fecha) cargarHoras(r.hora);
            mostrarOpciones(r.medico);
            hablar('Dígame el día u hora, o toque uno de los horarios libres del doctor.');
            return;
        }

        if (r.medico && r.fecha) {
            horasLibres(r.medico.id_medico, r.fecha).then(async (horas) => {
                if (horas.length === 0) {
                    return mostrarSugerencia(r.medico, r.fecha, await buscarCercana(r.medico.id_medico, r.fecha, r.hora));
                }
                mostrarForm();
                cargarHoras(r.hora);
                if (r.faltantes.length) {
                    mostrarAviso('vAviso', `Me faltó: <strong>${Clinica.esc(r.faltantes.join(', '))}</strong>. Complételo abajo.`);
                    hablar('Me falta: ' + r.faltantes.join(', ') + '. Complete el formulario y confirme.');
                } else {
                    hablar(`Entendí: cita con el doctor ${r.medico.apellidos}, para el día que indicó, a las ${r.hora.replace(':', ' y ')}. Revise y confirme.`);
                }
            }).catch(() => { mostrarForm(); cargarHoras(r.hora); });
            return;
        }
        mostrarForm();
        cargarHoras(r.hora);
        if (r.faltantes.length) {
            mostrarAviso('vAviso', `Me faltó: <strong>${Clinica.esc(r.faltantes.join(', '))}</strong>. Complételo abajo.`);
            hablar('Me falta: ' + r.faltantes.join(', ') + '. Complete el formulario y confirme.');
        }
    }

    function procesar(texto) {
        const r = VozReserva.interpretar(texto, medicos);
        mostrarFormulario(r);
    }

    /** Une fragmentos del reconocedor sin repetir: si un fragmento es la versión completa del anterior, lo reemplaza. */
    function colapsarTexto(lista) {
        const out = [];
        for (const raw of lista) {
            const t = String(raw || '').trim();
            if (!t) continue;
            if (out.length) {
                const a = out[out.length - 1].toLowerCase(), b = t.toLowerCase();
                if (b.startsWith(a)) { out[out.length - 1] = t; continue; }   // versión más completa
                if (a.startsWith(b)) continue;                                  // versión más corta, ya está
            }
            out.push(t);
        }
        return out.join(' ');
    }

    // ---------- Escuchar ----------
    function escuchar() {
        if (!SR) return;
        let historial = [];    // fragmentos de sesiones anteriores (ya cerradas)
        let sesion = [];       // fragmentos de la sesión actual
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
                // Se arma todo el texto de la sesión de nuevo: así no se repiten palabras,
                // aunque el celular vuelva a enviar resultados ya recibidos.
                sesion = Array.from(ev.results, r => r[0].transcript);
                $('textoVoz').value = colapsarTexto([...historial, ...sesion]);
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
                historial = historial.concat(sesion);   // guarda lo dicho en esta sesión
                sesion = [];
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
            const creada = await Clinica.api('citas.php', { metodo: 'POST', datos });
            const med = medicos.find(m => String(m.id_medico) === medico);
            hablar(`Cita reservada${nombre ? ' para ' + nombre : ''}, con el doctor ${med ? med.apellidos : ''}, el día ${fecha.slice(8)} a las ${hora.replace(':', ' y ')}.`);
            Clinica.toast('Cita reservada por voz.');
            $('formVoz').classList.add('d-none');
            const espTxt = $('vEspecialidad').options[$('vEspecialidad').selectedIndex]?.text || '';
            $('vSugerencia').className = 'alert alert-success mt-3';
            $('vSugerencia').innerHTML = `<strong><i class="fa-solid fa-circle-check"></i> Cita reservada</strong><br>` +
                `Número de cita: <strong>#${Clinica.esc(creada.id)}</strong><br>` +
                `Dr. ${med ? Clinica.esc(med.apellidos) : ''} · ${fechaLarga(fecha)} a las <strong>${Clinica.esc(hora)}</strong><br>` +
                `<span class="small">Preparación: ${Clinica.esc(Clinica.preparacion(espTxt))}</span>`;
            $('vSugerencia').classList.remove('d-none');
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
        $('vMedico').addEventListener('change', () => { llenarEspecialidades(); revisarManual(); });
        $('vFecha').addEventListener('change', () => revisarManual());
        $('formVoz').addEventListener('submit', reservar);

        try {
            const d = await Clinica.api('especialidades_medicos.php?tipo=medicos');
            medicos = d.medicos.filter(m => m.activo);
        } catch (e) {
            decir('No pude cargar los médicos: ' + e.message, 'error');
        }
    });
})();
