# Tema Metronic (assets/metronic/) — NO está en el repo

El portal usa **Metronic v9 (Tailwind CSS v4, demo1)** de **KeenThemes**, un tema de
**licencia comercial**. Por eso `assets/metronic/` está en `.gitignore` y **no se
versiona** (evita redistribuir el producto en un repo público).

La app necesita estos archivos para verse bien:

```
assets/metronic/
  css/styles.css                      # compilado con Tailwind (ver más abajo)
  js/core.bundle.js
  js/layouts/demo1.js
  vendors/ktui/ktui.min.js
  vendors/keenicons/…                 # styles.bundle.css + fuentes
```

## Cómo generarlos (dev local y build de la imagen)

Requiere el paquete Metronic v9 descargado (licencia propia). Desde
`metronic-tailwind-html-demos/`:

1. **Copiar los assets estáticos** al proyecto:
   ```bash
   SRC=".../metronic-tailwind-html-demos/dist/assets"
   DST="<portal>/assets/metronic"
   mkdir -p "$DST/css" "$DST/js/layouts" "$DST/vendors"
   cp -R "$SRC/vendors/keenicons" "$DST/vendors/keenicons"
   cp -R "$SRC/vendors/ktui"      "$DST/vendors/ktui"
   cp "$SRC/js/core.bundle.js"        "$DST/js/core.bundle.js"
   cp "$SRC/js/layouts/demo1.js"      "$DST/js/layouts/demo1.js"
   ```

2. **Compilar el CSS** (escanea el markup del portal; entry `src/css/portal.css`
   con `@source` al portal + override de marca `--primary:#005ab2` + Montserrat):
   ```bash
   npx @tailwindcss/cli -i ./src/css/portal.css \
       -o "<portal>/assets/metronic/css/styles.css" --minify
   ```
   (Si truena con `@tailwindcss/oxide`: `npm i --no-save @tailwindcss/oxide-<plataforma>`.)

## Deploy (Cloud Run / Docker)

Como `assets/metronic/` ya **no viene en el repo**, el `Dockerfile` no lo trae. Antes
de `docker build`, hay que **colocar `assets/metronic/`** en el contexto de build (p. ej.
copiándolo desde un almacén privado o generándolo con los pasos de arriba en el CI).
El resto del portal sirve el CSS/JS de forma estática, sin build en el contenedor.
