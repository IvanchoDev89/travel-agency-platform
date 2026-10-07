/* Pexels search admin screen for the landing hero. Plain JS, no jQuery. */
(function (window, document) {
    'use strict';

    var cfg = window.tapPexels || {};
    var i18n = cfg.i18n || {};

    function t(key, fallback) { return i18n[key] != null ? i18n[key] : fallback; }
    function qs(sel, root) { return (root || document).querySelector(sel); }

    var form      = qs('#tap-pexels-form');
    var results   = qs('#tap-pexels-results');
    var msg       = qs('#tap-pexels-msg');
    var goBtn     = qs('#tap-pexels-go');

    function setMsg(text, isError) {
        if (!msg) { return; }
        msg.textContent = text || '';
        msg.classList.toggle('is-error', !!isError);
    }

    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce || '');
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return window.fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (res) { return res.json(); });
    }

    function fmtDuration(sec) {
        sec = parseInt(sec, 10) || 0;
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function busy(button, isBusy) {
        if (!button) { return; }
        if (isBusy) {
            button.disabled = true;
            button.dataset.label = button.textContent;
            button.textContent = t('saving', 'Guardando…');
        } else {
            button.disabled = false;
            button.textContent = button.dataset.label || button.textContent;
        }
    }

    function cardVideo(v) {
        var fig = document.createElement('figure');
        fig.className = 'tap-pexels-card';
        fig.innerHTML =
            '<img loading="lazy" alt="" src="' + v.image + '">' +
            '<figcaption>' +
                '<span class="tap-pexels-meta">' + fmtDuration(v.duration) + ' · ' + v.width + '×' + v.height + '</span>' +
                '<span class="tap-pexels-actions">' +
                    '<button type="button" class="button button-primary tap-pexels-use">' + t('use', 'Usar como hero') + '</button>' +
                    '<a class="button" target="_blank" rel="noopener" href="' + (v.files[0] ? v.files[0].link : v.url) + '">' + (v.files[0] ? 'MP4' : 'Ver') + '</a>' +
                '</span>' +
            '</figcaption>';
        var useBtn = qs('.tap-pexels-use', fig);
        if (useBtn) {
            useBtn.addEventListener('click', function () {
                busy(useBtn, true);
                setMsg(t('saving', 'Guardando…'));
                post('tap_pexels_use_video', {
                    video_id: v.id,
                    quality: qs('#tap-pexels-quality').value,
                    download: qs('#tap-pexels-download').checked ? '1' : ''
                }).then(function (json) {
                    busy(useBtn, false);
                    if (!json || !json.success) {
                        setMsg((json && json.data && json.data.message) || t('error', 'Algo salió mal.'), true);
                        return;
                    }
                    setMsg(json.data.message + (json.data.note ? ' ' + json.data.note : ''));
                    if (json.data.customizer) {
                        var link = document.createElement('a');
                        link.className = 'button button-secondary';
                        link.href = json.data.customizer;
                        link.textContent = 'Personalizar →';
                        setMsg(json.data.message);
                        msg.appendChild(document.createTextNode(' '));
                        msg.appendChild(link);
                    }
                }).catch(function () {
                    busy(useBtn, false);
                    setMsg(t('error', 'Algo salió mal.'), true);
                });
            });
        }
        return fig;
    }

    function cardPhoto(p) {
        var fig = document.createElement('figure');
        fig.className = 'tap-pexels-card';
        fig.innerHTML =
            '<img loading="lazy" alt="' + (p.alt || '') + '" src="' + p.image + '">' +
            '<figcaption>' +
                '<span class="tap-pexels-meta">' + p.width + '×' + p.height + '</span>' +
                '<span class="tap-pexels-actions">' +
                    '<button type="button" class="button button-primary tap-pexels-use">' + t('save', 'Guardar en medios') + '</button>' +
                    '<button type="button" class="button tap-pexels-copy">' + t('copy', 'Copiar URL') + '</button>' +
                    '<a class="button" target="_blank" rel="noopener" href="' + p.url + '">Ver</a>' +
                '</span>' +
            '</figcaption>';

        var savedUrl = '';
        var useBtn   = qs('.tap-pexels-use', fig);
        var copyBtn  = qs('.tap-pexels-copy', fig);

        if (useBtn) {
            useBtn.addEventListener('click', function () {
                busy(useBtn, true);
                setMsg(t('saving', 'Guardando…'));
                post('tap_pexels_use_photo', { photo_id: p.id, photo_url: p.download || p.image })
                    .then(function (json) {
                        busy(useBtn, false);
                        if (!json || !json.success) {
                            setMsg((json && json.data && json.data.message) || t('error', 'Algo salió mal.'), true);
                            return;
                        }
                        savedUrl = json.data.url || '';
                        setMsg(json.data.message + ' ' + savedUrl);
                    }).catch(function () {
                        busy(useBtn, false);
                        setMsg(t('error', 'Algo salió mal.'), true);
                    });
            });
        }
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var value = savedUrl || (p.download || p.image);
                if (navigator.clipboard && value) {
                    navigator.clipboard.writeText(value).then(function () {
                        setMsg(t('copied', 'URL copiada.'));
                    });
                } else if (value) {
                    window.prompt('URL:', value);
                }
            });
        }
        return fig;
    }

    if (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var type = qs('#tap-pexels-type').value;
            var q    = qs('#tap-pexels-query').value.trim();
            if (!q) { setMsg(t('error', 'Escribí qué querés buscar.'), true); return; }

            results.innerHTML = '';
            setMsg(t('searching', 'Buscando…'));
            goBtn.disabled = true;

            post('tap_pexels_search', {
                type: type,
                query: q,
                orientation: qs('#tap-pexels-orientation').value
            }).then(function (json) {
                goBtn.disabled = false;
                if (!json || !json.success) {
                    setMsg((json && json.data && json.data.message) || t('error', 'Algo salió mal.'), true);
                    return;
                }
                var list = json.data.results || [];
                if (!list.length) {
                    setMsg(t('empty', 'No se encontraron resultados.'), true);
                    return;
                }
                setMsg(list.length + (type === 'photos' ? ' fotos' : ' videos'));
                list.forEach(function (item) {
                    results.appendChild(type === 'photos' ? cardPhoto(item) : cardVideo(item));
                });
            }).catch(function () {
                goBtn.disabled = false;
                setMsg(t('error', 'Algo salió mal.'), true);
            });
        });
    }
})(window, document);
