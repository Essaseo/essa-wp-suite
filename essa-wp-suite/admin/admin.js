/* ESSA WP Suite — Admin JS */
(function ($) {
    'use strict';

    if (typeof window.EWPS === 'undefined') { return; }
    var i18n = EWPS.i18n || {};

    $(function () {

        // ── Toggle switch (Dashboard) ─────────────────────────────────────────
        $(document).on('click', '.ewps-toggle', function (e) {
            e.stopPropagation();
            var $btn  = $(this);
            var $card = $btn.closest('.ewps-module-card');
            var mod   = $btn.data('module');

            if ($btn.hasClass('ewps-toggle--loading')) { return; }
            $btn.addClass('ewps-toggle--loading');

            $.post(EWPS.ajax, { action: 'ewps_toggle_module', nonce: EWPS.nonce, module: mod })
            .done(function (res) {
                if (!res || !res.success) { window.alert(i18n.error); return; }
                var on = !!res.data.active;

                $btn.toggleClass('ewps-toggle--on', on);
                $card.toggleClass('ewps-module-card--active', on).toggleClass('ewps-module-card--inactive', !on);

                $card.find('.ewps-module-card__status')
                     .text(on ? i18n.active : i18n.inactive)
                     .toggleClass('ewps-status--on', on).toggleClass('ewps-status--off', !on);

                var total  = $('.ewps-module-card').length;
                var active = $('.ewps-module-card--active').length;
                $('.ewps-modules-badge').text(active + '/' + total + ' ' + i18n.activeOf);
                $('#ewps-stat-active').text(active);
                $('#ewps-stat-inactive').text(total - active);

                if (mod === 'maintenance') {
                    if (on && !$('.ewps-maint-warning').length) {
                        $('<div class="notice notice-warning ewps-maint-warning"><p>🚧 <strong></strong></p></div>')
                            .find('strong').text(i18n.maintOn).end()
                            .insertAfter('.ewps-header');
                    } else if (!on) {
                        $('.ewps-maint-warning').remove();
                    }
                }
            })
            .fail(function () { window.alert(i18n.error); })
            .always(function () { $btn.removeClass('ewps-toggle--loading'); });
        });

        // Klik w kartę → konfiguracja (poza przełącznikiem i linkiem)
        $(document).on('click', '.ewps-module-card', function (e) {
            if ($(e.target).closest('.ewps-toggle, .ewps-module-card__link, a, button').length) { return; }
            var href = $(this).find('.ewps-module-card__link').attr('href');
            if (href) { window.location.href = href; }
        });

        // ── Email encoder: test ───────────────────────────────────────────────
        $('#ewps-test-btn').on('click', function () {
            var $btn  = $(this);
            var email = $.trim($('#ewps-test-input').val());
            if (!email) { $('#ewps-test-output').hide(); return; }
            $btn.prop('disabled', true).text('…');
            $.post(EWPS.ajax, { action: 'ewps_test_encode', nonce: EWPS.nonce, email: email })
            .done(function (res) { if (res && res.success) { $('#ewps-test-output').text(res.data.encoded).show(); } })
            .always(function () { $btn.prop('disabled', false).text(i18n.encode); });
        });

        $('#ewps-test-input').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#ewps-test-btn').trigger('click'); }
        });

        // ── SMTP: presety ─────────────────────────────────────────────────────
        $(document).on('click', '.ewps-smtp-preset', function () {
            $('[name="ewps_smtp_settings[smtp_host]"]').val($(this).data('host'));
            $('[name="ewps_smtp_settings[smtp_port]"]').val($(this).data('port'));
            $('[name="ewps_smtp_settings[smtp_encryption]"]').val($(this).data('enc'));
        });

        // ── SMTP: test wysyłki ────────────────────────────────────────────────
        $(document).on('click', '#ewps-smtp-test-btn', function () {
            var $btn = $(this);
            var to   = $('#ewps-smtp-test-to').val() || $('#ewps-smtp-test-to').attr('placeholder');
            var $r   = $('#ewps-smtp-test-result');
            $btn.prop('disabled', true).text(i18n.sending);
            $r.hide().removeClass('success error');

            $.post(EWPS.ajax, { action: 'ewps_smtp_test', nonce: EWPS.nonce, to: to })
            .done(function (res) {
                if (res && res.success) {
                    $r.addClass('success').text('✓ ' + i18n.sentTo + ' ' + res.data.to).show();
                } else {
                    var msg = (res && res.data) ? res.data : i18n.unknown;
                    $r.addClass('error').text('✗ ' + i18n.errorPrefix + ' ' + msg).show();
                }
            })
            .fail(function () { $r.addClass('error').text('✗ ' + i18n.ajaxError).show(); })
            .always(function () { $btn.prop('disabled', false).text(i18n.sendTest); });
        });

        // ── DB Cleaner ────────────────────────────────────────────────────────
        function recountTotal() {
            var total = 0;
            $('[id^="ewps-db-count-"]').not('#ewps-db-count-total').each(function () {
                total += parseInt($(this).text().replace(/[^\d]/g, ''), 10) || 0;
            });
            $('#ewps-db-count-total').text(total.toLocaleString());
        }

        $(document).on('click', '.ewps-db-clean-btn', function () {
            var $btn = $(this);
            var type = $btn.data('type');
            var label = $btn.closest('.ewps-db-stat-row').find('.ewps-db-stat-label').text() || type;
            if (!window.confirm((i18n.confirmClean || '%s').replace('%s', label))) { return; }

            var original = $btn.text();
            $btn.prop('disabled', true).text('…');

            $.post(EWPS.ajax, { action: 'ewps_db_clean', nonce: EWPS.nonce, type: type })
            .done(function (res) {
                if (!res || !res.success) { window.alert((res && res.data) ? res.data : i18n.error); return; }
                if (type === 'all') {
                    $('[id^="ewps-db-count-"]').text('0');
                } else {
                    $('#ewps-db-count-' + type).text('0');
                    recountTotal();
                }
            })
            .fail(function () { window.alert(i18n.ajaxError); })
            .always(function () { $btn.prop('disabled', false).text(original); });
        });

    });
}(jQuery));
