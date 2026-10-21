# Pasos para dejar funcionando el sistema de citas

**Estado actual: estamos en el PASO 1.**

Cuando termines un paso, escribe **LISTO** en el chat y di qué pasó. Así sabemos que puedes seguir con el siguiente.
Si algo sale diferente a lo que dice aquí, escribe exactamente qué ves, o manda una foto.

---

## PASO 1: Ver qué tienes instalado

1. Presiona la tecla **Windows** de tu teclado.
2. Escribe **XAMPP** y mira si aparece un programa con ese nombre.
3. Vuelve a presionar la tecla **Windows**, escribe **pgAdmin** y mira si aparece.
4. Confirma que tu computadora es **Windows** (si no lo es, avísame).

**Cuando termines:** escribe LISTO y dime si tienes XAMPP y si tienes pgAdmin (sí o no a cada uno).

---

## PASO 2: Instalar PostgreSQL (la base de datos)

Este paso solo hace falta si en el PASO 1 dijiste que **no** tienes pgAdmin.

1. Abre tu navegador y entra a **postgresql.org/download/windows**.
2. Toca el enlace que dice **Download the installer**.
3. Descarga la versión más reciente que aparezca (la 16 o superior).
4. Abre el archivo que descargaste y sigue el asistente.
5. Cuando pregunte qué componentes instalar, deja marcados **todos**:
   - PostgreSQL Server
   - pgAdmin 4
   - Command Line Tools
6. En la pantalla de **contraseña**, escribe una clave para el usuario `postgres` y **anótala en un papel**. La vas a necesitar en el PASO 4.
7. En la pantalla de **puerto**, deja el número **5432**.
8. Termina la instalación.

**Cuando termines:** escribe LISTO.

---

## PASO 3: Abrir pgAdmin y entrar a PostgreSQL

1. Presiona la tecla **Windows**, escribe **pgAdmin** y ábrelo.
2. Te pedirá una contraseña maestra para pgAdmin. Escribe una que recuerdes y da **OK**.
3. En el panel de la izquierda verás **Servers**. Haz clic en la flecha que está a su lado.
4. Haz clic en **PostgreSQL** (o en el nombre que tenga tu servidor).
5. Te pedirá la contraseña. Escribe la que anotaste en el PASO 2.
6. Si aparece un árbol con **Databases**, significa que entró bien.

**Cuando termines:** escribe LISTO. Si no te deja entrar, dime qué mensaje aparece.

---

## PASO 4: Crear la base de datos del sistema

1. En pgAdmin, haz clic derecho sobre **Databases**.
2. Elige **Create** y luego **Database...**.
3. En el campo **Database** escribe exactamente: `citas_vida_salud`
4. Haz clic en **Save**.
5. Repite lo mismo para crear una segunda base llamada: `citas_vida_salud_test`

Esta segunda base es solo para las pruebas automáticas.

**Cuando termines:** escribe LISTO.

---

## PASO 5: Crear las tablas y los datos de ejemplo

1. En pgAdmin, haz clic en la base **citas_vida_salud** (una sola vez, para seleccionarla).
2. En el menú de arriba, busca **Tools** y luego **Query Tool**.
3. Se abre una ventana en blanco para escribir consultas.
4. En esa ventana, busca el botón para **abrir un archivo** (un icono de carpeta).
5. Busca la carpeta del proyecto (la que descomprimiste del zip) y abre el archivo llamado **schema.sql** que está dentro de la carpeta **sql**.
6. Presiona la tecla **F5** o el botón de **Play** (triángulo) para ejecutarlo.
7. Espera el mensaje de abajo. Si dice algo como "Query returned successfully", está bien.

**Importante:** este paso se hace una sola vez en la base **citas_vida_salud**.

**Cuando termines:** escribe LISTO y dime si apareció algún mensaje de error en rojo.

---

## PASO 6: Revisar que las tablas se crearon

1. En pgAdmin, dentro de **citas_vida_salud**, abre **Schemas**, luego **public** y luego **Tables**.
2. Debes ver estas tablas: cita, horario_medico, medico_especialidad, usuario, medico, paciente, especialidad, rol.
3. Haz clic derecho en **Tables** y luego **Refresh** si no aparecen.

**Cuando termines:** escribe LISTO y dime cuántas tablas ves.

---

## PASO 7: Preparar PHP para que hable con PostgreSQL

Aquí hay que activar una extensión de PHP. Si no sabes cómo, sigue estos pasos con calma.

1. Abre el programa **XAMPP Control Panel**.
2. Haz clic en **Stop** junto a **Apache**, si está encendido.
3. Haz clic en el botón **Config** de la fila de **Apache** y elige **PHP (php.ini)**. Se abrirá un archivo de texto.
4. En ese archivo, presiona **Ctrl + F** y busca: `pdo_pgsql`
5. Vas a encontrar una línea que dice `;extension=pdo_pgsql`
6. Borra el punto y coma `;` del principio de esa línea, para que quede `extension=pdo_pgsql`
7. Haz lo mismo para la línea `;extension=pgsql` si aparece.
8. Guarda el archivo con **Ctrl + S** y ciérralo.
9. Vuelve al XAMPP Control Panel y haz clic en **Start** junto a **Apache**.

**Cuando termines:** escribe LISTO. Si XAMPP no aparece en tu computadora, dime y te explico cómo instalarlo.

---

## PASO 8: Copiar el proyecto a la carpeta de XAMPP

1. Abre el **Explorador de archivos**.
2. Ve a la carpeta **C:\xampp\htdocs**. Es la carpeta donde XAMPP publica las páginas.
3. Copia dentro de esa carpeta toda la carpeta del proyecto **citas_vida_salud** (la que descomprimiste del zip).
4. Debe quedar así: **C:\xampp\htdocs\citas_vida_salud**, y dentro los archivos como **login.php** e **index.php**.

