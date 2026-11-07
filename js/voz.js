/* ARCHIVO: voz.js | CAPA: FRONTEND (reserva por voz)
   SIRVE PARA: reservar una cita hablando (y sin escribir), para personas mayores o con dificultad para escribir.
   QUÉ HACE:
     1) Escucha con el micrófono (Web Speech API del navegador, si lo tiene).
     2) Interpreta la frase: CI del paciente, médico, especialidad, día y hora.
     3) Muestra lo que entendió en un formulario que la persona puede corregir.
     4) Al confirmar, consulta el paciente por CI y programa la cita con backend/citas.php.
   Parte de interpretación (VozReserva.interpretar) es pura: se puede probar sin micrófono.
   CONECTA CON: inicio.html, backend/pacientes.php, backend/especialidades_medicos.php,
                backend/disponibilidad.php, backend/citas.php, js/app.js */
(function () {
    'use strict';

    const quitarTildes = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const pad = n => String(n).padStart(2, '0');
    const fechaISO = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    const NUM_PALABRA = {
        cero: 0, uno: 1, una: 1, dos: 2, tres: 3, cuatro: 4, cinco: 5, seis: 6, siete: 7, ocho: 8, nueve: 9,
        diez: 10, once: 11, doce: 12,
    };
    const DIA_ISO = { lunes: 1, martes: 2, miercoles: 3, jueves: 4, viernes: 5, sabado: 6, domingo: 7 };
    const MESES = {
        enero: 1, febrero: 2, marzo: 3, abril: 4, mayo: 5, junio: 6, julio: 7, agosto: 8,
        septiembre: 9, setiembre: 9, octubre: 10, noviembre: 11, diciembre: 12,
    };
    // palabra que aparece en la frase -> nombre de la especialidad en la base de datos
    const PISTAS_ESPECIALIDAD = [
        ['pediatr', 'pediatria'], ['ginec', 'ginecologia'], ['odont', 'odontologia'],
        ['dent', 'odontologia'], ['cardi', 'cardiologia'], ['medicina general', 'medicina general'],
        ['general', 'medicina general'],
    ];

    /** Pasa "siete seis cinco" a "765" solo en las palabras de números. */
    function dígitosHablados(tokens) {
        const map = { cero: '0', uno: '1', dos: '2', tres: '3', cuatro: '4', cinco: '5', seis: '6', siete: '7', ocho: '8', nueve: '9' };
        let mejor = '', actual = '';
        for (const t of tokens) {
            const d = /^\d+$/.test(t) ? t : (map[t] ?? null);
            if (d !== null) actual += d;
            else { if (actual.length > mejor.length) mejor = actual; actual = ''; }
        }
        if (actual.length > mejor.length) mejor = actual;
        return mejor.length >= 5 ? mejor : null;
    }

    /** Lee la hora: "a las 8", "las 3 y media", "8:30", "a las 3 de la tarde". Devuelve "HH:MM" o null. */
    function leerHora(t) {
        let m = t.match(/(?:a las|las)\s+(\d{1,2}|una|uno|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce)(?::(\d{2}))?(\s+y\s+media)?/);
        let h, min = 0;
        if (m) {
            h = /^\d+$/.test(m[1]) ? parseInt(m[1], 10) : NUM_PALABRA[m[1]];
            min = m[2] ? parseInt(m[2], 10) : (m[3] ? 30 : 0);
        } else {
            m = t.match(/\b(\d{1,2}):(\d{2})\b/);
            if (!m) return null;
            h = parseInt(m[1], 10); min = parseInt(m[2], 10);
        }
        if (/de la tarde|de la noche|por la tarde|pm/.test(t) && h < 12) h += 12;
        else if (/de la manana|am/.test(t) && h === 12) h = 0;
        else if (!/de la manana|am/.test(t) && h >= 1 && h <= 6) h += 12;   // en la clínica, "a las 3" es 15:00
        if (h < 7 || h > 19 || min > 59) return null;
        return `${pad(h)}:${pad(min)}`;
    }

    /** Lee el día. Devuelve "AAAA-MM-DD" o null. */
    function leerFecha(t, hoy) {
        const base = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate());
        if (/\bhoy\b/.test(t)) return fechaISO(base);
        if (/\bmanana\b/.test(t.replace(/de la manana/g, ''))) {
            const d = new Date(base); d.setDate(d.getDate() + 1); return fechaISO(d);
        }
        const dia = Object.keys(DIA_ISO).find(k => new RegExp(`\\b${k}\\b`).test(t));
        if (dia) {
            const d = new Date(base);
            let diff = (DIA_ISO[dia] - (d.getDay() === 0 ? 7 : d.getDay()) + 7) % 7;
            if (diff === 0) diff = 7;   // "el lunes" dicho en lunes = el lunes siguiente
            d.setDate(d.getDate() + diff);
            return fechaISO(d);
        }
        const m = t.match(/(\d{1,2}) de (enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)/);
        if (m) {
            let anio = base.getFullYear();
            const d = new Date(anio, MESES[m[2]] - 1, parseInt(m[1], 10));
            if (d < base) d.setFullYear(anio + 1);
            return fechaISO(d);
        }
        return null;
    }

    /**
     * Interpreta una frase dictada o escrita.
     * @param texto   lo que dijo la persona
     * @param medicos lista de médicos (de backend/especialidades_medicos.php?tipo=medicos)
     * @param hoy     fecha de referencia (Date); por defecto, hoy
     * @returns {ci, medico, especialidad, fecha, hora, faltantes}
     */
    function interpretar(texto, medicos = [], hoy = new Date()) {
        const t = quitarTildes(texto).replace(/[.,;]/g, ' ').replace(/\s+/g, ' ').trim();
        const tokens = t.split(' ');
        const r = { texto, ci: null, medico: null, especialidad: null, fecha: null, hora: null, faltantes: [] };

        // CI dicho después de la palabra CI/carnet: se juntan todos los números, aunque el
        // reconocedor de voz los separe con ":" o espacios (ej. "11:30 5021" = 11305021).
        // Ese tramo se quita del texto para que no se confunda con una hora.
        let textoSinCi = t;
        const porPalabra = t.match(/\b(?:ci|carnet|cedula|c i)\b\s*(?:es|:|numero|nro)?\s*([\d:.\s-]+)/);
        if (porPalabra) {
            const digitos = porPalabra[1].replace(/\D/g, '');
            if (digitos.length >= 5) {
                r.ci = digitos;
                textoSinCi = t.replace(porPalabra[0], ' ');
            }
        }
        if (!r.ci) r.ci = dígitosHablados(tokens);

        // Médico: por apellido o nombre (si la frase no menciona ninguno, queda vacío)
        r.medico = medicos.find(m => {
            const ap = quitarTildes(m.apellidos).split(' ');
            const no = quitarTildes(m.nombres).split(' ');
            return ap.some(p => p.length >= 3 && new RegExp(`\\b${p}\\b`).test(t)) ||
                   no.some(p => p.length >= 4 && new RegExp(`\\b${p}\\b`).test(t) && /doctor|doctora|dr|dra/.test(t));
        }) || null;

        // Especialidad: por palabra clave (pediatra, dentista...) o nombre exacto
        const pista = PISTAS_ESPECIALIDAD.find(([p]) => t.includes(p));
        // Si no nombró al médico pero sí la especialidad, se elige un médico que la atienda
        if (!r.medico && pista) {
            r.medico = medicos.find(m => m.especialidades.some(e => quitarTildes(e.nombre).includes(pista[1]))) || null;
        }
        const listaEsp = r.medico ? r.medico.especialidades : [];
        if (pista) {
            r.especialidad = listaEsp.find(e => quitarTildes(e.nombre).includes(pista[1])) || null;
        }
        if (!r.especialidad && listaEsp.length === 1) r.especialidad = listaEsp[0];

        r.fecha = leerFecha(textoSinCi, hoy);
        r.hora = leerHora(textoSinCi);

        if (!r.ci) r.faltantes.push('CI del paciente');
        if (!r.medico) r.faltantes.push('médico');
        if (!r.fecha) r.faltantes.push('día');
        if (!r.hora) r.faltantes.push('hora');
        return r;
    }

    const VozReserva = { interpretar, dígitosHablados, leerHora, leerFecha };
    if (typeof module !== 'undefined' && module.exports) module.exports = VozReserva;
    if (typeof window !== 'undefined') window.VozReserva = VozReserva;
})();
