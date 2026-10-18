/**
 * Branded wp-login.php — background video niceties.
 * Honors the user's reduced-motion preference and keeps the poster when
 * autoplay is blocked by the browser.
 */
(function () {
    'use strict';

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
