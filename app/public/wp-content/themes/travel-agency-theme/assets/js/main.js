(function($) {
    'use strict';

    var header = $('#site-header');
    var mobileToggle = $('#mobile-toggle');
    var headerCenter = $('.header-center');
    var navMenu = headerCenter.find('.nav-menu').first();
    var moreMenu = $('#nav-more-menu');
    var moreDropdown = $('#nav-more-dropdown');
    var moreToggle = $('.nav-more-toggle');
    var mobileOverlay = $('#mobile-overlay');
    var searchToggle = $('#nav-search-toggle');
    var searchOverlay = $('#nav-search-overlay');
    var searchInput = $('#nav-search-input');
    var searchClose = $('#nav-search-close');
    var userBtn = $('#nav-user-btn');
    var userDropdown = $('#nav-user-dropdown');
    var isTouch = 'ontouchstart' in window;
    var BREAKPOINT = 769;
    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (isTouch) {
        header.attr('data-touch', 'true');
    }

    mobileToggle.attr('aria-controls', 'main-nav');

    /* ===== Mobile menu ===== */
    function closeMobileMenu() {
        headerCenter.removeClass('open');
        mobileToggle.removeClass('active').attr('aria-expanded', 'false');
        mobileOverlay.removeClass('open').attr('aria-hidden', 'true');
        $('body').css('overflow', '');
        mobileToggle.trigger('focus');
    }

    function openMobileMenu() {
        headerCenter.addClass('open');
        mobileToggle.addClass('active').attr('aria-expanded', 'true');
        mobileOverlay.addClass('open').attr('aria-hidden', 'false');
        $('body').css('overflow', 'hidden');
        var first = headerCenter.find('.nav-menu a:visible, .submenu-toggle:visible').first();
        if (first.length) first.trigger('focus');
    }

    mobileToggle.on('click', function(e) {
        e.stopPropagation();
        headerCenter.hasClass('open') ? closeMobileMenu() : openMobileMenu();
    });

    mobileOverlay.on('click', closeMobileMenu);

    /* ===== Submenu accordion (mobile) ===== */
    headerCenter.on('click', '.submenu-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var $parent = $btn.closest('.menu-item-has-children');
        var $submenu = $parent.find('> .sub-menu');

        $btn.toggleClass('active');
        $submenu.stop(true, true).slideToggle(250);
    });

    /* ===== Desktop click-to-toggle submenus (touch fallback) ===== */
    headerCenter.on('click', '.nav-menu > .menu-item-has-children > a.nav-link', function(e) {
        if ($(window).width() < BREAKPOINT) return;
        var $link = $(this);
        var $parent = $link.parent();
        var $submenu = $parent.find('> .sub-menu');

        if (!$submenu.length) return;

        if (isTouch) {
            var expanded = $link.attr('aria-expanded') === 'true';
            if (!expanded) {
                e.preventDefault();
                $('.nav-menu > li > a.nav-link').attr('aria-expanded', 'false');
                $('.nav-menu > .menu-item-has-children > .sub-menu').removeClass('open');
                $link.attr('aria-expanded', 'true');
                $submenu.addClass('open');
            }
            return;
        }

        if ($(window).width() >= BREAKPOINT) return;
    });

    /* ===== Keyboard nav ===== */
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape') {
            if (headerCenter.hasClass('open')) closeMobileMenu();
            if (searchOverlay.hasClass('open')) closeSearch();
            if (userDropdown.hasClass('open')) closeUserDropdown();
        }
    });

    /* ===== User dropdown ===== */
    function closeUserDropdown() {
        userDropdown.removeClass('open');
        userBtn.attr('aria-expanded', 'false');
    }

    userBtn.on('click', function(e) {
        e.stopPropagation();
        if ($(window).width() < BREAKPOINT) return;
        userDropdown.toggleClass('open');
        userBtn.attr('aria-expanded', userDropdown.hasClass('open'));
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.nav-user-dropdown-wrapper').length) {
            closeUserDropdown();
        }
    });

    /* ===== Search ===== */
    function closeSearch() {
        searchOverlay.removeClass('open');
        searchInput.val('');
    }

    searchToggle.on('click', function() {
        if ($(window).width() < BREAKPOINT) return;
        searchOverlay.addClass('open');
        setTimeout(function() { searchInput.trigger('focus'); }, 120);
    });

    searchClose.on('click', closeSearch);

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#nav-search-toggle, #nav-search-overlay').length) {
            if (searchOverlay.hasClass('open')) closeSearch();
        }
    });

    /* ===== Scroll effects ===== */
    var lastScroll = 0;
    $(window).on('scroll', function() {
        var st = $(this).scrollTop();
        if (st > 20) {
            header.addClass('scrolled');
        } else {
            header.removeClass('scrolled');
        }
        lastScroll = st;
    });

    /* ===== Overflow detection: move items to "More" ===== */
    function manageOverflow() {
        var w = $(window).width();
        if (w < BREAKPOINT) {
            moreMenu.attr('hidden', '');
            moveAllBack();
            return;
        }

        var container = header.find('.header-inner');
        var avail = container.width();
        var used = 0;

        /* Brand */
        var brand = container.find('.site-branding');
        used += brand.outerWidth(true) || 0;

        /* Center area: we calc space for nav + actions */
        var center = container.find('.header-center');
        var centerW = center.outerWidth(true) || 0;
        var actionsW = center.find('.nav-actions').outerWidth(true) || 0;
        var moreW = moreMenu.outerWidth(true) || 0;
        var padding = 32;

        var navAvail = avail - used - actionsW - moreW - padding;
        var navContainer = center.find('.main-navigation .nav-menu').first();
        var items = navContainer.children('li').not('#nav-more-menu');
        var totalItemsW = 0;
        var visibleCount = 0;

        items.each(function() {
            totalItemsW += $(this).outerWidth(true) || 0;
        });

        if (totalItemsW <= navAvail) {
            moreMenu.attr('hidden', '');
            moveAllBack();
            return;
        }

        moreMenu.removeAttr('hidden');
        moreDropdown.empty();
        var runningW = 0;

        items.each(function() {
            var itemW = $(this).outerWidth(true) || 0;
            if (runningW + itemW <= navAvail) {
                runningW += itemW;
                visibleCount++;
            } else {
                var clone = $(this).clone(true, true);
                clone.find('.sub-menu').removeClass('open').css('display', '');
                moreDropdown.append(clone);
                $(this).detach();
            }
        });

        if (!moreDropdown.children().length) {
            moreMenu.attr('hidden', '');
        }
    }

    function moveAllBack() {
        if (!moreDropdown.children().length) return;
        var navContainer = headerCenter.find('.main-navigation .nav-menu').first();
        moreDropdown.children().each(function() {
            $(this).insertBefore(moreMenu);
        });
    }

    /* ===== More menu toggle on desktop ===== */
    moreToggle.on('click', function(e) {
        e.preventDefault();
        if ($(window).width() < BREAKPOINT) return;
        var expanded = $(this).attr('aria-expanded') === 'true';
        $(this).attr('aria-expanded', expanded ? 'false' : 'true');
        moreDropdown.toggleClass('open');
    });

    /* Close more dropdown on click outside */
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.nav-more-toggle-wrapper').length) {
            moreToggle.attr('aria-expanded', 'false');
            moreDropdown.removeClass('open');
        }
    });

    /* ===== Animations ===== */
    if (REDUCED) {
        $('.tap-categories-grid .tap-category-card, .tap-section').css({ opacity: 1, transform: 'translateY(0)' });
    } else {
        $('.tap-categories-grid .tap-category-card').each(function(i) {
            $(this).css({ opacity: 0, transform: 'translateY(20px)' });
            setTimeout(function() {
                $(this).css({ transition: 'all 0.5s ease', opacity: 1, transform: 'translateY(0)' });
            }.bind(this), i * 100);
        });

        if (window.IntersectionObserver) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        $(entry.target).css({ opacity: 1, transform: 'translateY(0)' });
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            $('.tap-section').each(function() {
                $(this).css({ opacity: 0, transform: 'translateY(30px)', transition: 'all 0.7s ease' });
                observer.observe(this);
            });
        } else {
            $('.tap-section').css({ opacity: 1, transform: 'translateY(0)' });
        }
    }

    var hero = $('.tap-hero');
    if (hero.length) {
        $(window).on('scroll', function() {
            var scroll = $(this).scrollTop();
            if (!REDUCED) {
                hero.find('.tap-hero-bg').css('transform', 'translateY(' + (scroll * 0.3) + 'px)');
            }
        });
    }

    /* ===== Hash scroll ===== */
    var hash = window.location.hash;
    if (hash) {
        setTimeout(function() {
            var target = $(hash);
            if (target.length) {
                $('html, body').animate({ scrollTop: target.offset().top - 90 }, 500);
            }
        }, 300);
    }

    /* ===== Resize handler ===== */
    var resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            var w = $(window).width();
            if (w >= BREAKPOINT) {
                if (headerCenter.hasClass('open')) closeMobileMenu();
                manageOverflow();
            } else {
                closeUserDropdown();
                if (!moreMenu.attr('hidden')) {
                    moreMenu.attr('hidden', '');
                    moveAllBack();
                }
            }
        }, 200);
    });

    /* ===== Touch swipe to close menu ===== */
    if (isTouch) {
        var touchStartX = 0;
        var touchStartY = 0;
        var swiping = false;

        $(document).on('touchstart', function(e) {
            var touch = e.originalEvent.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
            swiping = false;
        });

        $(document).on('touchmove', function(e) {
            if (swiping || !headerCenter.hasClass('open')) return;
            var touch = e.originalEvent.touches[0];
            var diffX = touch.clientX - touchStartX;
            var diffY = Math.abs(touch.clientY - touchStartY);

            if (diffY > 20) { swiping = true; return; }

            if (diffX > 60) {
                swiping = true;
                closeMobileMenu();
            }
        });
    }

    manageOverflow();

})(jQuery);