**Cuando termines:** escribe LISTO.

---

## PASO 9: Poner la clave de la base de datos

1. Dentro de la carpeta **C:\xampp\htdocs\citas_vida_salud**, abre la carpeta **config**.
2. Busca el archivo llamado **local.ejemplo.php** y haz una copia de ese archivo en la misma carpeta.
3. Cambia el nombre de la copia a **local.php** (borra la palabra "ejemplo").
4. Abre **local.php** con el Bloc de notas.
5. En la línea que dice `'DB_PASS'`, cambia el texto `ESCRIBA_AQUI_LA_CLAVE_DE_POSTGRES` por la clave que anotaste en el PASO 2. Debe quedar entre comillas simples, por ejemplo: `'DB_PASS' => 'mi_clave',`
6. Guarda el archivo.

**Importante:** este archivo **no se sube a GitHub**. Por eso la clave queda segura.

**Cuando termines:** escribe LISTO.

---

## PASO 10: Entrar al sistema en el navegador

1. Abre tu navegador (Chrome, Edge o el que uses).
2. Escribe en la barra de direcciones: `http://localhost/citas_vida_salud/`
3. Debe aparecer la pantalla de **Iniciar sesión**.
4. Entra con estos datos:
   - Usuario: `admin`
   - Contraseña: `Vida2026*`
5. Debe aparecer el panel de inicio con el menú a la izquierda.

Si sale una pantalla de error, toma una foto y mándamela.

**Cuando termines:** escribe LISTO y dime si entraste.

---

## PASO 11: Probar el sistema (pruebas de las páginas)

Haz cada prueba en el navegador, entrando con `admin`. Anota si el resultado es el esperado.

| N.º | Qué haces | Qué debe pasar |
|---|---|---|
| 1 | Menú **Pacientes**, botón **Nuevo paciente**, llenar y guardar | Aparece el paciente en la lista |
| 2 | Registrar otro paciente con el **mismo CI** | Sale un mensaje que dice que ya existe ese CI |
| 3 | Menú **Citas**, botón **Programar cita**, elegir paciente, especialidad **Medicina general**, médico **Juan Pérez**, una fecha de lunes y una hora libre | La cita se registra |
| 4 | Programar otra cita con la **misma hora** y el mismo médico | Sale el mensaje "no está disponible" |
| 5 | En la lista de horas no aparecen horas fuera del horario del médico | Solo aparecen horas libres |
| 6 | En **Citas**, botón **Cancelar** de una cita, escribir un motivo | La cita queda como Cancelada y sigue en la lista |
| 7 | En **Pacientes**, botón **Historial** | Se ven todas sus citas |
| 8 | En **Citas**, filtrar por médico y fecha | Solo salen esas citas |
| 9 | Cerrar sesión y escribir en la barra `http://localhost/citas_vida_salud/citas.php` | Te manda a la pantalla de iniciar sesión |
| 10 | Intentar guardar un paciente con datos mal escritos (por ejemplo, un teléfono con letras) | Aparecen mensajes de error |

**Cuando termines:** escribe LISTO y dime cuáles pruebas salieron bien y cuáles no.

---

## PASO 12: Ejecutar las pruebas automáticas

1. Abre el **Símbolo del sistema**: presiona la tecla **Windows**, escribe **cmd** y ábrelo.
2. Escribe esta línea y presiona Enter (ajusta la ruta si tu carpeta está en otro lugar):

```
cd C:\xampp\htdocs\citas_vida_salud
```

3. Luego escribe esta línea y presiona Enter:

```
set DB_NAME=citas_vida_salud_test
```

4. Después escribe esta línea y presiona Enter:

```
C:\xampp\php\php.exe tests\run_tests.php
```

5. Al final debe aparecer: **18 de 18 pruebas pasaron**.

Si aparece otro número, toma una foto de la pantalla y mándamela.

**Cuando termines:** escribe LISTO.

---

## PASO 13: Subir el proyecto a GitHub

1. Entra a **github.com** con tu cuenta.
2. Arriba a la derecha toca el signo **+** y luego **New repository**.
3. En el nombre escribe: `citas_vida_salud`
4. Elige **Public** (para que el profesor pueda verlo).
5. **No marques** ninguna casilla de abajo.
6. Toca **Create repository**.
7. Copia la dirección que aparece en la página (empieza con `https://github.com/`).

Luego sube los archivos. La forma más fácil es con la página de GitHub:

8. En la misma página del repositorio, toca **uploading an existing file**.
9. Arrastra **todos los archivos y carpetas** de la carpeta del proyecto.
10. **Importante:** no subas el archivo **local.php** (el que tiene tu clave). Si aparece en la lista, quítalo antes de subir.
11. Toca **Commit changes**.

**Cuando termines:** escribe LISTO y pega aquí la dirección del repositorio.

---

## PASO 14: Hacer el código QR del repositorio

1. Abre tu navegador y busca **generador de código QR**.
2. Pega la dirección del repositorio de GitHub.
3. Descarga la imagen del código QR.

**Cuando termines:** escribe LISTO.

---

## PASO 15: Entregar

Lo que debes entregar según el documento:
- La **dirección del repositorio de GitHub**.
- El **código QR** de esa dirección.
- El documento que hará tu amigo (eso lo vemos después).

**Cuando termines:** escribe LISTO. Con eso el sistema queda terminado.

---

## Notas

- La nube (AlwaysData o Vercel) **no es obligatoria** para el trabajo. Solo sirve si después quieres un enlace en línea.
- Las contraseñas de demostración (`Vida2026*`) deben cambiarse antes de usar datos reales.
