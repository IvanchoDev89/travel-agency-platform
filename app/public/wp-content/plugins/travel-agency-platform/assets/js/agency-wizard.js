/* TAP agency back-office wizard.
 * Plain JS, no jQuery dependency. Provides:
 *   - toasts, confirm dialogs and busy buttons
 *   - listing / policies / room form submission over ajax
 *   - bed builder, number steppers, accordions and char counters
 *   - the Media Library image uploader (drag & drop, paste, URL fallback)
 *   - explicit publish / unpublish
 * Exposes window.TapWizard so the calendar view reuses the same helpers.
 */
(function (window, document) {
    'use strict';

    var cfg = window.tapAgencyWizard || {};
    var i18n = cfg.i18n || {};

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function on(el, ev, fn) { if (el) { el.addEventListener(ev, fn); } }

    function escapeHtml(value) {
        var d = document.createElement('div');
        d.textContent = value == null ? '' : String(value);
        return d.innerHTML;
    }

    function t(key, fallback) {
        return i18n[key] != null ? i18n[key] : fallback;
    }

    function money(value) {
        var num = parseFloat(value || 0);
        var dec = cfg.decimals != null ? parseInt(cfg.decimals, 10) : 2;
        return (cfg.currency || '') + num.toFixed(dec);
    }

    /* ── toasts ──────────────────────────────────────────────────────── */

    var toastTimer = null;

    function toast(message, type) {
        var el = qs('.tap-toast');
        if (!el) {
            el = document.createElement('div');
            el.className = 'tap-toast';
            document.body.appendChild(el);
        }
        el.className = 'tap-toast' + (type ? ' tap-toast-' + type : '');
        el.textContent = message;
        /* force reflow so the transition replays on repeated messages */
        void el.offsetWidth;
        el.classList.add('show');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            el.classList.remove('show');
        }, type === 'error' ? 5200 : 3200);
    }

    /* ── busy buttons ────────────────────────────────────────────────── */

    function setBusy(button, busy) {
        if (!button) { return; }
        if (busy) {
            if (!button.dataset.busyLabel) {
                button.dataset.busyLabel = button.textContent;
            }
            button.classList.add('is-busy');
            button.disabled = true;
        } else {
            button.classList.remove('is-busy');
            button.disabled = false;
        }
    }

    /* ── confirm dialog ──────────────────────────────────────────────── */

    var openOverlay = null;

    function closeOverlay() {
        if (!openOverlay) { return; }
        if (openOverlay.parentNode) {
            openOverlay.parentNode.removeChild(openOverlay);
        }
        openOverlay = null;
        document.removeEventListener('keydown', onOverlayKey);
    }

    function onOverlayKey(ev) {
        if (ev.key === 'Escape' && openOverlay) {
            ev.preventDefault();
            var cancel = openOverlay.querySelector('[data-modal-cancel]');
            closeOverlay();
            if (cancel) { cancel.focus(); }
        }
    }

    /**
     * Promise based confirm. onConfirm returning false keeps the dialog open,
     * which is what the room delete needs while the ajax call is in flight.
     */
    function confirmDialog(opts) {
        var settings = opts || {};
        return new Promise(function (resolve) {
            closeOverlay();

            var overlay = document.createElement('div');
            overlay.className = 'tap-modal-overlay';
            overlay.innerHTML =
                '<div class="tap-modal" role="dialog" aria-modal="true" aria-labelledby="tap-modal-title">' +
                    '<div class="tap-modal-head">' +
                        '<h3 class="tap-modal-title" id="tap-modal-title">' + escapeHtml(settings.title || t('confirm', 'Confirmar')) + '</h3>' +
                        (settings.subtitle ? '<p class="tap-modal-sub">' + escapeHtml(settings.subtitle) + '</p>' : '') +
                    '</div>' +
                    '<div class="tap-modal-foot">' +
                        '<button type="button" class="tap-btn tap-btn-sm" data-modal-cancel>' + escapeHtml(settings.cancelText || t('cancel', 'Cancelar')) + '</button>' +
                        '<button type="button" class="tap-btn tap-btn-sm ' + (settings.danger ? 'tap-btn-danger' : 'tap-btn-primary') + '" data-modal-ok>' + escapeHtml(settings.okText || t('confirm', 'Confirmar')) + '</button>' +
                    '</div>' +
                '</div>';

            var okBtn = qs('[data-modal-ok]', overlay);
            var cancelBtn = qs('[data-modal-cancel]', overlay);

            on(overlay, 'click', function (ev) {
                if (ev.target === overlay) {
                    closeOverlay();
                    resolve(false);
                }
            });
            on(cancelBtn, 'click', function () {
                closeOverlay();
                resolve(false);
            });
            on(okBtn, 'click', function () {
                var outcome = settings.onConfirm ? settings.onConfirm(okBtn) : true;
                if (outcome && typeof outcome.then === 'function') {
                    outcome.then(function (result) {
                        if (result === false) { return; }
                        closeOverlay();
                        resolve(true);
                    });
                    return;
                }
                if (outcome === false) { return; }
                closeOverlay();
                resolve(true);
            });

            document.body.appendChild(overlay);
            openOverlay = overlay;
            document.addEventListener('keydown', onOverlayKey);
            okBtn.focus();
        });
    }

    /* ── generic form modal ──────────────────────────────────────────── */

    /**
     * Renders a small form inside the shared modal shell.
     * fields: [{name, label, type: text|number|select|date, value, placeholder,
     *           min, max, step, options:[{value,label}], hint}]
     * Resolves with a {name: value} map, or null when dismissed.
     * A field named "__clear" resolves {__clear:true} instead.
     */
    function formModal(opts) {
        var settings = opts || {};
        return new Promise(function (resolve) {
            closeOverlay();

            var fields = settings.fields || [];
            var body = '';
            fields.forEach(function (field) {
                var id = 'tap-fm-' + field.name;
                var control;
                if (field.type === 'select') {
                    control = '<select class="tap-field-input" id="' + escapeHtml(id) + '" name="' + escapeHtml(field.name) + '">';
                    (field.options || []).forEach(function (opt) {
                        control += '<option value="' + escapeHtml(opt.value) + '"' +
                            (String(opt.value) === String(field.value) ? ' selected' : '') + '>' +
                            escapeHtml(opt.label) + '</option>';
                    });
                    control += '</select>';
                } else if (field.type === 'textarea') {
                    control = '<textarea class="tap-field-input" id="' + escapeHtml(id) + '" name="' + escapeHtml(field.name) +
                        '" rows="3" placeholder="' + escapeHtml(field.placeholder || '') + '">' + escapeHtml(field.value || '') + '</textarea>';
                } else {
                    control = '<input type="' + escapeHtml(field.type || 'text') + '" class="tap-field-input" id="' + escapeHtml(id) +
                        '" name="' + escapeHtml(field.name) + '" value="' + escapeHtml(field.value == null ? '' : field.value) + '"' +
                        (field.placeholder ? ' placeholder="' + escapeHtml(field.placeholder) + '"' : '') +
                        (field.min != null ? ' min="' + escapeHtml(field.min) + '"' : '') +
                        (field.max != null ? ' max="' + escapeHtml(field.max) + '"' : '') +
                        (field.step != null ? ' step="' + escapeHtml(field.step) + '"' : '') +
                        (field.inputmode ? ' inputmode="' + escapeHtml(field.inputmode) + '"' : '') +
                        (field.autofocus ? ' autofocus' : '') + '>';
                }
                body += '<div class="tap-field' + (field.wide ? ' tap-span-2' : '') + '">' +
                    '<label for="' + escapeHtml(id) + '">' + escapeHtml(field.label || '') + '</label>' + control +
                    (field.hint ? '<span class="tap-field-hint">' + escapeHtml(field.hint) + '</span>' : '') +
                    '</div>';
            });

            var overlay = document.createElement('div');
            overlay.className = 'tap-modal-overlay';
            overlay.innerHTML =
                '<form class="tap-modal tap-modal-form" role="dialog" aria-modal="true" aria-labelledby="tap-fm-title">' +
                    '<div class="tap-modal-head">' +
                        '<h3 class="tap-modal-title" id="tap-fm-title">' + escapeHtml(settings.title || '') + '</h3>' +
                        (settings.subtitle ? '<p class="tap-modal-sub">' + escapeHtml(settings.subtitle) + '</p>' : '') +
                    '</div>' +
                    '<div class="tap-modal-body"><div class="tap-form-grid">' + body + '</div></div>' +
                    '<p class="tap-form-msg" role="status" aria-live="polite"></p>' +
                    '<div class="tap-modal-foot">' +
                        (settings.extraLabel
                            ? '<button type="button" class="tap-btn tap-btn-sm tap-btn-danger tap-modal-extra">' + escapeHtml(settings.extraLabel) + '</button>'
                            : '') +
                        '<button type="button" class="tap-btn tap-btn-sm" data-modal-cancel>' + escapeHtml(settings.cancelText || t('cancel', 'Cancelar')) + '</button>' +
                        '<button type="submit" class="tap-btn tap-btn-sm ' + (settings.danger ? 'tap-btn-danger' : 'tap-btn-primary') + '">' + escapeHtml(settings.okText || t('save', 'Guardar')) + '</button>' +
                    '</div>' +
                '</form>';

            var form = qs('form', overlay);
            var finish = function (result) {
                closeOverlay();
                resolve(result);
            };

            on(overlay, 'click', function (ev) {
                if (ev.target === overlay) { finish(null); }
            });
            on(qs('[data-modal-cancel]', overlay), 'click', function () { finish(null); });

            var extra = qs('.tap-modal-extra', overlay);
            if (extra) {
                on(extra, 'click', function () { finish({ __clear: true }); });
            }

            on(form, 'submit', function (ev) {
                ev.preventDefault();
                if (!form.reportValidity()) { return; }
                var values = {};
                qsa('[name]', form).forEach(function (el) {
                    values[el.name] = el.value;
                });
                finish(values);
            });

            document.body.appendChild(overlay);
            openOverlay = overlay;
            document.addEventListener('keydown', onOverlayKey);

            var first = qs('.tap-modal-extra', overlay) ? qs('.tap-modal-extra', overlay) : qs('[data-modal-cancel]', overlay);
            if (first) { first.focus(); }
            var auto = qs('[autofocus]', form);
            if (auto) { auto.focus(); }
        });
    }

    /* ── ajax ────────────────────────────────────────────────────────── */

    function post(action, formData, nonce) {
        var fd = formData instanceof FormData ? formData : new FormData();
        fd.append('action', action);
        fd.set('nonce', nonce);
        return window.fetch(cfg.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json();
        });
    }

    function formMessage(form, message, isError) {
        var box = qs('.tap-form-msg', form) || qs('.tap-ac-msg', form);
        if (!box) {
            return;
        }
        box.textContent = message || '';
        box.classList.toggle('is-error', !!isError);
    }

    /* ── bed builder ─────────────────────────────────────────────────── */

    function bedRowHtml(type, count) {
        var options = '';
        Object.keys(cfg.bedTypes || {}).forEach(function (key) {
            options += '<option value="' + escapeHtml(key) + '"' + (key === type ? ' selected' : '') + '>' + escapeHtml(cfg.bedTypes[key]) + '</option>';
        });
        return '<div class="tap-bed-row">' +
            '<select class="tap-bed-type" aria-label="' + escapeHtml(t('bedType', 'Tipo de cama')) + '">' + options + '</select>' +
            '<input type="number" class="tap-bed-count tap-num-input" min="1" step="1" value="' + (parseInt(count, 10) || 1) + '" aria-label="' + escapeHtml(t('bedCount', 'Cantidad')) + '">' +
            '<button type="button" class="tap-bed-remove" aria-label="' + escapeHtml(t('removeBed', 'Quitar cama')) + '">&times;</button>' +
        '</div>';
    }

    function bindBedBuilder(scope) {
        var form = scope.classList && scope.classList.contains('tap-manage-form') ? scope : qs('.tap-manage-form', scope);
        if (!form) { return; }
        var rows = qs('.tap-beds-rows', form);
        var json = qs('.tap-beds-json', form);
        var add = qs('.tap-bed-add', form);
        if (!rows) { return; }

        on(add, 'click', function () {
            rows.insertAdjacentHTML('beforeend', bedRowHtml('double', 1));
            var last = qs('.tap-bed-row:last-child .tap-bed-type', rows);
            if (last) { last.focus(); }
            serialize();
        });

        on(rows, 'click', function (ev) {
            var btn = ev.target.closest ? ev.target.closest('.tap-bed-remove') : null;
            if (btn) {
                btn.closest('.tap-bed-row').remove();
                serialize();
            }
        });

        on(rows, 'change', function () { serialize(); });

        function serialize() {
            var list = [];
            qsa('.tap-bed-row', rows).forEach(function (row) {
                var bedType = qs('.tap-bed-type', row);
                var bedCount = qs('.tap-bed-count', row);
                var amount = parseInt(bedCount ? bedCount.value : 0, 10) || 0;
                if (bedType && amount > 0) {
                    list.push({ type: bedType.value, count: amount });
                }
            });
            if (json) { json.value = JSON.stringify(list); }
            form.dispatchEvent(new CustomEvent('tap:beds', { bubbles: true, detail: list }));
        }

        on(form, 'submit', serialize);
        serialize();
    }

    /* ── image uploader ──────────────────────────────────────────────── */

    function initUploader(root) {
        if (!root || root.dataset.tapReady === '1') { return; }
        root.dataset.tapReady = '1';

        var grid = qs('.tap-uploader-grid', root);
        var drop = qs('.tap-uploader-drop', root);
        var fileInput = qs('input[type="file"]', root);
        var status = qs('.tap-uploader-progress', root);
        var mainInput = qs('[data-role="main-id"]', root);
        var galleryInput = qs('[data-role="gallery-ids"]', root);
        var mainUrl = qs('[data-role="main-url"]', root);
        var galleryUrls = qs('[data-role="gallery-urls"]', root);
        var urlWrap = qs('.tap-uploader-url-wrap', root);
        var urlInput = qs('.tap-uploader-url', root);
        var urlToggle = qs('.tap-uploader-url-toggle', root);
        var urlAdd = qs('.tap-uploader-url-add', root);
        var postId = root.dataset.postId || '0';
        var parentId = root.dataset.parentId || postId;
        var postType = root.dataset.postType || '';
        var maxBytes = parseInt(root.dataset.maxBytes || cfg.maxUploadBytes || 0, 10);
        var items = [];

        try {
            items = JSON.parse(root.dataset.initial || '[]');
        } catch (err) {
            items = [];
        }
        if (!Array.isArray(items)) { items = []; }

        function setStatus(message, isError) {
            if (!status) { return; }
            status.textContent = message || '';
            status.classList.toggle('is-error', !!isError);
        }

        function syncInputs() {
            var ids = items.filter(function (item) { return item.id; });
            var urls = items.filter(function (item) { return item.url; });
            var nameMain = mainInput ? mainInput.dataset.name : '';
            var nameGallery = galleryInput ? galleryInput.dataset.name : '';
            var nameMainUrl = mainUrl ? mainUrl.dataset.name : '';
            var nameGalleryUrls = galleryUrls ? galleryUrls.dataset.name : '';

            if (ids.length) {
                if (mainInput) {
                    mainInput.value = ids[0].id;
                    mainInput.setAttribute('name', nameMain);
                }
                if (galleryInput) {
                    galleryInput.value = ids.slice(1).map(function (item) { return item.id; }).join(',');
                    galleryInput.setAttribute('name', nameGallery);
                }
                if (mainUrl) { mainUrl.value = ''; mainUrl.removeAttribute('name'); }
                if (galleryUrls) { galleryUrls.value = ''; galleryUrls.removeAttribute('name'); }
            } else if (urls.length) {
                if (mainUrl) {
                    mainUrl.value = urls[0].url;
                    mainUrl.setAttribute('name', nameMainUrl);
                }
                if (galleryUrls) {
                    galleryUrls.value = urls.slice(1).map(function (item) { return item.url; }).join('\n');
                    galleryUrls.setAttribute('name', nameGalleryUrls);
                }
                if (mainInput) { mainInput.value = ''; mainInput.removeAttribute('name'); }
                if (galleryInput) { galleryInput.value = ''; galleryInput.removeAttribute('name'); }
            } else {
                [mainInput, galleryInput, mainUrl, galleryUrls].forEach(function (el) {
                    if (el) {
                        el.value = '';
                        el.removeAttribute('name');
                    }
                });
            }
        }

        function render() {
            if (!grid) { return; }
            grid.innerHTML = '';
            items.forEach(function (item, index) {
                var figure = document.createElement('figure');
                figure.className = 'tap-uploader-item' + (index === 0 ? ' is-main' : '');
                figure.innerHTML =
                    '<img src="' + escapeHtml(item.thumb || item.url) + '" alt="" loading="lazy">' +
                    (index === 0 ? '<span class="tap-uploader-main-flag">' + escapeHtml(t('main', 'Principal')) + '</span>' : '') +
                    '<figcaption class="tap-uploader-item-tools">' +
                        '<button type="button" class="tap-uploader-main" title="' + escapeHtml(t('setMain', 'Usar como foto principal')) + '" aria-label="' + escapeHtml(t('setMain', 'Usar como foto principal')) + '">&#9733;</button>' +
                        '<button type="button" class="tap-uploader-del" title="' + escapeHtml(t('removeImage', 'Quitar imagen')) + '" aria-label="' + escapeHtml(t('removeImage', 'Quitar imagen')) + '">&times;</button>' +
                    '</figcaption>';
                on(qs('.tap-uploader-main', figure), 'click', function () {
                    if (index === 0) { return; }
                    var picked = items.splice(index, 1)[0];
                    items.unshift(picked);
                    syncInputs();
                    render();
                });
                on(qs('.tap-uploader-del', figure), 'click', function () {
                    items.splice(index, 1);
                    syncInputs();
                    render();
                    setStatus('');
                });
                grid.appendChild(figure);
            });
            syncInputs();
        }

        function fail(message) {
            setStatus(message, true);
            toast(message, 'error');
        }

        function uploadOne(file) {
            if (!/^image\//i.test(file.type || '')) {
                fail(file.name + ': ' + t('badType', 'solo se permiten imágenes.'));
                return Promise.resolve(false);
            }
            if (maxBytes && file.size > maxBytes) {
                fail(file.name + ': ' + t('tooBig', 'la imagen supera el tamaño máximo.'));
                return Promise.resolve(false);
            }
            var fd = new FormData();
            fd.append('file', file);
            fd.append('action', 'tap_agency_upload_image');
            fd.append('nonce', cfg.uploadNonce || '');
            fd.append('listing_id', postId);
            if (postType) { fd.append('listing_type', postType); }
            if (parentId && parentId !== postId) { fd.append('parent_id', parentId); }

            return new Promise(function (resolve) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', cfg.ajaxUrl, true);
                xhr.upload.addEventListener('progress', function (ev) {
                    if (!ev.lengthComputable) { return; }
                    setStatus(t('uploading', 'Subiendo') + ' ' + file.name + ' ' + Math.round((ev.loaded / ev.total) * 100) + '%');
                });
                xhr.onload = function () {
                    var payload = null;
                    try { payload = JSON.parse(xhr.responseText); } catch (err) { payload = null; }
                    if (!payload || !payload.success) {
                        fail((payload && payload.data && payload.data.message) || t('uploadFailed', 'No se pudo subir la imagen.'));
                        resolve(false);
                        return;
                    }
                    items.push({
                        id: payload.data.attachment_id,
                        url: payload.data.url,
                        thumb: payload.data.thumb || payload.data.url
                    });
                    render();
                    resolve(true);
                };
                xhr.onerror = function () {
                    fail(t('networkError', 'Error de conexión.'));
                    resolve(false);
                };
                xhr.send(fd);
            });
        }

        function uploadQueue(files) {
            var list = Array.prototype.slice.call(files || []);
            if (!list.length) { return; }
            var total = list.length;
            var done = 0;
            var ok = 0;
            var chain = Promise.resolve();
            list.forEach(function (file) {
                chain = chain.then(function () {
                    return uploadOne(file).then(function (success) {
                        done += 1;
                        if (!success) { return; }
                        ok += 1;
                        setStatus(t('uploadingDone', 'Subiendo') + ' ' + done + '/' + total);
                    });
                });
            });
            chain.then(function () {
                if (!ok) { return; }
                setStatus(t('uploadedCount', '') + ' ' + ok + '/' + total);
                window.setTimeout(function () {
                    if (status && status.textContent.indexOf('/' + total) > -1) {
                        setStatus('');
                    }
                }, 4000);
                toast(ok === 1 ? t('uploadedOne', 'Imagen subida.') : t('uploaded', 'Imágenes subidas.'), 'success');
            });
        }

        on(fileInput, 'change', function () {
            uploadQueue(fileInput.files);
            fileInput.value = '';
        });

        if (drop) {
            on(drop, 'click', function () { if (fileInput) { fileInput.click(); } });
            on(drop, 'keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    if (fileInput) { fileInput.click(); }
                }
            });
            ['dragenter', 'dragover'].forEach(function (type) {
                on(drop, type, function (ev) {
                    ev.preventDefault();
                    drop.classList.add('is-over');
                });
            });
            ['dragleave', 'drop'].forEach(function (type) {
                on(drop, type, function (ev) {
                    ev.preventDefault();
                    drop.classList.remove('is-over');
                });
            });
            on(drop, 'drop', function (ev) {
                uploadQueue(ev.dataTransfer && ev.dataTransfer.files);
            });
            on(drop, 'paste', function (ev) {
                var clip = ev.clipboardData;
                if (!clip) { return; }
                uploadQueue(clip.files);
            });
        }

        on(urlToggle, 'click', function () {
            if (!urlWrap) { return; }
            var open = urlWrap.classList.toggle('is-open');
            if (open && urlInput) { urlInput.focus(); }
        });

        function addUrl() {
            var value = urlInput ? urlInput.value.trim() : '';
            if (!value) { return; }
            items.push({ id: 0, url: value, thumb: value });
            if (urlInput) { urlInput.value = ''; }
            render();
            toast(t('added', 'Imagen añadida.'), 'success');
        }

        on(urlAdd, 'click', addUrl);
        on(urlInput, 'keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                addUrl();
            }
        });

        render();
    }

    /* ── accordions, steppers, counters ──────────────────────────────── */

    function initGroups() {
        qsa('.tap-group-head').forEach(function (head) {
            on(head, 'click', function (ev) {
                /* Ignore clicks on nested controls, but not on the head itself
                   (the head is a button, so closest() would always match). */
                var inner = ev.target !== head && ev.target.closest
                    ? ev.target.closest('a, button, input, select, textarea')
                    : null;
                if (inner && inner !== head) { return; }
                var group = head.closest('.tap-group');
                if (!group) { return; }
                var open = group.classList.toggle('is-open');
                head.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        });
    }

    /**
     * Existing units collapse to their summary card so the page stays readable;
     * the "add unit" form only appears when it is asked for.
     */
    function initUnitCards() {
        var forms = qsa('.tap-room-form[data-room]:not([data-new])');
        var collapse = forms.length > 1;
        forms.forEach(function (form) {
            var body = qs('.tap-unit-card-body', form);
            var toggle = qs('.tap-unit-toggle', form);
            if (!body || !toggle) { return; }
            var setOpen = function (open) {
                body.hidden = !open;
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
            if (collapse) { setOpen(false); }
            on(toggle, 'click', function () {
                setOpen(body.hidden);
            });
        });

        qsa('[data-role="show-new-room"]').forEach(function (btn) {
            on(btn, 'click', function () {
                var target = document.getElementById(btn.getAttribute('aria-controls') || '');
                if (!target) { return; }
                target.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                var first = qs('input[name="title"]', target);
                if (first) { first.focus(); }
            });
        });
    }

    function initSteppers() {
        qsa('.tap-number-row').forEach(function (row) {
            var input = qs('input', row);
            if (!input) { return; }
            qsa('[data-step]', row).forEach(function (btn) {
                on(btn, 'click', function () {
                    var step = parseFloat(btn.dataset.step) || 1;
                    var min = input.min === '' ? -Infinity : parseFloat(input.min);
                    var max = input.max === '' ? Infinity : parseFloat(input.max);
                    var next = (parseFloat(input.value) || min || 0) + step;
                    if (next < min) { next = min; }
                    if (next > max) { next = max; }
                    input.value = next;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
        });
    }

    function initCounters() {
        qsa('[data-count-for]').forEach(function (counter) {
            var input = document.getElementById(counter.dataset.countFor) || qs('[name="' + counter.dataset.countFor + '"]');
            if (!input) { return; }
            var max = parseInt(counter.dataset.countMax || input.maxLength || 0, 10);
            var update = function () {
                var used = (input.value || '').length;
                counter.textContent = max ? used + '/' + max : String(used);
                counter.classList.toggle('is-near', !!max && used > max * 0.9);
            };
            on(input, 'input', update);
            update();
        });
    }

    /* ── forms ───────────────────────────────────────────────────────── */

    /**
     * The wizard footer lives outside the form and submits it through the
     * `form` attribute, so the busy state has to find that button too.
     */
    function formSubmitter(form, submitter) {
        if (submitter && submitter.form === form) { return submitter; }
        var inner = qs('[type="submit"]', form);
        if (inner) { return inner; }
        if (!form.id) { return null; }
        return qs('button[form="' + form.id + '"][type="submit"], input[form="' + form.id + '"][type="submit"]');
    }

    function submitListing(form, submitter) {
        var button = formSubmitter(form, submitter);
        setBusy(button, true);
        formMessage(form, '');
        return post('tap_agency_save_listing', new FormData(form), cfg.listingNonce)
            .then(function (json) {
                setBusy(button, false);
                if (!json || !json.success) {
                    var message = (json && json.data && json.data.message) || t('error', 'Error');
                    formMessage(form, message, true);
                    toast(message, 'error');
                    return false;
                }
                toast(json.data.message || t('saved', 'Guardado.'), 'success');
                var target = cfg.isEdit ? cfg.stepUrl(2) : (json.data.edit_url || '') + '&step=2';
                window.location.href = target;
                return true;
            })
            .catch(function () {
                setBusy(button, false);
                formMessage(form, t('networkError', 'Error de conexión.'), true);
                toast(t('networkError', 'Error de conexión.'), 'error');
                return false;
            });
    }

    function submitPolicies(form, submitter) {
        var button = formSubmitter(form, submitter);
        setBusy(button, true);
        formMessage(form, '');
        return post('tap_agency_save_listing', new FormData(form), cfg.listingNonce)
            .then(function (json) {
                setBusy(button, false);
                if (!json || !json.success) {
                    var message = (json && json.data && json.data.message) || t('error', 'Error');
                    formMessage(form, message, true);
                    toast(message, 'error');
                    return false;
                }
                toast(json.data.message || t('saved', 'Guardado.'), 'success');
                window.location.href = cfg.stepUrl(5);
                return true;
            })
            .catch(function () {
                setBusy(button, false);
                formMessage(form, t('networkError', 'Error de conexión.'), true);
                return false;
            });
    }

    function submitRoom(form, backToStep, submitter) {
        var button = formSubmitter(form, submitter);
        setBusy(button, true);
        formMessage(form, '');
        return post('tap_agency_save_room', new FormData(form), cfg.listingNonce)
            .then(function (json) {
                setBusy(button, false);
                if (!json || !json.success) {
                    var message = (json && json.data && json.data.message) || t('error', 'Error');
                    formMessage(form, message, true);
                    toast(message, 'error');
                    return false;
                }
                toast(json.data.message || t('saved', 'Guardado.'), 'success');
                window.location.href = cfg.stepUrl(backToStep);
                return true;
            })
            .catch(function () {
                setBusy(button, false);
                formMessage(form, t('networkError', 'Error de conexión.'), true);
                toast(t('networkError', 'Error de conexión.'), 'error');
                return false;
            });
    }

    function deleteRoom(form) {
        var roomId = form.dataset.room;
        confirmDialog({
            title: t('deleteRoom', 'Eliminar unidad'),
            subtitle: t('deleteRoomSub', 'Se borrará la unidad y sus precios. Esta acción no se puede deshacer.'),
            okText: t('delete', 'Eliminar'),
            danger: true,
            onConfirm: function (okBtn) {
                setBusy(okBtn, true);
                var fd = new FormData();
                fd.append('room_id', roomId);
                return post('tap_agency_delete_room', fd, cfg.listingNonce).then(function (json) {
                    setBusy(okBtn, false);
                    if (!json || !json.success) {
                        toast((json && json.data && json.data.message) || t('error', 'Error'), 'error');
                        return false;
                    }
                    toast(t('deleted', 'Unidad eliminada.'), 'success');
                    window.location.href = cfg.stepUrl(2);
                    return true;
                }).catch(function () {
                    setBusy(okBtn, false);
                    toast(t('networkError', 'Error de conexión.'), 'error');
                    return false;
                });
            }
        });
    }

    function togglePublish(button) {
        var publish = button.dataset.publish === '1';
        confirmDialog({
            title: publish ? t('publish', 'Publicar alojamiento') : t('unpublish', 'Despublicar alojamiento'),
            subtitle: publish ? t('publishSub', 'Pasará a ser visible para los viajeros.') : t('unpublishSub', 'Dejará de aparecer en el buscador hasta que lo publiques de nuevo.'),
            okText: publish ? t('publish', 'Publicar') : t('unpublish', 'Despublicar'),
            danger: !publish,
            onConfirm: function (okBtn) {
                setBusy(okBtn, true);
                var fd = new FormData();
                fd.append('listing_id', cfg.listingId);
                fd.append('listing_type', cfg.listingType || 'tap_accommodation');
                fd.append('publish', publish ? '1' : '0');
                return post('tap_agency_toggle_publish', fd, cfg.listingNonce).then(function (json) {
                    setBusy(okBtn, false);
                    if (!json || !json.success) {
                        toast((json && json.data && json.data.message) || t('error', 'Error'), 'error');
                        return false;
                    }
                    toast(json.data.message || t('saved', 'Guardado.'), 'success');
                    window.setTimeout(function () { window.location.reload(); }, 900);
                    return true;
                }).catch(function () {
                    setBusy(okBtn, false);
                    toast(t('networkError', 'Error de conexión.'), 'error');
                    return false;
                });
            }
        });
    }

    /* ── boot ────────────────────────────────────────────────────────── */

    function init() {
        var listing = qs('#tap-listing-form');
        if (listing) {
            on(listing, 'submit', function (ev) {
                ev.preventDefault();
                submitListing(listing, ev.submitter);
            });
        }

        var policies = qs('#tap-policies-form');
        if (policies) {
            on(policies, 'submit', function (ev) {
                ev.preventDefault();
                submitPolicies(policies, ev.submitter);
            });
        }

        qsa('.tap-room-form').forEach(function (form) {
            bindBedBuilder(form);
            on(form, 'submit', function (ev) {
                ev.preventDefault();
                submitRoom(form, 2, ev.submitter);
            });
            var del = qs('.tap-delete-room', form);
            if (del) {
                on(del, 'click', function () {
                    deleteRoom(form);
                });
            }
        });

        qsa('[data-publish]').forEach(function (button) {
            on(button, 'click', function () {
                togglePublish(button);
            });
        });

        qsa('.tap-uploader').forEach(initUploader);
        initGroups();
        initUnitCards();
        initSteppers();
        initCounters();
    }

    window.TapWizard = {
        toast: toast,
        confirm: confirmDialog,
        formModal: formModal,
        money: money,
        escapeHtml: escapeHtml,
        setBusy: setBusy,
        t: t
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
