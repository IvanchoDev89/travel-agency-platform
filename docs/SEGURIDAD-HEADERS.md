# Headers de seguridad recomendados (Fase 5)

Estos headers se configuran a nivel de servidor (Apache/Nginx/Cloudflare),
no dentro del plugin. Copia el bloque correspondiente a tu entorno.

## Apache (.htaccess)

```apache
<IfModule mod_headers.c>
    # Evita clickjacking
    Header always set X-Frame-Options "SAMEORIGIN"
    # Evita MIME sniffing
    Header always set X-Content-Type-Options "nosniff"
    # Referrer policy
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    # Permissions policy (deshabilita APIs sensibles)
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=(), payment=(self)"
    # HSTS (solo si el sitio es 100% HTTPS)
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
    # Content Security Policy (ajustar según assets propios/CDN)
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self' https:; frame-ancestors 'self';"
</IfModule>
```

## Nginx (server block)

```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=(), payment=(self)" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; connect-src 'self' https:; frame-ancestors 'self';" always;
```

## Cloudflare (Transform Rules / Page Rules)
- Agregar los mismos headers vía "Modify Response Header" rules en el dashboard.

## Notas
- Ajustar `script-src`/`style-src` según los CDN que uses (Leaflet, Chart.js, etc.).
- Probar en modo report-only primero (`Content-Security-Policy-Report-Only`) para no romper el sitio.
- HSTS solo activar si TODO el tráfico es HTTPS (incluidos subdominios).
- El plugin no emite estos headers por sí solo porque dependen del servidor.
PY