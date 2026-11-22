/**
 * Branded wp-login.php — background video niceties.
 * Honors the user's reduced-motion preference and keeps the poster when
 * autoplay is blocked by the browser.
 */
(function () {
    'use strict';

    // Focus the username field on arrival so typing can start immediately.
    var user = document.getElementById('user_login');
    if (user && !user.value) {
        try { user.focus(); } catch (e) {}
    }

    // Give feedback on submit and prevent double submissions. If the page
    // reloads with a validation error, restore the button after a short grace.
    var form = document.getElementById('loginform');
    var submit = document.getElementById('wp-submit');
    if (form && submit) {
        form.addEventListener('submit', function () {
            if (submit.disabled) { return; }
            var original = submit.value;
            submit.disabled = true;
            submit.value = 'Ingresando…';
            window.setTimeout(function () {
                if (submit.disabled) {
                    submit.disabled = false;
                    submit.value = original;
                }
            }, 8000);
        });
    }

    var video = document.querySelector('.tap-login-video');
    if (!video) {
        return;
    }

    var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) {
        video.removeAttribute('autoplay');
        try { video.pause(); } catch (e) {}
        return;
    }

    var play = video.play();
    if (play && typeof play.catch === 'function') {
        play.catch(function () {
            /* Autoplay blocked: the poster/background image stays visible. */
        });
    }
})();
