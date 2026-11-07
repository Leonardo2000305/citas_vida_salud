/* ARCHIVO: app.js | CAPA: FRONTEND | SIRVE PARA: funciones comunes de todas las pantallas.
   QUÉ HACE: llama a la API (backend/...), protege las páginas con sesión, dibuja el menú,
   muestra avisos, escapa texto y guarda el modo claro/oscuro.
   CONECTA CON: backend/*.php (carpeta api) y css/clinica.css. Todas las páginas la cargan. */
(function () {
    'use strict';

    const API = 'backend';   // relativa: funciona en XAMPP (carpeta del proyecto) y en Vercel
    const MENU = [
        { href: 'inicio.html', icono: 'fa-gauge-high', texto: 'Inicio', id: 'inicio' },
        { href: 'pacientes.html', icono: 'fa-user-injured', texto: 'Pacientes', id: 'pacientes' },
        { href: 'citas.html', icono: 'fa-calendar-check', texto: 'Citas', id: 'citas' },
        { href: 'medicos.html', icono: 'fa-user-doctor', texto: 'Médicos', id: 'medicos' },
    ];
    // El paciente solo ve sus citas y los médicos
    const MENU_PACIENTE = [
        { href: 'mis-citas.html', icono: 'fa-calendar-check', texto: 'Mis citas', id: 'mis-citas' },
        { href: 'medicos.html', icono: 'fa-user-doctor', texto: 'Médicos', id: 'medicos' },
    ];
    const PAGINAS_PACIENTE = ['mis-citas.html', 'medicos.html'];

    const Clinica = {
        csrf: null,
        usuario: null,
        menu: MENU,

        esc(valor) {
            return String(valor == null ? '' : valor).replace(/[&<>"']/g, c => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));
        },

        async api(ruta, { metodo = 'GET', datos } = {}) {
            const opciones = { method: metodo, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
            if (datos !== undefined) {
                opciones.headers['Content-Type'] = 'application/json';
                opciones.body = JSON.stringify(datos);
            }
            if (metodo !== 'GET' && this.csrf) opciones.headers['X-CSRF-Token'] = this.csrf;
            let respuesta;
            try {
                respuesta = await fetch(`${API}/${ruta}`, opciones);
            } catch (e) {
                throw new Error('No hay conexión con el servidor. Revise su conexión e intente de nuevo.');
            }
            let cuerpo = {};
            try { cuerpo = await respuesta.json(); } catch (e) { /* respuesta vacía */ }
            if (!respuesta.ok) {
                const error = new Error(cuerpo.error || 'Ocurrió un error. Intente nuevamente.');
                error.estado = respuesta.status;
                throw error;
            }
            return cuerpo;
        },

        /** Exige sesión: si no hay, lleva al login. Devuelve el usuario. */
        async requerirSesion() {
            try {
                const d = await this.api('sesion.php');
                this.csrf = d.csrf;
                this.usuario = d.usuario;
                if (d.usuario.rol === 'Paciente') {
                    this.menu = MENU_PACIENTE;
                    const pagina = location.pathname.split('/').pop() || 'index.html';
                    if (!PAGINAS_PACIENTE.includes(pagina)) location.replace('mis-citas.html');
                }
                return d.usuario;
            } catch (e) {
                location.replace('entrar.html');
                throw e;
            }
        },

        async cerrarSesion() {
            try { await this.api('sesion.php', { metodo: 'DELETE' }); } catch (e) { /* ya cerrada */ }
            location.replace('entrar.html');
        },

        /** Dibuja el menú lateral (escritorio) y el menú superior (móvil) dentro de #menu. */
        dibujarMenu(activo) {
            const lateral = this.menu.map(m => `
                <a href="${m.href}" class="${m.id === activo ? 'activo' : ''}">
                    <i class="fa-solid ${m.icono}"></i> ${m.texto}</a>`).join('');
            const movil = this.menu.map(m => `
                <a href="${m.href}" class="${m.id === activo ? 'activo' : ''}">${m.texto}</a>`).join('');
            const u = this.usuario || {};
            const cont = document.getElementById('menu');
            if (!cont) return;
            cont.innerHTML = `
                <aside class="menu-lateral">
                    <div class="marca"><i class="fa-solid fa-heart-pulse"></i> Vida Salud</div>
                    ${lateral}
                    <div class="pie">
                        <div class="mb-2"><strong>${this.esc(u.nombre || '')}</strong><br><span>${this.esc(u.rol || '')}</span></div>
                        <button class="btn-tema w-100 mb-2" type="button" onclick="Clinica.alternarTema()">
                            <i class="fa-solid fa-circle-half-stroke"></i> Modo claro / oscuro</button>
                        <button class="btn btn-outline-light btn-sm w-100" type="button" onclick="Clinica.cerrarSesion()">
                            <i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión</button>
                    </div>
                </aside>
                <nav class="menu-movil">${movil}
                    <a href="#" onclick="Clinica.cerrarSesion();return false;">Salir</a>
                </nav>`;
        },

        toast(texto, tipo = 'ok') {
            let cont = document.querySelector('.avisos');
            if (!cont) {
                cont = document.createElement('div');
                cont.className = 'avisos';
                document.body.appendChild(cont);
            }
            const aviso = document.createElement('div');
            aviso.className = `aviso-toast ${tipo}`;
            aviso.textContent = texto;
            cont.appendChild(aviso);
            setTimeout(() => aviso.remove(), 4200);
        },

        alternarTema() {
            const raiz = document.documentElement;
            const oscuro = raiz.getAttribute('data-theme') === 'dark' ||
                (raiz.getAttribute('data-theme') !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
            const nuevo = oscuro ? 'light' : 'dark';
            raiz.setAttribute('data-theme', nuevo);
            try { localStorage.setItem('vida-tema', nuevo); } catch (e) { /* navegador sin almacenamiento */ }
        },

        aplicarTemaGuardado() {
            try {
                const t = localStorage.getItem('vida-tema');
                if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
            } catch (e) { /* ignorar */ }
        },

        estadoBadge(estado) {
            return `<span class="estado estado-${this.esc(estado)}">${this.esc(estado)}</span>`;
        },

        fecha(iso, conHora = true) {
            if (!iso) return '';
            const d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d)) return iso;
            const opciones = conHora
                ? { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }
                : { day: '2-digit', month: '2-digit', year: 'numeric' };
            return d.toLocaleString('es-BO', opciones);
        },
    };

    window.Clinica = Clinica;
    Clinica.aplicarTemaGuardado();
})();
