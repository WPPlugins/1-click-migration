/* OneClick Migration 3.0 admin UI — vanilla JS, no jQuery UI accordion. */
(function ($) {
    'use strict';

    // -------- helpers --------
    var EMAIL_RE = /^([a-zA-Z0-9_.+\-])+@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,})+$/;
    var noticeList = [];
    var stallNotice = null;

    function validateEmail(email) {
        return EMAIL_RE.test(email);
    }

    // Translated strings from OCM_Admin_Page::script_strings(). Escape them
    // with escHtml() before inserting them as HTML.
    function t(key) {
        var strings = (typeof siteData !== 'undefined' && siteData.i18n) || {};
        return typeof strings[key] === 'string' ? strings[key] : key;
    }

    // Fills %s / %d, or %1$s, %2$s, in a translated string. The replacement is
    // a function so that "$&" in a value stays literal.
    function fmt(format) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return String(format).replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
            var value = position ? args[position - 1] : args[next++];
            return value == null ? '' : String(value);
        });
    }

    // The log line at the start of a progress text, before the help the server
    // appends after a line break (OCM_Admin_Page::status_head()). Error, notice
    // and completion checks read only this part: the log line is English
    // protocol, the help after it is translated.
    function statusHead(text) {
        return String(text == null ? '' : text).split(/<\s*\/?\s*br\s*\/?\s*>/i)[0];
    }

    // "Copy details for the forum" on a failed run's progress card: the text
    // comes from the server (site domain, versions, the failure line).
    function copyText(text, done) {
        function fallback() {
            var $ta = $('<textarea readonly></textarea>').val(text).css({ position: 'fixed', top: 0, left: '-9999px' }).appendTo('body');
            $ta[0].focus();
            $ta[0].select();
            try { document.execCommand('copy'); } catch (e) { /* nothing more to try */ }
            $ta.remove();
            done();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, fallback);
        } else {
            fallback();
        }
    }
    $(document).on('click', '.ocm-copy-forum', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var text = String($btn.attr('data-ocm-copy') || '').split(' || ').join('\n');
        copyText(text, function () {
            var copied = $btn.attr('data-ocm-copied');
            if (copied) { $btn.text(copied); }
        });
    });

    function disableButtons() {
        $('.ocm-button.backup-button, .ocm-button.restore-button').addClass('one-click-disabled');
    }
    function enableButtons() {
        $('#ocm-btn-restore, #ocm_restore_code, input[data-ocm-exclude]').prop('disabled', false);
        $('.ocm-button').removeClass('one-click-disabled');
        if (typeof siteData !== 'undefined' && siteData.otherPluginsActive > 0 && !$('#ocm-plugins-ack-checkbox').is(':checked')) {
            $('.ocm-button.backup-button, .ocm-button.restore-button').addClass('one-click-disabled');
        }
    }
    function buttonIsDisabled(el) { return $(el).hasClass('one-click-disabled'); }

    function setProgress(value, statusText) {
        $('#ocm-pg-pct').text(value);
        $('#ocm-pg-fill').css('width', value);
        if (typeof statusText === 'string') {
            $('#ocm-pg-status').html(statusText);
        }
    }

    function clearMessages() {
        $('.ocm-info.error, .ocm-info.notice').hide().removeClass('show').empty();
    }

    function renderMessage(text) {
        if (!text) return;
        var head = statusHead(text);
        if (head.match(/Error/)) {
            if (head.indexOf('SYSLOG: "[PHP ERR][FATAL]') === -1) {
                $('.ocm-info.error').html(text).addClass('show').show();
            }
        } else if (head.match(/Notice:/)) {
            if (noticeList.indexOf(text) === -1) {
                noticeList.push(text);
                $('.ocm-info.notice').append($('<span></span>').attr('data-ocm-notice', text).html(text)).append('<br>').addClass('show').show();
            }
        } else {
            $('#ocm-pg-status').html(text);
        }
    }

    function removeNotice(text) {
        var i = noticeList.indexOf(text);
        if (i === -1) return;
        noticeList.splice(i, 1);
        $('.ocm-info.notice span[data-ocm-notice]').filter(function () {
            return $(this).attr('data-ocm-notice') === text;
        }).each(function () {
            $(this).next('br').remove();
            $(this).remove();
        });
        if (!noticeList.length) {
            $('.ocm-info.notice').hide().removeClass('show').empty();
        }
    }

    function clearNotices() {
        noticeList = [];
        stallNotice = null;
        $('.ocm-info.notice').hide().removeClass('show').empty();
    }

    // -------- progress polling --------
    var progressTimeoutId, progressIntervalId;
    function stopProgressPolling() {
        clearTimeout(progressTimeoutId);
        clearInterval(progressIntervalId);
    }

    function displayProgressBar() {
        stopProgressPolling();
        clearMessages();

        progressTimeoutId = setTimeout(function () {
            progressIntervalId = setInterval(function () {
                $.ajax({
                    url: siteData.progressUrl,
                    method: 'GET',
                    // A JSON Accept header lets progressUrl's _locale=user apply.
                    dataType: 'json',
                    beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', siteData.restNonce); },
                    success: function (response) {
                        if (response.maintenanceMode) {
                            $('.ocm-maintenance-mode-indicator').show();
                        } else {
                            $('.ocm-maintenance-mode-indicator').hide();
                        }

                        // A finished or stopped run drops the notices it collected
                        // (a notice that comes with the final state is shown again
                        // below). While it runs, the "taking longer" notice goes
                        // away as soon as the server stops sending it.
                        if (response.isStopped || '100%' === response.value) {
                            clearNotices();
                        } else if (stallNotice && response.customNotice !== stallNotice) {
                            removeNotice(stallNotice);
                            stallNotice = null;
                        }
                        // The run is past an error it showed (it resumed on its
                        // own, or the error came from an unrelated request):
                        // don't leave the old red box up.
                        if (!response.isStopped && !/Error/.test(statusHead(response.text || ''))) {
                            $('.ocm-info.error').hide().removeClass('show').empty();
                        }
                        renderMessage(response.text);
                        if (response.customNotice) {
                            renderMessage(response.customNotice);
                            stallNotice = response.customNotice;
                        }

                        if (response.topBanner) {
                            $('#ocm-top-banner').html(response.topBanner).show();
                            applyBannerPayState();
                        } else {
                            stopBannerPay();
                            $('#ocm-top-banner').hide().empty();
                        }

                        // Bar + pct
                        $('#ocm-pg-fill').css('width', response.value).addClass('running').removeClass('ocm-pg-done ocm-pg-failed');
                        $('#ocm-pg-pct').text(response.value);

                        var responseHead = statusHead(response.text);

                        if (response.isStopped) {
                            clearInterval(progressIntervalId);
                            clearTimeout(progressTimeoutId);
                            // Steady red for a failed run; the bar never flickers on an error.
                            $('#ocm-pg-fill').removeClass('running').toggleClass('ocm-pg-failed', !!response.failed);
                            enableButtons();
                        } else if ('100%' === response.value) {
                            $('#ocm-pg-fill').removeClass('running').addClass('ocm-pg-done');
                            enableButtons();
                            if (responseHead.indexOf('Backup completed') !== -1 || responseHead.indexOf('Restore completed') !== -1) {
                                clearInterval(progressIntervalId);
                                clearTimeout(progressTimeoutId);
                                if (responseHead.indexOf('Restore completed') !== -1 || response.restoreCompleted) {
                                    redirectToPluginPageAfterRestore();
                                }
                            }
                        }
                    },
                    error: function (xhr) {
                        if (xhr.status === 403) {
                            // Logged out (a restore replaces the site's users) or
                            // the page expired. Say so before reloading, which
                            // leads to the login screen.
                            clearInterval(progressIntervalId);
                            clearTimeout(progressTimeoutId);
                            $('.ocm-info.notice').append($('<span></span>').text(t('loggedOut'))).append('<br>').addClass('show').show();
                            setTimeout(function () { window.location.reload(); }, 8000);
                        }
                    }
                });
            }, 1000);
        }, 2000);
    }

    // -------- submit handlers --------
    function getSelectedExcludes() {
        var out = [];
        $('input[data-ocm-exclude]:checked').each(function () {
            out.push($(this).data('ocm-exclude'));
        });
        return out;
    }

    function submitBackup() {
        clearMessages();
        var email = $('#ocm_user_email').val();
        if (!validateEmail(email)) {
            $('#ocm-pg-status').html('<span style="color:var(--ocm-err)">' + escHtml(t('invalidEmail')) + '</span>');
            return;
        }
        var excludes = getSelectedExcludes();
        if (excludes.length >= 4) {
            $('.ocm-exclusion-warning').show();
            return;
        }
        $('.ocm-exclusion-warning').hide();
        disableButtons();
        var url = $('#ocm-backup-href').attr('href')
            + '&username=' + encodeURIComponent(email)
            + '&selected=' + encodeURIComponent(excludes.join(','))
            + '&_wpnonce=' + siteData.backupNonce;
        setProgress('1%', escHtml(t('backupStarted')));
        displayProgressBar();
        $.get(url).fail(function (xhr) {
            enableButtons();
            // The server refuses a start while another backup or restore runs.
            var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            if (message) {
                stopProgressPolling();
                setProgress('0%', escHtml(message));
            }
        });
    }

    var restorePreparation = null;
    var prepareGeneration = 0;

    function clearRestorePreparation() {
        endPayStep();
        prepareGeneration++;
        restorePreparation = null;
        $('#ocm-restore-precheck').prop('hidden', true).empty();
        $('#ocm-btn-restore').prop('disabled', false);
        enableButtons();
    }

    function startPreparedRestore(preparation, ids) {
        // The server also binds this token to the code, exclusions, user and destination.
        disableButtons();
        $('#ocm-restore-precheck button, #ocm-restore-precheck input, #ocm_restore_code, input[data-ocm-exclude]').prop('disabled', true);
        var url = $('#ocm-restore-href').attr('href')
            + '&restore_code=' + encodeURIComponent(preparation.code)
            + '&selected=' + encodeURIComponent(preparation.excludes.join(','))
            + '&_wpnonce=' + encodeURIComponent(siteData.restoreNonce);
        $.post(url, { precheck_token: preparation.token, domain_ids: ids }).done(function (response) {
            if (response && response.success === false) {
                restoreStartFailed(response.data && response.data.message);
            }
        }).fail(function (xhr) {
            // A rejected precheck never starts a worker. A transport timeout may
            // leave a running worker, so let progress polling reconcile that case.
            if (xhr.responseJSON && xhr.responseJSON.success === false) {
                restoreStartFailed(xhr.responseJSON.data && xhr.responseJSON.data.message);
            }
        });
        $('#ocm-restore-precheck').prop('hidden', true);
        setProgress('1%', escHtml(t(preparation.legacy ? 'restoreStartedLegacy' : 'restoreStarted')));
        displayProgressBar();
    }

    // -------- pay per migration --------
    // The service names the pay page for a backup that has not been paid for.
    // Only an https address on our own site ever becomes a link, a form
    // action or an address we poll.
    var PAY_URL_RE = /^https:\/\/1clickmigration\.com\/[A-Za-z0-9:\/?&=._~%-]*$/;
    var PAY_WINDOW = 'ocm_pay';
    var PAY_POLL_MS = 4000;
    var PAY_POLL_FOR_MS = 20 * 60 * 1000;
    var PAY_START_DELAY_MS = 1200;

    function safePayUrl(url) {
        return typeof url === 'string' && PAY_URL_RE.test(url) ? url : '';
    }

    function payLink(data) {
        return data && data.code === 'payment_required' ? safePayUrl(data.pay_url) : '';
    }

    // Opens the small payment window and starts checkout in it: a POST with
    // action=start goes straight on to PayPal. Must run inside the click
    // handler, or the browser blocks the window. Returns the window or null
    // (blocked: the form then opens a new tab instead).
    function openPayWindow(url) {
        url = safePayUrl(url);
        if (!url) return null;
        var width = 520, height = 760, left = 0, top = 0;
        try {
            left = Math.max(0, Math.round((window.screenX || 0) + ((window.outerWidth || width) - width) / 2));
            top = Math.max(0, Math.round((window.screenY || 0) + ((window.outerHeight || height) - height) / 2));
        } catch (e) { /* keep 0, 0 */ }
        var popup = null;
        try {
            popup = window.open('about:blank', PAY_WINDOW, 'popup=yes,width=' + width + ',height=' + height + ',left=' + left + ',top=' + top);
        } catch (e) {
            popup = null;
        }
        // setAttribute: the field named "action" shadows form.action.
        var form = document.createElement('form');
        form.setAttribute('method', 'post');
        form.setAttribute('action', url);
        form.setAttribute('target', popup ? PAY_WINDOW : '_blank');
        if (!popup) form.setAttribute('rel', 'noopener');
        form.style.display = 'none';
        var field = document.createElement('input');
        field.setAttribute('type', 'hidden');
        field.setAttribute('name', 'action');
        field.setAttribute('value', 'start');
        form.appendChild(field);
        document.body.appendChild(form);
        try {
            form.submit();
        } finally {
            document.body.removeChild(form);
        }
        return popup;
    }

    function closePayWindow(popup) {
        try {
            if (popup && !popup.closed) popup.close();
        } catch (e) { /* PayPal may have cut the link to it */ }
    }

    // Asks the pay page every few seconds whether the backup is paid. on.paid
    // and on.gone end the wait; on.pending is a payment PayPal is still
    // clearing. A failed or non-JSON answer (an older server) changes
    // nothing. After PAY_POLL_FOR_MS it stops quietly. A closed payment
    // window is no reason to stop: PayPal can cut our link to it.
    function watchPayment(url, on) {
        var statusUrl = url + (url.indexOf('?') === -1 ? '?' : '&') + 'status=1';
        var until = Date.now() + PAY_POLL_FOR_MS;
        var watch = { active: true, timer: null };
        watch.stop = function () {
            watch.active = false;
            clearTimeout(watch.timer);
            watch.timer = null;
        };
        function next() {
            if (!watch.active) return;
            if (Date.now() >= until) {
                watch.stop();
                return;
            }
            watch.timer = setTimeout(ask, PAY_POLL_MS);
        }
        function ask() {
            watch.timer = null;
            if (!watch.active) return;
            $.ajax({
                url: statusUrl,
                dataType: 'json',
                cache: false,
                timeout: 8000,
                success: function (response) {
                    if (!watch.active) return;
                    var state = response && typeof response.state === 'string' ? response.state : '';
                    if (state === 'paid' || state === 'free') {
                        watch.stop();
                        on.paid();
                        return;
                    }
                    if (state === 'expired' || state === 'not_found') {
                        watch.stop();
                        if (on.gone) on.gone();
                        return;
                    }
                    if (state === 'pending' && on.pending) on.pending();
                    next();
                },
                error: function () { next(); }
            });
        }
        next();
        return watch;
    }

    // The pay step inside the restore: "ask" → "waiting" → "paid", or "gone".
    var payStep = null;

    function payPrice(value) {
        return typeof value === 'string' && /^(\$[0-9]{1,6}\.[0-9]{2}|[0-9]{1,6}\.[0-9]{2} [A-Z]{3})$/.test(value) ? value : '';
    }

    // "2026-11-20 16:55:00" (UTC) → "Nov 20, 2026, 4:55 PM" in the browser's own time.
    function payWhen(value) {
        var m = typeof value === 'string' ? /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(value) : null;
        if (!m) return '';
        var date = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]));
        if (isNaN(date.getTime())) return '';
        try {
            return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
        } catch (e) {
            return date.toLocaleString();
        }
    }

    function payButtonLabel(step) {
        return step.price ? fmt(t('payAndRestorePrice'), step.price) : t('payAndRestore');
    }

    function stopPayWatch(step) {
        if (step && step.watch) {
            step.watch.stop();
            step.watch = null;
        }
    }

    // Forgets the pay step: no poll, no pending start, no dialog.
    function endPayStep() {
        var step = payStep;
        if (!step) return;
        payStep = null;
        stopPayWatch(step);
        clearTimeout(step.timer);
        step.timer = null;
        step.open = false;
        if (step.$root) {
            var el = step.$root[0];
            if (step.modal && el.open && typeof el.close === 'function') {
                try { el.close(); } catch (e) { /* removed below anyway */ }
            }
            step.$root.remove();
            step.$root = null;
        }
    }

    // A calm line and a button stay in the restore box, so closing the pay
    // step never leaves a dead end.
    function openPayStep(url, data, recheck) {
        endPayStep();
        payStep = {
            url: url,
            price: payPrice(data.price),
            site: typeof data.site === 'string' && data.site.length <= 253 ? data.site : '',
            when: payWhen(data.created),
            state: 'ask',
            recheck: !!recheck,
            pending: false,
            popup: null,
            watch: null,
            timer: null,
            $root: null,
            modal: false,
            open: false
        };
        $('#ocm-restore-precheck').prop('hidden', false).empty()
            .append($('<p class="ocm-pay-ready" id="ocm-pay-ready"></p>').text(t('payReadyLine')))
            .append($('<p class="ocm-precheck-actions" id="ocm-pay-reopen-row" hidden></p>').append(
                $('<button type="button" class="ocm-btn ocm-btn-primary" id="ocm-pay-reopen"></button>').text(payButtonLabel(payStep))
            ));
        $('#ocm-pay-reopen').on('click', showPayStep);
        showPayStep();
    }

    // A native modal <dialog> where the browser has one; otherwise the same
    // content inside the restore box.
    function showPayStep() {
        var step = payStep;
        if (!step || step.open || step.state === 'gone') return;
        var dialog = document.createElement('dialog');
        var modal = typeof dialog.showModal === 'function';
        var $root;
        if (modal) {
            $root = $(dialog).attr({ id: 'ocm-pay-step', 'class': 'ocm-pay-step ocm-pay-dialog', 'aria-labelledby': 'ocm-pay-title' });
            $('body').append($root);
            // Esc and the close button both end in "close".
            $root.on('close', function () { payStepClosed(step); });
        } else {
            $root = $('<div id="ocm-pay-step" class="ocm-pay-step ocm-pay-inline" role="group" aria-labelledby="ocm-pay-title"></div>');
            $('#ocm-restore-precheck').append($root);
            $root.on('keydown', function (e) {
                if (e.key === 'Escape' || e.key === 'Esc') {
                    e.preventDefault();
                    hidePayStep();
                }
            });
        }
        $root.on('click', '[data-ocm-pay-close]', hidePayStep);
        step.$root = $root;
        step.modal = modal;
        step.open = true;
        $('#ocm-pay-reopen-row').prop('hidden', true);
        renderPayStep();
        if (modal) dialog.showModal();
        // Reopened while waiting: wait again, for another full stretch.
        if (step.state === 'waiting') startPayWatch(step);
        focusPayStep();
    }

    function hidePayStep() {
        var step = payStep;
        if (!step || !step.open) return;
        if (step.modal && step.$root && step.$root[0].open) {
            step.$root[0].close(); // fires "close" → payStepClosed()
            return;
        }
        payStepClosed(step);
    }

    function payStepClosed(step) {
        if (payStep !== step || !step.open) return;
        step.open = false;
        stopPayWatch(step);
        if (step.$root) {
            step.$root.remove();
            step.$root = null;
        }
        if (step.state === 'gone' || step.state === 'paid') return;
        $('#ocm-pay-reopen-row').prop('hidden', false);
        $('#ocm-pay-reopen').trigger('focus');
    }

    function focusPayStep() {
        var step = payStep;
        if (!step || !step.$root) return;
        step.$root.find(step.state === 'ask' ? '#ocm-pay-go' : '#ocm-pay-title').first().trigger('focus');
    }

    function payStepHead(text, spinner) {
        var $head = $('<div class="ocm-pay-head"></div>');
        if (spinner) $head.append('<span class="ocm-pay-spinner" aria-hidden="true"></span>');
        return $head.append($('<h2 id="ocm-pay-title" class="ocm-pay-title" tabindex="-1"></h2>').text(text));
    }

    function renderPayStep() {
        var step = payStep;
        if (!step || !step.$root) return;
        var $card = $('<div class="ocm-pay-card"></div>');
        step.$root.empty().append($card);
        if (step.state !== 'paid') {
            $card.append($('<button type="button" class="ocm-modal-close ocm-pay-close" data-ocm-pay-close></button>')
                .attr('aria-label', t('close')).text('×'));
        }
        if (step.state === 'ask') {
            $card.append(payStepHead(t(step.site ? 'payFoundTitle' : 'payReadyTitle'), false));
            var $facts = $('<dl class="ocm-pay-facts"></dl>');
            if (step.site) {
                $facts.append($('<div></div>').append($('<dt></dt>').text(t('paySite')), $('<dd class="ocm-pay-site"></dd>').text(step.site)));
            }
            if (step.when) {
                $facts.append($('<div></div>').append($('<dt></dt>').text(t('payBackedUp')), $('<dd class="ocm-pay-when"></dd>').text(step.when)));
            }
            if ($facts.children().length) $card.append($facts);
            if (step.price) {
                $card.append($('<div class="ocm-pay-price"></div>').append(
                    $('<p class="ocm-pay-price-line"></p>').append(
                        $('<span></span>').text(t('payRestoreThis')),
                        $('<span class="ocm-pay-amount"></span>').text(step.price)
                    ),
                    $('<p class="ocm-pay-price-note"></p>').text(t('payOneTime'))
                ));
            }
            if (step.recheck) {
                $card.append($('<p class="ocm-pay-recheck" role="status"></p>').text(t('payNotReceived')));
            }
            $card.append($('<div class="ocm-pay-actions"></div>').append(
                $('<button type="button" class="ocm-btn ocm-btn-primary" id="ocm-pay-go"></button>').text(payButtonLabel(step))
                    .on('click', function () { payStepPay(step); })
            ));
            $card.append($('<p class="ocm-pay-note"></p>').text(t('payPayPalNote')));
            $card.append($('<p class="ocm-pay-guarantee"></p>').text(t('payGuarantee')));
        } else if (step.state === 'waiting') {
            $card.append(payStepHead(t('payWaitingTitle'), true));
            $card.append($('<p class="ocm-pay-body" id="ocm-pay-wait-text"></p>').text(t(step.pending ? 'payPendingBody' : 'payWaitingBody')));
            $card.append($('<div class="ocm-pay-actions"></div>').append(
                $('<button type="button" class="ocm-btn ocm-btn-ghost" id="ocm-pay-reopen-window"></button>').text(t('payReopenWindow'))
                    .on('click', function () { payStepReopenWindow(step); }),
                $('<button type="button" class="ocm-btn ocm-btn-primary" id="ocm-pay-check"></button>').text(t('payIvePaid'))
                    .on('click', function () { payStepCheck(step); })
            ));
            $card.append($('<p class="ocm-pay-note"></p>').text(t('payClosedNote')));
        } else if (step.state === 'paid') {
            $card.append(payStepHead(t('payPaidTitle'), true));
        } else if (step.state === 'gone') {
            $card.append($('<p id="ocm-pay-title" class="ocm-pay-title ocm-pay-gone" tabindex="-1"></p>').text(t('payGone')));
            $card.append($('<div class="ocm-pay-actions"></div>').append(
                $('<button type="button" class="ocm-btn ocm-btn-ghost" data-ocm-pay-close></button>').text(t('close'))
            ));
        }
    }

    function startPayWatch(step) {
        stopPayWatch(step);
        step.watch = watchPayment(step.url, {
            paid: function () { payStepPaid(step); },
            pending: function () {
                if (payStep !== step || step.pending) return;
                step.pending = true;
                $('#ocm-pay-wait-text').text(t('payPendingBody'));
            },
            gone: function () { payStepGone(step); }
        });
    }

    function payStepPay(step) {
        if (payStep !== step || step.state !== 'ask') return;
        // Synchronously inside the click, or the window is blocked.
        step.popup = openPayWindow(step.url);
        step.state = 'waiting';
        step.recheck = false;
        step.pending = false;
        renderPayStep();
        startPayWatch(step);
        focusPayStep();
    }

    function payStepReopenWindow(step) {
        if (payStep !== step || step.state !== 'waiting') return;
        step.popup = openPayWindow(step.url) || step.popup;
        startPayWatch(step);
    }

    // "I've paid": stop waiting and run the normal check. Still unpaid comes
    // back to the first screen with a note (restoreStartFailed()).
    function payStepCheck(step) {
        if (payStep !== step || step.state !== 'waiting') return;
        endPayStep();
        submitRestore(true);
    }

    function payStepPaid(step) {
        if (payStep !== step || step.state === 'paid') return;
        stopPayWatch(step);
        step.state = 'paid';
        closePayWindow(step.popup);
        if (step.open) {
            renderPayStep();
            focusPayStep();
        }
        step.timer = setTimeout(function () {
            step.timer = null;
            if (payStep !== step) return;
            endPayStep();
            submitRestore(true);
        }, PAY_START_DELAY_MS);
    }

    function payStepGone(step) {
        if (payStep !== step) return;
        stopPayWatch(step);
        step.state = 'gone';
        $('#ocm-pay-ready').text(t('payGone'));
        $('#ocm-pay-reopen-row').remove();
        if (step.open) {
            renderPayStep();
            focusPayStep();
        }
    }

    // The "Pay $9.99 now" button on the old site's backup-finished notice.
    // Without this handler it is a plain link to the pay page (new tab).
    var bannerPay = null;

    function stopBannerPay() {
        if (bannerPay && bannerPay.watch) bannerPay.watch.stop();
        bannerPay = null;
    }

    function bannerPayClick(e) {
        var url = safePayUrl($(this).attr('href'));
        if (!url) return;
        e.preventDefault();
        stopBannerPay();
        var state = { url: url, popup: openPayWindow(url), watch: null, paid: false };
        bannerPay = state;
        state.watch = watchPayment(url, {
            paid: function () {
                if (bannerPay !== state) return;
                state.watch = null;
                state.paid = true;
                closePayWindow(state.popup);
                applyBannerPayState();
            },
            gone: function () { state.watch = null; }
        });
    }

    // Also runs after progress polling redraws the notice.
    function applyBannerPayState() {
        if (!bannerPay || !bannerPay.paid) return;
        var url = bannerPay.url;
        $('#ocm-top-banner a[data-ocm-pay]').filter(function () {
            return $(this).attr('href') === url;
        }).each(function () {
            $(this).replaceWith($('<span class="ocm-pay-thanks" role="status"></span>').text(t('payBannerThanks')));
        });
    }

    function restoreStartFailed(message, data, recheck) {
        stopProgressPolling();
        clearRestorePreparation();
        $('#ocm_restore_code, input[data-ocm-exclude]').prop('disabled', false);
        var url = payLink(data);
        if (url) {
            openPayStep(url, data, recheck);
            return;
        }
        $('#ocm-restore-precheck').prop('hidden', false)
            .append($('<p role="alert"></p>').text(message || t('checkBackupAgain')));
    }

    function renderRestorePreparation(preparation) {
        var likely = '', other = '';
        preparation.candidates.forEach(function (row) {
            var item = '<li><label><input type="checkbox" name="ocm_precheck_domain" value="' + escHtml(row.id) + '">'
                + '<span><strong>' + escHtml(row.label || row.host) + '</strong>'
                + '<span class="ocm-precheck-note">' + escHtml(t(row.class === 'suggest' ? 'possibleOldAddress' : 'externalAddress')) + '</span>'
                + '<span class="ocm-precheck-example">' + fmt(escHtml(t('fromExample')), escHtml(row.example)) + '</span>'
                + '<span class="ocm-precheck-example">' + fmt(escHtml(t('toExample')), escHtml(row.preview)) + '</span></span></label></li>';
            if (row.class === 'suggest') likely += item;
            else other += item;
        });
        var html = '<h3 tabindex="-1" id="ocm-precheck-title">' + escHtml(t('reviewAddressesTitle')) + '</h3>'
            + '<p>' + escHtml(t('reviewAddressesIntro')) + '</p>'
            + (likely ? '<ul>' + likely + '</ul>' : '')
            + (other ? '<details' + (!likely ? ' open' : '') + '><summary>' + escHtml(t('otherDomains')) + '</summary><p>' + escHtml(t('otherDomainsNote')) + '</p><ul>' + other + '</ul></details>' : '')
            + (preparation.omitted ? '<p>' + escHtml(t('addressesOmitted')) + '</p>' : '')
            + '<p id="ocm-precheck-selection" role="status">' + escHtml(t('noAddressesSelected')) + '</p>'
            + '<p>' + escHtml(t('restoreReplaces')) + '</p>'
            + '<div class="ocm-precheck-actions"><button type="button" class="ocm-btn ocm-btn-primary" id="ocm-precheck-start">' + escHtml(t('startRestore')) + '</button>'
            + '<button type="button" class="ocm-btn ocm-btn-ghost" id="ocm-precheck-back">' + escHtml(t('back')) + '</button></div>';
        $('#ocm-restore-precheck').html(html).prop('hidden', false);
        $('#ocm-precheck-title').trigger('focus');
        $('#ocm-restore-precheck input').on('change', function () {
            var count = $('#ocm-restore-precheck input:checked').length;
            $('#ocm-precheck-selection').text(count ? fmt(t('addressesSelected'), count) : t('noAddressesSelected'));
        });
        $('#ocm-precheck-back').on('click', function () {
            clearRestorePreparation();
            $('#ocm-btn-restore').trigger('focus');
        });
        $('#ocm-precheck-start').on('click', function () {
            if (restorePreparation !== preparation) return;
            var ids = [];
            $('#ocm-restore-precheck input:checked').each(function () { ids.push($(this).val()); });
            restorePreparation = null;
            startPreparedRestore(preparation, ids);
        });
    }

    // fromPayStep: run again from the pay step ("I've paid", or the payment
    // went through); still unpaid then says the payment hasn't arrived.
    function submitRestore(fromPayStep) {
        var recheck = fromPayStep === true;
        clearMessages();
        var raw = ($('#ocm_restore_code').val() || '').replace(/[\s\-]+/g, '').toUpperCase();
        if (raw.length < 6 || raw.length > 32) {
            $('#ocm-restore-precheck').prop('hidden', false).text(t('pasteRestoreCode'));
            return;
        }
        var excludes = getSelectedExcludes();
        if (excludes.length >= 4) {
            $('.ocm-exclusion-warning').show();
            return;
        }
        $('.ocm-exclusion-warning').hide();
        clearRestorePreparation();
        var generation = prepareGeneration;
        disableButtons();
        $('#ocm-btn-restore').prop('disabled', true);
        $('#ocm-restore-precheck').prop('hidden', false).html('<p role="status">' + escHtml(t('checkingAddresses')) + '</p>');
        $.post(siteData.ajaxurl, {
            action: 'ocm_prepare_restore',
            _wpnonce: siteData.prepareRestoreNonce,
            restore_code: raw,
            selected: excludes.join(',')
        }).done(function (response) {
            if (generation !== prepareGeneration) return;
            if (!response || !response.success) {
                restoreStartFailed(response && response.data && response.data.message, response && response.data, recheck);
                return;
            }
            var preparation = response.data;
            preparation.code = raw;
            preparation.excludes = excludes;
            restorePreparation = preparation;
            if (!preparation.candidates.length && !preparation.omitted) {
                restorePreparation = null;
                startPreparedRestore(preparation, []);
            } else {
                renderRestorePreparation(preparation);
            }
        }).fail(function (xhr) {
            if (generation !== prepareGeneration) return;
            restoreStartFailed(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                || t('checkFailed'), xhr.responseJSON && xhr.responseJSON.data, recheck);
        });
    }

    function setActiveTab(name) {
        $('.ocm-tab').each(function () {
            var on = $(this).data('ocm-tab') === name;
            $(this).toggleClass('active', on).attr('aria-selected', on ? 'true' : 'false');
        });
        $('.ocm-pane').each(function () {
            $(this).toggleClass('active', $(this).data('ocm-pane') === name);
        });
    }


    function ocmFlagOn(v) {
        return v === true || v === 1 || v === '1';
    }

    // -------- restore success modal --------
    var extraDomainsSettled = false;
    var extraDomainsApplyStarted = false;

    function extraDomainRows() {
        if (typeof siteData === 'undefined' || !siteData.extraDomains || !siteData.extraDomains.length) {
            return [];
        }
        return siteData.extraDomains;
    }

    function escHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[ch];
        });
    }

    function extraDomainsBlock() {
        var rows = extraDomainRows();
        if (!rows.length) return '';
        var items = '';
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i] || {};
            var id = escHtml(row.id);
            items += ''
                + '<li>'
                + '  <label>'
                + '    <input type="checkbox" name="ocm_extra_domain" value="' + id + '"' + (ocmFlagOn(row.checked) ? ' checked' : '') + '>'
                + '    <span>'
                + '      <span class="ocm-extra-host">' + escHtml(row.label || row.host) + '</span>'
                + (row.note ? '      <span class="ocm-extra-note">' + escHtml(row.note) + '</span>' : '')
                + (row.example ? '      <span class="ocm-extra-example">' + escHtml(row.example) + '</span>' : '')
                + '    </span>'
                + '  </label>'
                + '</li>';
        }
        return ''
            + '<div class="ocm-extra-domains">'
            + '  <h3>' + escHtml(t('otherAddressesFound')) + '</h3>'
            + '  <p>' + escHtml(t('otherAddressesIntro')) + '</p>'
            + '  <ul>' + items + '</ul>'
            + '  <button type="button" class="ocm-btn ocm-btn-primary" id="ocm-extra-domains-apply">' + escHtml(t('updateAddresses')) + '</button>'
            + '  <p id="ocm-extra-domains-status" class="ocm-extra-status" hidden></p>'
            + '</div>';
    }

    function settleExtraDomainsSkip() {
        if (extraDomainsSettled || extraDomainsApplyStarted || !extraDomainRows().length) return;
        if (typeof siteData === 'undefined' || !siteData.ajaxurl || !siteData.extraDomainsNonce) return;
        extraDomainsSettled = true;
        var body = new FormData();
        body.append('action', 'ocm_apply_extra_domains');
        body.append('_wpnonce', siteData.extraDomainsNonce);
        if (navigator.sendBeacon) {
            navigator.sendBeacon(siteData.ajaxurl, body);
            return;
        }
        $.post(siteData.ajaxurl, {
            action: 'ocm_apply_extra_domains',
            _wpnonce: siteData.extraDomainsNonce
        });
    }

    function applyExtraDomains() {
        if (extraDomainsApplyStarted || typeof siteData === 'undefined') return;
        extraDomainsApplyStarted = true;
        var $btn = $('#ocm-extra-domains-apply');
        var $status = $('#ocm-extra-domains-status');
        var ids = [];
        $('#ocm-restore-success-modal input[name="ocm_extra_domain"]:checked').each(function () {
            ids.push($(this).val());
        });
        $btn.prop('disabled', true);
        $('#ocm-restore-success-modal input[name="ocm_extra_domain"]').prop('disabled', true);
        $status.prop('hidden', false).text(t('updatingAddresses'));
        $.post(siteData.ajaxurl, {
            action: 'ocm_apply_extra_domains',
            _wpnonce: siteData.extraDomainsNonce,
            ids: ids
        }).done(function (resp) {
            extraDomainsSettled = true;
            var updates = resp && resp.data && typeof resp.data.updates !== 'undefined' ? parseInt(resp.data.updates, 10) : 0;
            if (!resp || !resp.success) {
                $status.text(t('addressesRetry'));
                extraDomainsApplyStarted = false;
                extraDomainsSettled = false;
                $btn.prop('disabled', false);
                $('#ocm-restore-success-modal input[name="ocm_extra_domain"]').prop('disabled', false);
                return;
            }
            $status.text(updates > 0 ? fmt(t('rowsUpdated'), updates) : t('noAddressesRewritten'));
        }).fail(function () {
            extraDomainsApplyStarted = false;
            extraDomainsSettled = false;
            $btn.prop('disabled', false);
            $('#ocm-restore-success-modal input[name="ocm_extra_domain"]').prop('disabled', false);
            $status.prop('hidden', false).text(t('addressesRetry'));
        });
    }

    function ensureRestoreSuccessModal(withReviewCta) {
        $('#ocm-restore-success-modal').remove();
        var reviewUrl = (typeof siteData !== 'undefined' && siteData.reviewUrl)
            ? siteData.reviewUrl
            : 'https://wordpress.org/plugins/1-click-migration/reviews/#new-post';
        var dismissUrl = (typeof siteData !== 'undefined' && siteData.dismissReviewUrl)
            ? siteData.dismissReviewUrl
            : '';
        var supportUrl = (typeof siteData !== 'undefined' && siteData.supportUrl)
            ? siteData.supportUrl
            : 'https://wordpress.org/support/plugin/1-click-migration/';
        var isPartial = typeof siteData !== 'undefined' && ocmFlagOn(siteData.showRestorePartial);
        var isWarned = typeof siteData !== 'undefined' && ocmFlagOn(siteData.showRestoreWarned);
        var isIncomplete = isPartial || isWarned;
        // A clean restore asks "did everything come across?" first. "Yes" leads
        // to the review ask, "Something's off" to the forum; the review link is
        // never shown to someone whose site is broken.
        var askFirst = false;
        if (withReviewCta && !isIncomplete) {
            askFirst = true;
        }
        var titleText = t(isPartial
            ? 'restorePartialTitle'
            : (isWarned ? 'restoreWarnedTitle' : 'restoreDoneTitle'));
        var bodyText = t(isPartial
            ? 'restorePartialBody'
            : (isWarned
                ? 'restoreWarnedBody'
                : (askFirst ? 'restoreCheckBody' : 'restoreDoneBody')));
        // The forum is public: anyone with the restore code can restore the backup.
        var forumSentence = fmt(escHtml(t('supportForum')), '<a href="' + escHtml(supportUrl) + '" target="_blank" rel="noopener noreferrer">', '</a>');
        var closeButton = '      <button type="button" class="ocm-btn ocm-btn-ghost ocm-modal-dismiss" data-ocm-modal-close>' + escHtml(t('close')) + '</button>';
        var checkActions = ''
            + '      <button type="button" class="ocm-btn ocm-btn-primary ocm-modal-choice" id="ocm-restore-ok">' + escHtml(t('looksGood')) + '</button>'
            + '      <button type="button" class="ocm-btn ocm-btn-ghost ocm-modal-choice" id="ocm-restore-off">' + escHtml(t('somethingOff')) + '</button>';
        var reviewActions = ''
            + '      <a class="ocm-btn ocm-btn-primary ocm-modal-review" id="ocm-review-btn" href="' + reviewUrl + '" target="_blank" rel="noopener noreferrer">' + escHtml(t('leaveReview')) + '</a>'
            + (dismissUrl
                ? '      <a class="ocm-btn-link ocm-modal-never" id="ocm-dismiss-review" href="' + dismissUrl + '">' + escHtml(t('dontAskAgain')) + '</a>'
                : '');
        var forumActions = ''
            + '      <a class="ocm-btn ocm-btn-primary ocm-modal-forum" id="ocm-forum-btn" href="' + escHtml(supportUrl) + '" target="_blank" rel="noopener noreferrer">' + escHtml(t('openForum')) + '</a>';
        var domains = extraDomainsBlock();
        var cardClass = 'ocm-modal-card' + (domains ? ' ocm-has-extra-domains' : '');
        var html = ''
            + '<div id="ocm-restore-success-modal" class="ocm-modal" role="dialog" aria-modal="true" aria-labelledby="ocm-restore-success-title" hidden>'
            + '  <div class="ocm-modal-backdrop" data-ocm-modal-close></div>'
            + '  <div class="' + cardClass + '">'
            + '    <button type="button" class="ocm-modal-close" data-ocm-modal-close aria-label="' + escHtml(t('close')) + '">&times;</button>'
            + '    <div class="ocm-modal-icon" aria-hidden="true">' + (isIncomplete ? '!' : '✓') + '</div>'
            + '    <h2 id="ocm-restore-success-title" class="ocm-modal-title">' + escHtml(titleText) + '</h2>'
            + '    <p class="ocm-modal-body" id="ocm-restore-modal-body">' + escHtml(bodyText) + '</p>'
            + (isIncomplete
                ? '    <p class="ocm-modal-body ocm-modal-support">' + escHtml(t('needHelp')) + ' ' + forumSentence + '</p>'
                : '')
            + domains
            + '    <div class="ocm-modal-actions" id="ocm-restore-modal-actions">'
            + (askFirst ? checkActions : '')
            + closeButton
            + '    </div>'
            + '  </div>'
            + '</div>';
        $('body').append(html);
        $('#ocm-restore-success-modal').on('click', '[data-ocm-modal-close]', function () {
            hideRestoreSuccessModal();
        });
        $('#ocm-restore-ok').on('click', function () {
            $('#ocm-restore-modal-body').text(t('restoreReviewBody'));
            $('#ocm-restore-modal-actions').html(reviewActions + closeButton);
            $('#ocm-review-btn').trigger('focus');
        });
        $('#ocm-restore-off').on('click', function () {
            $('#ocm-restore-modal-body').text(t('restoreTroubleBody'))
                .after('<p class="ocm-modal-body ocm-modal-support">' + forumSentence + '</p>');
            $('#ocm-restore-modal-actions').html(forumActions + closeButton);
            $('#ocm-forum-btn').trigger('focus');
        });
        $('#ocm-extra-domains-apply').on('click', function () {
            applyExtraDomains();
        });
        $(document).on('keydown.ocmRestoreModal', function (e) {
            if (e.key === 'Escape') hideRestoreSuccessModal();
        });
    }

    function showRestoreSuccessModal(withReviewCta) {
        ensureRestoreSuccessModal(!!withReviewCta);
        var $m = $('#ocm-restore-success-modal');
        $m.prop('hidden', false).attr('aria-hidden', 'false').addClass('open');
        $('body').addClass('ocm-modal-open');
        setTimeout(function () {
            var $focus = extraDomainRows().length
                ? $('#ocm-extra-domains-apply')
                : ($('#ocm-restore-ok').length ? $('#ocm-restore-ok') : $m.find('[data-ocm-modal-close]').first());
            $focus.trigger('focus');
        }, 50);
    }

    function hideRestoreSuccessModal() {
        var $m = $('#ocm-restore-success-modal');
        if (!$m.length) return;
        settleExtraDomainsSkip();
        $m.prop('hidden', true).attr('aria-hidden', 'true').removeClass('open');
        $('body').removeClass('ocm-modal-open');
        $(document).off('keydown.ocmRestoreModal');
    }

    function redirectToPluginPageAfterRestore() {
        if (typeof siteData === 'undefined' || !siteData.pluginPageUrl) return;
        var url = siteData.pluginPageUrl;
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'ocm_restore_done=1';
        window.location.href = url;
    }

    // -------- Local Backups & Scheduler --------
    var localPollTimerId = null;
    var localPollDelayId = null;
    var activeLocalRestoreId = '';
    // True from the click until the start request answers. Until the server
    // has written the new run to the log, a poll still reads the previous
    // run's last line ("completed", or its failure).
    var localStartPending = false;
    // Bumped whenever a start request is sent or answers, so a poll sent
    // before that is dropped when its answer arrives late.
    var localPollEpoch = 0;
    // The start request got no answer (timeout, dropped connection), so the
    // server may or may not have started the run. Until a poll shows a run in
    // progress, a "completed" or "failed" in the log is the previous run's.
    var localStartUnconfirmed = false;
    var localSawActive = false;
    // 'backup' or 'restore' while one runs: the Stop question differs.
    var localCurrentAction = '';
    // Progress polls in a row that failed with something other than 401/403.
    var localPollErrors = 0;
    var LOCAL_POLL_MAX_ERRORS = 8;
    var localReloadId = null;

    // ------------------------------------------------------------------
    // Search & Replace
    // ------------------------------------------------------------------
    var srPreviewKey = '';      // the inputs the last finished preview ran with
    var srPreviewRows = 0;
    var srPreviewSiteAddress = false;
    var srPreviewEpoch = 0;
    var SR_SAMPLE_LIMIT = 20;

    function srNonce() {
        return (typeof siteData !== 'undefined' && siteData.srNonce) || '';
    }

    function srInput() {
        var tables = [];
        $('input[data-ocm-sr-table]:checked').each(function () {
            tables.push($(this).attr('data-ocm-sr-table'));
        });
        return {
            search: String($('#ocm-sr-search').val() || ''),
            replace: String($('#ocm-sr-replace').val() || ''),
            tables: tables,
            match_case: $('#ocm-sr-match-case').is(':checked') ? '1' : '0',
            skip_guid: $('#ocm-sr-skip-guid').is(':checked') ? '1' : '0'
        };
    }

    function srKey(input) {
        return JSON.stringify([input.search, input.replace, input.tables, input.match_case, input.skip_guid]);
    }

    function srValidate(input) {
        if (Array.from(input.search).length < 3) {
            return t('srNeedSearch');
        }
        if (!input.tables.length) {
            return t('srNeedTables');
        }
        var same = input.match_case === '1'
            ? input.search === input.replace
            : input.search.toLowerCase() === input.replace.toLowerCase();
        return same ? t('srSameText') : '';
    }

    function srSetMsg(text) {
        $('#ocm-sr-msg').text(text || '').prop('hidden', !text);
    }

    function srUpdateTablesCount() {
        var all = $('input[data-ocm-sr-table]').length;
        var on = $('input[data-ocm-sr-table]:checked').length;
        $('#ocm-sr-tables-count').text(fmt(t('srTablesCount'), on, all));
    }

    function srSyncReplaceButton() {
        var ready = srPreviewKey !== '' && srPreviewKey === srKey(srInput()) && srPreviewRows > 0;
        $('#ocm-sr-btn-replace').prop('disabled', !ready);
    }

    function srSetFormBusy(busy) {
        $('#ocm-sr-form-card').find('input, button').prop('disabled', !!busy);
        if (!busy) {
            srSyncReplaceButton();
        }
    }

    function srShowProgress(pct, text, state) {
        showRunProgress('sr', pct, text, state);
    }

    function srCountsTable(counts) {
        var names = Object.keys(counts || {}).sort();
        if (!names.length) {
            return '';
        }
        var html = '<div class="ocm-local-table-wrap"><table class="ocm-local-table ocm-sr-counts"><thead><tr>'
            + '<th>' + escHtml(t('srTableCol')) + '</th><th>' + escHtml(t('srRowsCol')) + '</th></tr></thead><tbody>';
        names.forEach(function (name) {
            html += '<tr><td><code>' + escHtml(name) + '</code></td><td>' + escHtml(String(counts[name].rows || 0)) + '</td></tr>';
        });
        return html + '</tbody></table></div>';
    }

    function srTotalRows(counts) {
        var total = 0;
        Object.keys(counts || {}).forEach(function (name) {
            total += parseInt(counts[name].rows, 10) || 0;
        });
        return total;
    }

    function srWarningsHtml(warnings) {
        if (!warnings || !warnings.length) {
            return '';
        }
        return '<div class="ocm-sr-warnings"><strong>' + escHtml(t('srWarnings')) + '</strong><ul>'
            + warnings.map(function (w) { return '<li>' + escHtml(w) + '</li>'; }).join('') + '</ul></div>';
    }

    function srRenderPreview(counts, samples, siteAddress, warnings) {
        var rows = srTotalRows(counts);
        var tables = Object.keys(counts).length;
        $('#ocm-sr-result-title').text(t('srPreviewTitle'));
        $('#ocm-sr-result-hint').text('');
        if (!rows) {
            $('#ocm-sr-result-body').html('<p class="ocm-local-empty">' + escHtml(t('srPreviewNone')) + '</p>' + srWarningsHtml(warnings));
            return;
        }
        var html = '<p class="ocm-sr-summary"><strong>' + escHtml(fmt(t('srPreviewSummary'), rows.toLocaleString(), tables)) + '</strong></p>';
        if (siteAddress) {
            html += '<p class="ocm-sr-site-warn">' + escHtml(t('srSiteAddressWarn')) + '</p>';
        }
        html += srCountsTable(counts) + srWarningsHtml(warnings);
        if (samples.length) {
            html += '<div class="ocm-sr-samples"><div class="ocm-label">' + escHtml(t('srSamplesTitle')) + '</div>';
            samples.forEach(function (sm) {
                html += '<div class="ocm-sr-sample"><div class="ocm-sr-sample-where"><code>' + escHtml(sm.table) + '.' + escHtml(sm.column) + '</code></div>'
                    + '<div class="ocm-sr-before"><span>' + escHtml(t('srBefore')) + '</span>' + escHtml(sm.before) + '</div>'
                    + '<div class="ocm-sr-after"><span>' + escHtml(t('srAfter')) + '</span>' + escHtml(sm.after) + '</div></div>';
            });
            html += '</div>';
        }
        $('#ocm-sr-result-body').html(html);
    }

    function srRequestError(xhrOrResponse) {
        return runRequestError(xhrOrResponse);
    }

    function srPreview() {
        var input = srInput();
        var problem = srValidate(input);
        srSetMsg(problem);
        if (problem) {
            return;
        }
        var epoch = ++srPreviewEpoch;
        var key = srKey(input);
        var counts = {};
        var samples = [];
        var warnings = [];
        var objectsSkipped = 0;
        var siteAddress = false;
        srPreviewKey = '';
        srPreviewRows = 0;
        srSetFormBusy(true);
        $('#ocm-sr-result-title').text(t('srPreviewTitle'));
        $('#ocm-sr-result-body').empty();
        srShowProgress(0, t('srPreviewing'), 'running');

        function step(tableIndex, lastKey) {
            $.post(siteData.ajaxurl, $.extend({}, input, {
                action: 'ocm_sr_preview',
                nonce: srNonce(),
                table_index: tableIndex,
                last_key: lastKey ? JSON.stringify(lastKey) : '',
                samples_left: Math.max(0, SR_SAMPLE_LIMIT - samples.length)
            })).done(function (response) {
                if (epoch !== srPreviewEpoch) {
                    return;
                }
                if (!response || !response.success) {
                    srSetFormBusy(false);
                    $('#ocm-sr-progress').prop('hidden', true);
                    srSetMsg(localAjaxMessage(response) || t('srConnectionLost'));
                    return;
                }
                var d = response.data || {};
                Object.keys(d.counts || {}).forEach(function (name) {
                    if (!counts[name]) {
                        counts[name] = { rows: 0, cells: 0 };
                    }
                    counts[name].rows += parseInt(d.counts[name].rows, 10) || 0;
                    counts[name].cells += parseInt(d.counts[name].cells, 10) || 0;
                });
                (d.samples || []).forEach(function (sm) {
                    if (samples.length < SR_SAMPLE_LIMIT) {
                        samples.push(sm);
                    }
                });
                if (d.warning) {
                    warnings.push(d.warning);
                }
                objectsSkipped += parseInt(d.objects_skipped, 10) || 0;
                siteAddress = siteAddress || !!d.site_address;
                srShowProgress(d.progress || 0, t('srPreviewing'), 'running');
                if (!d.done) {
                    step(d.table_index, d.last_key);
                    return;
                }
                $('#ocm-sr-progress').prop('hidden', true);
                if (objectsSkipped > 0) {
                    // Text inside stored objects is never changed; say how many values keep it.
                    warnings.push(fmt(t(objectsSkipped === 1 ? 'srObjectsSkippedOne' : 'srObjectsSkippedMany'), objectsSkipped.toLocaleString()));
                }
                srPreviewKey = key;
                srPreviewRows = srTotalRows(counts);
                srPreviewSiteAddress = siteAddress;
                srSetFormBusy(false);
                srRenderPreview(counts, samples, siteAddress, warnings);
            }).fail(function (xhr) {
                if (epoch !== srPreviewEpoch) {
                    return;
                }
                srSetFormBusy(false);
                $('#ocm-sr-progress').prop('hidden', true);
                srSetMsg(srRequestError(xhr));
            });
        }
        step(0, null);
    }

    function srStartReplace() {
        var input = srInput();
        if (srPreviewKey === '' || srPreviewKey !== srKey(input)) {
            srSetMsg(t('srPreviewStale'));
            srSyncReplaceButton();
            return;
        }
        var question = fmt(t('srConfirm'), input.search, input.replace, srPreviewRows.toLocaleString());
        if (srPreviewSiteAddress) {
            question += '\n\n' + t('srSiteAddressWarn');
        }
        if (typeof window.confirm === 'function' && !window.confirm(question)) {
            return;
        }
        srSetMsg('');
        srSetFormBusy(true);
        $.post(siteData.ajaxurl, $.extend({}, input, { action: 'ocm_sr_start', nonce: srNonce() }))
            .done(function (response) {
                if (!response || !response.success) {
                    srSetFormBusy(false);
                    srSetMsg(localAjaxMessage(response) || t('srConnectionLost'));
                    return;
                }
                srPreviewKey = '';
                srRenderRun(response.data);
            })
            .fail(function (xhr) {
                srSetFormBusy(false);
                srSetMsg(srRequestError(xhr));
            });
    }

    // ------------------------------------------------------------------
    // A database change with a backup first and Undo (Search & Replace,
    // Site Cleanup): the run card, the step loop, Stop, Undo and Done.
    // cfg: prefix ('sr' | 'cl', the element ids are #ocm-<prefix>-…),
    // actions {step, stop, undo, dismiss}, nonce(), working (the state while
    // it changes the database), runTitle, workingText(run), body(run) -> html,
    // stopConfirm, setFormBusy(busy), setMsg(text), onEnded(run), onDismiss().
    // ------------------------------------------------------------------
    function showRunProgress(prefix, pct, text, state) {
        var $fill = $('#ocm-' + prefix + '-pg-fill');
        $('#ocm-' + prefix + '-progress').prop('hidden', false);
        $fill.css('width', Math.max(0, Math.min(100, pct)) + '%')
            .toggleClass('running', state === 'running')
            .toggleClass('ocm-pg-done', state === 'done')
            .toggleClass('ocm-pg-failed', state === 'failed');
        $('#ocm-' + prefix + '-pg-status').text(text || '');
    }

    // WordPress refused the request because the admin is no longer signed in
    // ("0", status 400) or the page's security token expired ("-1", 403).
    // Retrying can't help; only reloading the page (and signing in) can.
    function runSessionExpired(xhrOrResponse) {
        if (!xhrOrResponse || localAjaxMessage(xhrOrResponse)) {
            return false;
        }
        var status = parseInt(xhrOrResponse.status, 10) || 0;
        var body = typeof xhrOrResponse.responseText === 'string' ? xhrOrResponse.responseText.trim() : null;
        return status === 401 || status === 403 || body === '-1' || (status === 400 && body === '0');
    }

    function runRequestError(xhrOrResponse) {
        if (runSessionExpired(xhrOrResponse)) {
            return t('runSessionExpired');
        }
        return localAjaxMessage(xhrOrResponse) || t('srConnectionLost');
    }

    function makeGuardedRun(cfg) {
        var timer = null;
        var errors = 0;
        var MAX_ERRORS = 20;

        function el(name) {
            return $('#ocm-' + cfg.prefix + '-' + name);
        }

        function isRunning(run) {
            return !!run && (run.state === 'backing_up' || run.state === cfg.working);
        }

        function render(run) {
            clearTimeout(timer);
            if (!run || !run.state || run.state === 'idle') {
                el('run-actions').prop('hidden', true);
                el('progress').prop('hidden', true);
                cfg.setFormBusy(false);
                return;
            }
            var running = isRunning(run);
            cfg.setFormBusy(running);
            el('run-actions').prop('hidden', false);
            el('btn-stop').prop('hidden', !running).prop('disabled', false);
            el('btn-undo').prop('hidden', running || !run.can_undo || !!run.undone).prop('disabled', false);
            el('btn-dismiss').prop('hidden', running).prop('disabled', false);
            el('btn-undo').data('backupTime', run.backup_time || 0);

            if (run.state === 'backing_up') {
                el('result-title').text(t(cfg.runTitle));
                showRunProgress(cfg.prefix, parseInt(run.backup_progress, 10) || 2, t('srBackingUp') + (run.backup_text ? ' ' + run.backup_text : ''), 'running');
                el('result-body').html(cfg.body(run));
            } else if (run.state === cfg.working) {
                el('result-title').text(t(cfg.runTitle));
                showRunProgress(cfg.prefix, run.progress || 0, cfg.workingText(run), 'running');
                el('result-body').html(cfg.body(run));
            } else {
                var title = run.state === 'done' ? t('srDone') : (run.state === 'stopped' ? t('srStopped') : t('srFailed'));
                el('result-title').text(title);
                showRunProgress(cfg.prefix, run.state === 'done' ? 100 : (run.progress || 0), run.message || '', run.state === 'done' ? 'done' : 'failed');
                var extra = '';
                if (run.undone) {
                    extra = '<p class="ocm-sr-note">' + escHtml(t('srUndone')) + '</p>';
                } else if (run.backup_id && !run.backup_exists) {
                    extra = '<p class="ocm-sr-note">' + escHtml(t('srBackupGone')) + '</p>';
                }
                el('result-body').html(cfg.body(run) + srWarningsHtml(run.warnings) + extra);
            }
            if (cfg.onEnded) {
                cfg.onEnded(run, running);
            }
            if (running) {
                timer = setTimeout(step, run.state === 'backing_up' || run.busy ? 3000 : 200);
            }
        }

        function step() {
            $.post(siteData.ajaxurl, { action: cfg.actions.step, nonce: cfg.nonce() })
                .done(function (response) {
                    if (!response || !response.success) {
                        stepFailed(response);
                        return;
                    }
                    errors = 0;
                    render(response.data);
                })
                .fail(stepFailed);
        }

        // A step that got no answer keeps the run where it was; try again a
        // bit later, and say why the bar is not moving.
        function stepFailed(source) {
            errors++;
            el('pg-status').text(runRequestError(source));
            if (runSessionExpired(source)) {
                return; // signed out: every retry would be refused too
            }
            if (errors < MAX_ERRORS) {
                clearTimeout(timer);
                timer = setTimeout(step, Math.min(30000, 2000 * errors));
            }
        }

        function action(name, $button, after) {
            $button.prop('disabled', true);
            $.post(siteData.ajaxurl, { action: name, nonce: cfg.nonce() })
                .done(function (response) {
                    $button.prop('disabled', false);
                    if (!response || !response.success) {
                        cfg.setMsg(localAjaxMessage(response) || t('srConnectionLost'));
                        el('pg-status').text(localAjaxMessage(response) || t('srConnectionLost'));
                        return;
                    }
                    after(response.data);
                })
                .fail(function (xhr) {
                    $button.prop('disabled', false);
                    el('pg-status').text(runRequestError(xhr));
                });
        }

        function stop() {
            if (typeof window.confirm === 'function' && !window.confirm(t(cfg.stopConfirm))) {
                return;
            }
            clearTimeout(timer);
            action(cfg.actions.stop, el('btn-stop'), render);
        }

        function undo() {
            var when = parseInt(el('btn-undo').data('backupTime'), 10) || 0;
            var label = when ? new Date(when * 1000).toLocaleString() : '';
            if (typeof window.confirm === 'function' && !window.confirm(fmt(t('srUndoConfirm'), label))) {
                return;
            }
            action(cfg.actions.undo, el('btn-undo'), function () {
                el('btn-undo').prop('hidden', true);
                el('pg-status').text(t('srUndoStarted'));
                localCurrentAction = 'restore';
                setPluginMode('local', true);
                startLocalProgressPolling();
            });
        }

        function dismiss() {
            action(cfg.actions.dismiss, el('btn-dismiss'), function (data) {
                render(data);
                if (cfg.onDismiss) {
                    cfg.onDismiss();
                }
            });
        }

        function bind() {
            el('btn-stop').on('click', stop);
            el('btn-undo').on('click', undo);
            el('btn-dismiss').on('click', dismiss);
        }

        return { render: render, isRunning: isRunning, bind: bind };
    }

    var srRun = makeGuardedRun({
        prefix: 'sr',
        actions: { step: 'ocm_sr_step', stop: 'ocm_sr_stop', undo: 'ocm_sr_undo', dismiss: 'ocm_sr_dismiss' },
        nonce: srNonce,
        working: 'replacing',
        runTitle: 'srRunTitle',
        stopConfirm: 'srStopConfirm',
        workingText: function (run) {
            return fmt(t('srReplacing'), run.tables_done, run.tables_total);
        },
        body: function (run) {
            var head = '<p class="ocm-sr-summary"><code>' + escHtml(run.search) + '</code> &rarr; <code>' + escHtml(run.replace) + '</code></p>';
            return run.state === 'backing_up' ? head : head + srCountsTable(run.counts);
        },
        setFormBusy: function (busy) { srSetFormBusy(busy); },
        setMsg: function (text) { srSetMsg(text); },
        onDismiss: function () {
            $('#ocm-sr-result-title').text(t('srPreviewTitle'));
            $('#ocm-sr-result-body').empty();
        }
    });

    function srIsRunning(run) {
        return srRun.isRunning(run);
    }

    function srRenderRun(run) {
        srRun.render(run);
    }

    // ------------------------------------------------------------------
    // Site Cleanup
    // ------------------------------------------------------------------
    var clScanEpoch = 0;
    var clScanDone = false;

    function clNonce() {
        return (typeof siteData !== 'undefined' && siteData.cleanupNonce) || '';
    }

    function clSetMsg(text) {
        $('#ocm-cl-msg').text(text || '').prop('hidden', !text);
    }

    function clChosen() {
        var items = [];
        $('input[data-ocm-cl-item]:checked:not(:disabled)').each(function () {
            items.push($(this).attr('data-ocm-cl-item'));
        });
        return items;
    }

    function clSyncCleanButton() {
        $('#ocm-cl-btn-clean').prop('disabled', !clScanDone || !clChosen().length);
    }

    function clSetFormBusy(busy) {
        $('#ocm-cl-form-card').find('input, button').prop('disabled', !!busy);
        if (!busy) {
            $('input[data-ocm-cl-item][data-empty="1"]').prop('disabled', true);
            clSyncCleanButton();
        }
    }

    function clItemMeta(item) {
        if (!item.count) {
            return t('clNothing');
        }
        if (item.id === 'reclaim') {
            return fmt(t('clTablesMeta'), item.count.toLocaleString(), item.size);
        }
        return fmt(t('clItemMeta'), item.count.toLocaleString(), item.size);
    }

    // The checklist from a scan. Ticks the user changed survive a rescan.
    function clRenderScan(scan) {
        var previous = {};
        $('input[data-ocm-cl-item]').each(function () {
            previous[$(this).attr('data-ocm-cl-item')] = $(this).is(':checked');
        });
        var html = '<div class="ocm-cl-items">';
        (scan.items || []).forEach(function (item) {
            var empty = !item.count;
            var checked = !empty && (Object.prototype.hasOwnProperty.call(previous, item.id) ? previous[item.id] : !!item.default);
            html += '<label class="ocm-check ocm-cl-item' + (empty ? ' ocm-cl-empty' : '') + '">'
                + '<input type="checkbox" data-ocm-cl-item="' + escHtml(item.id) + '"' + (checked ? ' checked' : '')
                + (empty ? ' disabled data-empty="1"' : '') + '>'
                + '<span><strong>' + escHtml(item.label) + '</strong> <em class="ocm-cl-meta">' + escHtml(clItemMeta(item)) + '</em>'
                + '<small>' + escHtml(item.help) + '</small></span></label>';
        });
        html += '</div>';
        $('#ocm-cl-items').html(html);
        clScanDone = !!scan.done;
        clRenderScanSummary(scan);
        clSyncCleanButton();
    }

    function clRenderScanSummary(scan) {
        $('#ocm-cl-result-title').text(t('clScanTitle'));
        var when = scan.scanned_at ? new Date(scan.scanned_at * 1000).toLocaleString() : '';
        $('#ocm-cl-result-hint').text(when ? fmt(t('clScannedAt'), when) : '');
        var anything = (scan.items || []).some(function (item) { return item.count > 0 && item.id !== 'reclaim'; });
        $('#ocm-cl-result-body').html(anything
            ? '<p class="ocm-sr-summary"><strong>' + escHtml(fmt(t('clScanSummary'), scan.total)) + '</strong></p>'
            : '<p class="ocm-local-empty">' + escHtml(t('clScanNothing')) + '</p>');
    }

    function clScan() {
        var epoch = ++clScanEpoch;
        clSetMsg('');
        clScanDone = false;
        clSetFormBusy(true);
        $('#ocm-cl-migrate').prop('hidden', true);
        showRunProgress('cl', 0, t('clScanning'), 'running');

        function step(restart) {
            $.post(siteData.ajaxurl, { action: 'ocm_cleanup_scan', nonce: clNonce(), restart: restart ? 1 : 0 })
                .done(function (response) {
                    if (epoch !== clScanEpoch) {
                        return;
                    }
                    if (!response || !response.success) {
                        clSetFormBusy(false);
                        $('#ocm-cl-progress').prop('hidden', true);
                        clSetMsg(localAjaxMessage(response) || t('srConnectionLost'));
                        return;
                    }
                    var scan = response.data || {};
                    showRunProgress('cl', scan.progress || 0, t('clScanning'), 'running');
                    if (!scan.done) {
                        step(false);
                        return;
                    }
                    $('#ocm-cl-progress').prop('hidden', true);
                    clSetFormBusy(false);
                    clRenderScan(scan);
                })
                .fail(function (xhr) {
                    if (epoch !== clScanEpoch) {
                        return;
                    }
                    clSetFormBusy(false);
                    $('#ocm-cl-progress').prop('hidden', true);
                    clSetMsg(runRequestError(xhr));
                });
        }
        step(true);
    }

    function clStart() {
        var items = clChosen();
        if (!items.length) {
            clSetMsg(t('clNeedItems'));
            return;
        }
        if (typeof window.confirm === 'function' && !window.confirm(t('clConfirm'))) {
            return;
        }
        clSetMsg('');
        clSetFormBusy(true);
        $.post(siteData.ajaxurl, { action: 'ocm_cleanup_start', nonce: clNonce(), items: items })
            .done(function (response) {
                if (!response || !response.success) {
                    clSetFormBusy(false);
                    clSetMsg(localAjaxMessage(response) || t('srConnectionLost'));
                    return;
                }
                clRun.render(response.data);
            })
            .fail(function (xhr) {
                clSetFormBusy(false);
                clSetMsg(runRequestError(xhr));
            });
    }

    function clCountsTable(counts) {
        if (!counts || !counts.length) {
            return '';
        }
        var html = '<div class="ocm-local-table-wrap"><table class="ocm-local-table ocm-sr-counts"><thead><tr>'
            + '<th>' + escHtml(t('clItemCol')) + '</th><th>' + escHtml(t('clRemovedCol')) + '</th></tr></thead><tbody>';
        counts.forEach(function (c) {
            html += '<tr><td>' + escHtml(c.label) + '</td><td>' + escHtml(String(c.rows || 0)) + (c.size && c.rows ? ' · ' + escHtml(c.size) : '') + '</td></tr>';
        });
        return html + '</tbody></table></div>';
    }

    var clRun = makeGuardedRun({
        prefix: 'cl',
        actions: { step: 'ocm_cleanup_step', stop: 'ocm_cleanup_stop', undo: 'ocm_cleanup_undo', dismiss: 'ocm_cleanup_dismiss' },
        nonce: clNonce,
        working: 'cleaning',
        runTitle: 'clRunTitle',
        stopConfirm: 'clStopConfirm',
        workingText: function (run) {
            return fmt(t('clCleaning'), run.items_done, run.items_total) + (run.current_item ? ' (' + run.current_item + ')' : '');
        },
        body: function (run) {
            return run.state === 'backing_up' ? '' : clCountsTable(run.counts);
        },
        setFormBusy: function (busy) { clSetFormBusy(busy); },
        setMsg: function (text) { clSetMsg(text); },
        onEnded: function (run, running) {
            $('#ocm-cl-result-hint').text('');
            $('#ocm-cl-migrate').prop('hidden', running || run.state !== 'done');
            if (!running && run.state === 'done') {
                // The scan is used up: scan again before the next cleanup.
                clScanDone = false;
                $('#ocm-cl-items').empty();
                clSyncCleanButton();
            }
        },
        onDismiss: function () {
            $('#ocm-cl-migrate').prop('hidden', true);
            $('#ocm-cl-result-title').text(t('clScanTitle'));
            $('#ocm-cl-result-body').empty();
        }
    });

    function clRenderSpace(rep) {
        var html = '';
        if (rep.tables && rep.tables.length) {
            html += '<div class="ocm-label">' + escHtml(t('clSpaceTables')) + '</div><ul class="ocm-cl-space-list">';
            rep.tables.forEach(function (tb) {
                html += '<li><code>' + escHtml(tb.name) + '</code> <span>' + escHtml(tb.size_readable) + '</span>'
                    + (tb.free_readable ? ' <em>' + escHtml(fmt(t('clSpaceUnused'), tb.free_readable)) + '</em>' : '') + '</li>';
            });
            html += '</ul>';
        }
        if (rep.folders && rep.folders.length) {
            html += '<div class="ocm-label">' + escHtml(t('clSpaceFolders')) + '</div><ul class="ocm-cl-space-list">';
            rep.folders.forEach(function (f) {
                html += '<li><code>' + escHtml(f.name) + '</code> <span>' + escHtml(f.size_readable) + '</span>'
                    + (f.backup ? ' <strong class="ocm-cl-flag">' + escHtml(t('clSpaceBackup')) + '</strong>' : '')
                    + ' <em>' + escHtml(t(f.moved ? 'clSpaceMoved' : 'clSpaceNotMoved')) + '</em></li>';
            });
            html += '</ul>';
            if (rep.partial) {
                html += '<p class="ocm-sr-note">' + escHtml(t('clSpacePartial')) + '</p>';
            }
        }
        var themes = rep.inactive_themes && rep.inactive_themes.length ? rep.inactive_themes.join(', ') : t('clSpaceNone');
        var plugins = rep.inactive_plugins && rep.inactive_plugins.length ? rep.inactive_plugins.join(', ') : t('clSpaceNone');
        html += '<p class="ocm-cl-inactive">' + escHtml(fmt(t('clSpaceInactiveThemes'), themes))
            + (rep.themes_url && rep.inactive_themes && rep.inactive_themes.length ? ' <a href="' + escHtml(rep.themes_url) + '">' + escHtml(t('clSpaceManageThemes')) + '</a>' : '') + '</p>'
            + '<p class="ocm-cl-inactive">' + escHtml(fmt(t('clSpaceInactivePlugins'), plugins))
            + (rep.plugins_url && rep.inactive_plugins && rep.inactive_plugins.length ? ' <a href="' + escHtml(rep.plugins_url) + '">' + escHtml(t('clSpaceManagePlugins')) + '</a>' : '') + '</p>';
        $('#ocm-cl-space-body').html(html);
    }

    function clLoadSpace() {
        var $body = $('#ocm-cl-space-body');
        $body.html('<p class="ocm-local-empty">' + escHtml(t('clSpaceLoading')) + '</p>');
        $.post(siteData.ajaxurl, { action: 'ocm_cleanup_space', nonce: clNonce() })
            .done(function (response) {
                if (!response || !response.success) {
                    $body.html('<p class="ocm-local-action-msg">' + escHtml(localAjaxMessage(response) || t('srConnectionLost')) + '</p>');
                    return;
                }
                clRenderSpace(response.data || {});
            })
            .fail(function (xhr) {
                $body.html('<p class="ocm-local-action-msg">' + escHtml(runRequestError(xhr)) + '</p>');
            });
    }

    function initSiteCleanup() {
        if (!$('#ocm-cl-form-card').length) {
            return;
        }
        $('#ocm-cl-btn-scan').on('click', function (e) {
            e.preventDefault();
            clScan();
        });
        $('#ocm-cl-btn-clean').on('click', function (e) {
            e.preventDefault();
            clStart();
        });
        $('#ocm-cl-form-card').on('change', 'input[data-ocm-cl-item]', function () {
            clSetMsg('');
            clSyncCleanButton();
        });
        $('#ocm-cl-space-card').on('click', '#ocm-cl-btn-space', clLoadSpace);
        clRun.bind();
        var scan = typeof siteData !== 'undefined' ? siteData.cleanupScan : null;
        if (scan && scan.done) {
            clRenderScan(scan);
        }
        var run = typeof siteData !== 'undefined' ? siteData.cleanupRun : null;
        if (run && run.state && run.state !== 'idle') {
            clRun.render(run);
        }
    }

    function initSearchReplace() {
        if (!$('#ocm-mode-sr').length || !$('#ocm-sr-form-card').length) {
            return;
        }
        $('#ocm-sr-btn-preview').on('click', function (e) {
            e.preventDefault();
            srPreview();
        });
        $('#ocm-sr-btn-replace').on('click', function (e) {
            e.preventDefault();
            srStartReplace();
        });
        srRun.bind();
        $('#ocm-sr-search, #ocm-sr-replace').on('input', function () {
            srSetMsg('');
            srSyncReplaceButton();
        });
        $('#ocm-sr-form-card').on('change', 'input[type=checkbox]', function () {
            srUpdateTablesCount();
            srSyncReplaceButton();
        });
        $('#ocm-sr-tables-all, #ocm-sr-tables-none').on('click', function () {
            $('input[data-ocm-sr-table]').prop('checked', this.id === 'ocm-sr-tables-all');
            srUpdateTablesCount();
            srSyncReplaceButton();
        });
        $('#ocm-sr-preset-https').on('click', function () {
            $('#ocm-sr-search').val($(this).attr('data-from'));
            $('#ocm-sr-replace').val($(this).attr('data-to'));
            srSyncReplaceButton();
            srPreview();
        });
        var run = typeof siteData !== 'undefined' ? siteData.srRun : null;
        if (run && run.state && run.state !== 'idle') {
            if (srIsRunning(run)) {
                $('#ocm-sr-search').val(run.search);
                $('#ocm-sr-replace').val(run.replace);
            }
            srRenderRun(run);
        }
    }

    function setPluginMode(mode, updateUrl) {
        if (mode !== 'local' && mode !== 'sr' && mode !== 'cleanup') {
            mode = 'cloud';
        }
        var isLocal = mode === 'local';
        $('.ocm-mode-btn').removeClass('active').attr('aria-selected', 'false');
        $('.ocm-mode-btn[data-ocm-mode="' + mode + '"]').addClass('active').attr('aria-selected', 'true');
        $('#ocm-mode-cloud').toggleClass('active', mode === 'cloud').prop('hidden', mode !== 'cloud');
        $('#ocm-mode-local').toggleClass('active', isLocal).prop('hidden', !isLocal);
        $('#ocm-mode-sr').toggleClass('active', mode === 'sr').prop('hidden', mode !== 'sr');
        $('#ocm-mode-cleanup').toggleClass('active', mode === 'cleanup').prop('hidden', mode !== 'cleanup');
        if (updateUrl && window.history && window.history.replaceState) {
            try {
                var u = new URL(window.location.href);
                u.searchParams.set('mode', mode);
                window.history.replaceState({}, '', u.pathname + u.search + u.hash);
            } catch (e) { /* ignore */ }
        }
        if (isLocal) {
            maybeShowLocalTelemetryModal();
        }
    }

    function maybeShowLocalTelemetryModal() {
        if (typeof siteData === 'undefined' || !ocmFlagOn(siteData.localTelemetryPrompt)) {
            return;
        }
        var $modal = $('#ocm-local-telemetry-modal');
        if (!$modal.length) return;
        $modal.prop('hidden', false).attr('aria-hidden', 'false').addClass('open');
    }

    function closeLocalTelemetryModal() {
        $('#ocm-local-telemetry-modal').prop('hidden', true).attr('aria-hidden', 'true').removeClass('open');
    }

    function saveLocalTelemetryConsent(consent) {
        var choice = consent === 'opted_in' ? 'opted_in' : 'declined';
        if (typeof siteData !== 'undefined') {
            siteData.localTelemetryPrompt = 0;
            siteData.localTelemetryOptedIn = choice === 'opted_in' ? 1 : 0;
        }
        closeLocalTelemetryModal();
        $('#ocm-local-telemetry-enabled').prop('checked', choice === 'opted_in');
        if (typeof siteData === 'undefined' || !siteData.ajaxurl) return;
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_save_telemetry',
            nonce: localNonce('saveTelemetry'),
            consent: choice
        });
    }

    function localNonce(key) {
        return (typeof siteData !== 'undefined' && siteData.localNonces && siteData.localNonces[key]) ? siteData.localNonces[key] : '';
    }

    // The server's own message from a wp_send_json_error() answer, given the
    // parsed response or the failed request. Empty when there is none: an
    // expired nonce answers "-1", a logged-out admin "0", a crash an HTML page.
    function localAjaxMessage(source) {
        var body = (source && source.responseJSON !== undefined) ? source.responseJSON : source;
        return (body && body.data && typeof body.data.message === 'string') ? body.data.message : '';
    }

    function localAjaxCode(source) {
        var body = (source && source.responseJSON !== undefined) ? source.responseJSON : source;
        return (body && body.data && typeof body.data.code === 'string') ? body.data.code : '';
    }

    // A failed delete / clear, in the card the button is in. Empty text hides it.
    function setLocalActionMsg(id, text) {
        var $msg = $('#' + id);
        $msg.text(text || '').prop('hidden', !text);
    }

    // A note under the local progress bar that polling doesn't overwrite.
    function setLocalNotice(text) {
        var $notice = $('#ocm-local-pg-notice');
        if (text) {
            $notice.text(text).addClass('show').show();
        } else {
            $notice.removeClass('show').hide().empty();
        }
    }

    function localPollingIsOn() {
        return localPollTimerId !== null || localPollDelayId !== null;
    }

    function stopLocalProgressPolling() {
        clearTimeout(localPollDelayId);
        clearInterval(localPollTimerId);
        localPollDelayId = null;
        localPollTimerId = null;
    }

    function setLocalButtonsDisabled(disabled) {
        $('#ocm-local-btn-backup, #ocm-local-save-settings, .ocm-local-restore-open, .ocm-local-delete-btn').prop('disabled', !!disabled);
    }

    function setLocalProgress(pct, text, state) {
        $('#ocm-local-pg-pct').text(pct);
        $('#ocm-local-pg-fill')
            .css('width', pct)
            .toggleClass('running', state === 'running')
            .toggleClass('ocm-pg-done', state === 'done')
            .toggleClass('ocm-pg-failed', state === 'failed');
        if (typeof text === 'string') {
            $('#ocm-local-pg-status').text(text);
        }
        // 'stalled': the page lost the server. The error shows, but the bar
        // doesn't turn red, because the run itself may be fine.
        if (state === 'failed' || state === 'stalled') {
            $('#ocm-local-pg-error').text(text || '').addClass('show').show();
        } else {
            $('#ocm-local-pg-error').removeClass('show').hide().empty();
        }
    }

    function syncLocalScheduleFields() {
        var freq = $('#ocm-local-sched-freq').val() || 'daily';
        $('#ocm-local-sched-dow-wrap').toggle(freq === 'weekly');
        $('#ocm-local-sched-dom-wrap').toggle(freq === 'monthly');
        $('#ocm-local-sched-time-hint').toggle(freq === 'hourly');
    }

    function renderLocalStorageSummary(storage) {
        if (!storage) return;
        $('#ocm-local-stat-count').text(storage.count != null ? String(storage.count) : '0');
        var keep = parseInt(storage.keep_last, 10) || 0;
        $('#ocm-local-stat-retention').text(keep > 0 ? fmt(t('localKeepLastLabel'), keep) : t('localUnlimited'));
        $('#ocm-local-stat-used').text(storage.total_readable || '0 B');
        $('#ocm-local-stat-free').text(storage.free_readable || '');
        var minFree = parseInt(storage.min_free_space_mb, 10) || 0;
        $('#ocm-local-stat-guard').text(minFree > 0 ? fmt(t('localMinGuard'), minFree) : t('localGuardOff'));
        if (storage.low_space) {
            $('#ocm-local-stat-low').prop('hidden', false).text(t('localLowSpace'));
        } else {
            $('#ocm-local-stat-low').prop('hidden', true);
        }
        if (storage.base_dir) {
            $('#ocm-local-stat-path').text(storage.base_dir);
        }
    }

    function localPartLabel(part) {
        var keys = { db: 'localPartDb', themes: 'localPartThemes', plugins: 'localPartPlugins', uploads: 'localPartUploads' };
        return Object.prototype.hasOwnProperty.call(keys, part) ? t(keys[part]) : String(part).toUpperCase();
    }

    function renderLocalPartPills(parts) {
        var html = '<div class="ocm-part-pills">';
        for (var p = 0; p < (parts ? parts.length : 0); p++) {
            html += '<span class="ocm-part-pill">' + escHtml(localPartLabel(parts[p])) + '</span>';
        }
        return html + '</div>';
    }

    // Plain text: the caller escapes it once.
    function localActivityStatusLabel(status) {
        var keys = {
            completed: 'localStatusCompleted',
            failed: 'localStatusFailed',
            skipped: 'localStatusSkipped',
            cancelled: 'localStatusCancelled'
        };
        if (Object.prototype.hasOwnProperty.call(keys, status)) {
            return t(keys[status]);
        }
        var raw = String(status || '');
        return raw.charAt(0).toUpperCase() + raw.slice(1);
    }

    function renderLocalBackupsTable(backups) {
        var $container = $('#ocm-local-backups-list');
        if (!backups || !backups.length) {
            $container.html('<p class="ocm-local-empty">' + escHtml(t('localNoBackups')) + '</p>');
            return;
        }
        var html = '<div class="ocm-local-table-wrap"><table class="ocm-local-table"><thead><tr>'
            + '<th>' + escHtml(t('localDateCol')) + '</th>'
            + '<th>' + escHtml(t('localTypeCol')) + '</th>'
            + '<th>' + escHtml(t('localPartsCol')) + '</th>'
            + '<th>' + escHtml(t('localSizeCol')) + '</th>'
            + '<th>' + escHtml(t('localActionsCol')) + '</th>'
            + '</tr></thead><tbody>';
        for (var i = 0; i < backups.length; i++) {
            var b = backups[i] || {};
            var parts = b.parts ? Object.keys(b.parts) : [];
            var dateStr = b.created_at || b.id || '';
            var trigger = b.trigger === 'scheduled' ? 'scheduled' : 'manual';
            var triggerLabel = trigger === 'scheduled' ? t('localScheduled') : t('localManual');
            html += '<tr data-backup-id="' + escHtml(b.id) + '">'
                + '<td><strong>' + escHtml(dateStr) + '</strong><div class="ocm-local-id-sub"><code>' + escHtml(b.id) + '</code></div></td>'
                + '<td><span class="ocm-trigger-badge ocm-trigger-' + escHtml(trigger) + '">' + escHtml(triggerLabel) + '</span>'
                + (b.label ? ' <span class="ocm-local-row-label">' + escHtml(b.label) + '</span>' : '') + '</td>'
                + '<td>' + renderLocalPartPills(parts) + '</td>'
                + '<td>' + escHtml(b.total_readable || '') + '</td>'
                + '<td class="ocm-local-row-actions">'
                + '<button type="button" class="ocm-btn ocm-btn-primary ocm-btn-sm ocm-local-restore-open"'
                + ' data-backup-id="' + escHtml(b.id) + '"'
                + ' data-backup-date="' + escHtml(dateStr) + '"'
                + ' data-backup-label="' + escHtml(b.label || '') + '"'
                + ' data-backup-size="' + escHtml(b.total_readable || '') + '"'
                + ' data-backup-parts="' + escHtml(JSON.stringify(parts)) + '">' + escHtml(t('localRestoreBtn')) + '</button> '
                + (b.has_log ? ('<button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm ocm-local-view-backup-log" data-backup-id="' + escHtml(b.id) + '">' + escHtml(t('localViewLogBtn')) + '</button> ') : '')
                + '<button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm ocm-local-delete-btn" data-backup-id="' + escHtml(b.id) + '">' + escHtml(t('localDeleteBtn')) + '</button>'
                + '</td></tr>';
        }
        html += '</tbody></table></div>';
        $container.html(html);
    }

    function renderLocalActivityTable(entries) {
        var $container = $('#ocm-local-activity-list');
        if (!$container.length) return;
        if (!entries || !entries.length) {
            $('#ocm-local-clear-activity').prop('hidden', true);
            $container.html('<p class="ocm-local-empty">' + escHtml(t('localNoActivity')) + '</p>');
            return;
        }
        $('#ocm-local-clear-activity').prop('hidden', false);
        var html = '<div class="ocm-local-table-wrap"><table class="ocm-local-table ocm-local-activity-table"><thead><tr>'
            + '<th>' + escHtml(t('localActivityDateCol')) + '</th>'
            + '<th>' + escHtml(t('localActivityOpCol')) + '</th>'
            + '<th>' + escHtml(t('localActivityPartsCol')) + '</th>'
            + '<th>' + escHtml(t('localActivityStatusCol')) + '</th>'
            + '<th>' + escHtml(t('localActivityDetailsCol')) + '</th>'
            + '</tr></thead><tbody>';
        for (var i = 0; i < entries.length; i++) {
            var act = entries[i] || {};
            var opType = act.type === 'restore' || act.type === 'search_replace' || act.type === 'site_cleanup' ? act.type : 'backup';
            var opLabel = { restore: t('localOpRestore'), search_replace: t('localOpSearchReplace'), site_cleanup: t('localOpSiteCleanup') }[opType] || t('localOpBackup');
            var trigger = act.trigger === 'scheduled' ? 'scheduled' : 'manual';
            var triggerLabel = trigger === 'scheduled' ? t('localScheduled') : t('localManual');
            var status = act.status || 'completed';
            var subMeta = [];
            if (act.size_readable) subMeta.push(act.size_readable);
            if (act.duration_readable) subMeta.push(act.duration_readable);
            html += '<tr>'
                + '<td><strong>' + escHtml(act.time_formatted || '') + '</strong>'
                + (subMeta.length ? '<div class="ocm-local-id-sub">' + escHtml(subMeta.join(' · ')) + '</div>' : '')
                + '</td>'
                + '<td><span class="ocm-op-badge ocm-op-' + escHtml(opType) + '">' + escHtml(opLabel) + '</span> '
                + (opType === 'backup' ? '<span class="ocm-trigger-badge ocm-trigger-' + escHtml(trigger) + '">' + escHtml(triggerLabel) + '</span>' : '')
                + (act.label ? ' <span class="ocm-local-row-label">' + escHtml(act.label) + '</span>' : '')
                + '</td>'
                + '<td>' + renderLocalPartPills(act.parts || []) + '</td>'
                + '<td><span class="ocm-status-badge ocm-status-' + escHtml(status) + '">' + escHtml(localActivityStatusLabel(status)) + '</span></td>'
                + '<td class="ocm-activity-details">'
                + (act.message ? '<div>' + escHtml(act.message) + '</div>' : '')
                + (act.backup_id ? '<div class="ocm-local-id-sub"><code>' + escHtml(act.backup_id) + '</code></div>' : '')
                + '</td></tr>';
        }
        html += '</tbody></table></div>';
        $container.html(html);
    }

    function applyGlobalLogPayload(logData) {
        var hasLog = !!(logData && logData.exists && logData.content);
        if (logData && logData.exists && logData.size_readable) {
            $('#ocm-local-log-meta').text(logData.size_readable);
        } else {
            $('#ocm-local-log-meta').text(t('localLogEmpty'));
        }
        if (logData && logData.exists) {
            $('#ocm-local-download-log, #ocm-local-log-download-btn').show().prop('hidden', false);
        } else {
            $('#ocm-local-log-download-btn').prop('hidden', true);
        }
        $('#ocm-local-log-content').text(hasLog ? logData.content : t('localLogEmpty'));
        if (logData && logData.truncated && logData.line_count) {
            $('#ocm-local-log-note').text(fmt(t('localLogTruncated'), logData.line_count));
        } else {
            $('#ocm-local-log-note').text('');
        }
    }

    function loadLocalLog() {
        if (typeof siteData === 'undefined' || !siteData.ajaxurl) return;
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_get_log',
            nonce: localNonce('getLog')
        }).done(function (response) {
            if (!response || !response.success || !response.data) return;
            applyGlobalLogPayload(response.data.log);
            if (response.data.activity !== undefined) {
                renderLocalActivityTable(response.data.activity);
            }
        }).fail(function () {
            $('#ocm-local-log-content').text(t('localLogLoadFailed'));
        });
    }

    function toggleLocalLogViewer() {
        var $btn = $('#ocm-local-log-toggle');
        var $body = $('#ocm-local-log-body');
        var willOpen = $body.prop('hidden');
        $body.prop('hidden', !willOpen);
        $btn.toggleClass('open', willOpen).attr('aria-expanded', willOpen ? 'true' : 'false');
        if (willOpen) {
            $('#ocm-local-log-content').text(t('localLogLoading'));
            loadLocalLog();
        }
    }

    function clearLocalLog() {
        if (typeof window.confirm === 'function' && !window.confirm(t('localClearLogConfirm'))) {
            return;
        }
        setLocalActionMsg('ocm-local-log-msg', '');
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_clear_log',
            nonce: localNonce('clearLog')
        }).done(function (response) {
            if (response && response.success === true) {
                if (response.data) {
                    applyGlobalLogPayload(response.data.log);
                }
            } else {
                setLocalActionMsg('ocm-local-log-msg', localAjaxMessage(response) || t('localClearLogFailed'));
            }
        }).fail(function (xhr) {
            setLocalActionMsg('ocm-local-log-msg', localAjaxMessage(xhr) || t('localClearLogFailed'));
        });
    }

    function clearLocalActivity() {
        if (typeof window.confirm === 'function' && !window.confirm(t('localClearActivityConfirm'))) {
            return;
        }
        setLocalActionMsg('ocm-local-activity-msg', '');
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_clear_activity',
            nonce: localNonce('clearActivity')
        }).done(function (response) {
            if (response && response.success === true) {
                if (response.data) {
                    renderLocalActivityTable(response.data.activity || []);
                }
            } else {
                setLocalActionMsg('ocm-local-activity-msg', localAjaxMessage(response) || t('localClearActivityFailed'));
            }
        }).fail(function (xhr) {
            setLocalActionMsg('ocm-local-activity-msg', localAjaxMessage(xhr) || t('localClearActivityFailed'));
        });
    }

    function openBackupLogModal(backupId) {
        if (!backupId || typeof siteData === 'undefined' || !siteData.ajaxurl) return;
        $('#ocm-local-log-modal-title').text(t('localBackupLogTitle') + ' ' + backupId);
        $('#ocm-local-log-modal-content').text(t('localLogLoading'));
        $('#ocm-local-log-modal').prop('hidden', false).attr('aria-hidden', 'false').addClass('open');
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_get_log',
            nonce: localNonce('getLog'),
            backup_id: backupId
        }).done(function (response) {
            var logData = response && response.success && response.data && response.data.log;
            var txt = (logData && logData.exists && logData.content) ? logData.content : t('localLogEmpty');
            $('#ocm-local-log-modal-content').text(txt);
        }).fail(function () {
            $('#ocm-local-log-modal-content').text(t('localLogLoadFailed'));
        });
    }

    function closeBackupLogModal() {
        $('#ocm-local-log-modal').prop('hidden', true).attr('aria-hidden', 'true').removeClass('open');
    }

    function copyLogElement($pre, $btn) {
        var text = $pre.text() || '';
        if (!text) return;
        var markCopied = function () {
            if (!$btn || !$btn.length) return;
            var prev = $btn.text();
            $btn.text(t('localLogCopied'));
            setTimeout(function () { $btn.text(prev); }, 1500);
        };
        if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(markCopied, function () {});
            return;
        }
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            if (document.execCommand('copy')) {
                markCopied();
            }
        } catch (e) { /* ignore */ }
        document.body.removeChild(ta);
    }

    function refreshLocalBackups() {
        if (typeof siteData === 'undefined' || !siteData.ajaxurl) return;
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_list_backups',
            nonce: localNonce('listBackups')
        }).done(function (response) {
            if (!response || !response.success || !response.data) return;
            var d = response.data;
            renderLocalStorageSummary(d.storage);
            renderLocalBackupsTable(d.backups);
            if (d.activity !== undefined) {
                renderLocalActivityTable(d.activity);
            }
            if (d.log_exists) {
                $('#ocm-local-download-log').show();
                $('#ocm-local-log-download-btn').prop('hidden', false);
            }
            if (d.schedule && d.schedule.enabled && d.next_run_formatted) {
                $('#ocm-local-next-run').text(t('localNextRun') + ' ' + d.next_run_formatted);
            } else {
                $('#ocm-local-next-run').text(t('localScheduleDisabled'));
            }
            if ($('#ocm-local-log-body').length && !$('#ocm-local-log-body').prop('hidden')) {
                loadLocalLog();
            }
        });
    }

    // The run is over (or there never was one): unlock the screen.
    function endLocalRun(pct, text, state) {
        stopLocalProgressPolling();
        localStartUnconfirmed = false;
        localCurrentAction = '';
        setLocalNotice('');
        setLocalProgress(pct, text, state);
        setLocalButtonsDisabled(false);
        refreshLocalBackups();
    }

    function pollLocalProgressOnce() {
        if (typeof siteData === 'undefined' || !siteData.localProgressUrl) return;
        var epoch = localPollEpoch;
        $.ajax({
            url: siteData.localProgressUrl,
            method: 'GET',
            dataType: 'json',
            beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', siteData.restNonce); },
            success: function (res) {
                if (!res || epoch !== localPollEpoch) return;
                localPollErrors = 0;
                if (res.active) {
                    localSawActive = true;
                    if (res.action) localCurrentAction = String(res.action);
                }
                if (localStartPending && (!res.active || res.failed || res.completed || res.value === '100%')) {
                    return;
                }
                if (localStartUnconfirmed && !localSawActive && !res.active) {
                    // The start request never answered and no run has shown
                    // up: whatever the log says belongs to the previous run.
                    handleLocalStartError(t('localStartUnconfirmed'));
                    return;
                }
                $('#ocm-local-download-log').show();
                if (res.failed) {
                    endLocalRun(res.value || '0%', res.text || '', 'failed');
                    return;
                }
                if (res.completed || res.value === '100%') {
                    endLocalRun('100%', res.text || '', 'done');
                    return;
                }
                if (!res.active) {
                    // Nothing is running and nothing finished: stopped, or
                    // reset from another tab. Don't animate forever.
                    endLocalRun(res.value || '0%', res.text || t('localIdleText'), 'idle');
                    return;
                }
                setLocalProgress(res.value || '1%', res.text || '', 'running');
            },
            error: function (xhr) {
                if (epoch !== localPollEpoch) return;
                var status = xhr ? (parseInt(xhr.status, 10) || 0) : 0;
                if (status === 401 || status === 403) {
                    // Logged out (a database restore replaces the site's users)
                    // or the page expired. Say so before reloading, which leads
                    // to the login screen. The restore keeps going on the server.
                    stopLocalProgressPolling();
                    $('#ocm-local-pg-fill').removeClass('running');
                    setLocalNotice(t('localLoggedOut'));
                    clearTimeout(localReloadId);
                    localReloadId = setTimeout(function () { window.location.reload(); }, 8000);
                    return;
                }
                // A 5xx or a dropped connection is normal for a moment while a
                // restore swaps files; give up only when it keeps happening.
                localPollErrors++;
                if (localPollErrors < LOCAL_POLL_MAX_ERRORS) return;
                localPollErrors = 0;
                localStartPending = false;
                localStartUnconfirmed = false;
                localPollEpoch++;
                stopLocalProgressPolling();
                setLocalProgress($('#ocm-local-pg-pct').text() || '0%', t('localProgressLost'), 'stalled');
                setLocalButtonsDisabled(false);
            }
        });
    }

    function startLocalProgressPolling(immediately) {
        stopLocalProgressPolling();
        localPollErrors = 0;
        setLocalButtonsDisabled(true);
        localPollDelayId = setTimeout(function () {
            localPollDelayId = null;
            // Interval first: a first poll that ends the run then clears it.
            localPollTimerId = setInterval(pollLocalProgressOnce, 1500);
            pollLocalProgressOnce();
        }, immediately ? 0 : 1200);
    }

    function beginLocalStart(action) {
        localStartPending = true;
        localStartUnconfirmed = false;
        localSawActive = false;
        localCurrentAction = action || '';
        localPollEpoch++;
        setLocalNotice('');
        startLocalProgressPolling();
    }

    // The start request answered: the new run is in the log now, so polls can
    // be trusted again. With unconfirmed, it didn't answer at all (see
    // localStartUnconfirmed).
    function endLocalStart(unconfirmed) {
        localStartPending = false;
        localStartUnconfirmed = !!unconfirmed;
        localPollEpoch++;
        startLocalProgressPolling(true);
    }

    function handleLocalStartError(msg) {
        localStartPending = false;
        localStartUnconfirmed = false;
        localCurrentAction = '';
        localPollEpoch++;
        stopLocalProgressPolling();
        setLocalButtonsDisabled(false);
        setLocalProgress('0%', msg || t('localStartFailed'), 'failed');
        refreshLocalBackups();
    }

    // A start request that got an HTTP answer without a JSON body: an expired
    // nonce ("-1", 403), a logged-out admin ("0", 400) or a crash page. The
    // run did not start.
    function localStartFailureText(xhr) {
        var status = xhr ? (parseInt(xhr.status, 10) || 0) : 0;
        return (status === 400 || status === 401 || status === 403) ? t('localStartExpired') : t('localStartFailed');
    }

    // No answer (timeout, dropped connection) or a proxy that gave up waiting
    // for PHP: the server may have started the run all the same.
    function localStartMayHaveRun(xhr) {
        var status = xhr ? (parseInt(xhr.status, 10) || 0) : 0;
        return status === 0 || status === 502 || status === 504 || status === 524;
    }

    // PHP warnings printed in front of the JSON (display_errors on) reach us
    // as a string, or as a parse error: read the answer out of the text.
    function localJsonInText(text) {
        if (typeof text !== 'string') return null;
        var at = text.lastIndexOf('{"success":');
        if (at === -1) return null;
        try {
            return JSON.parse(text.slice(at));
        } catch (e) {
            return null;
        }
    }

    function localStartDone(response) {
        var answer = (typeof response === 'string') ? localJsonInText(response) : response;
        if (answer && answer.success === true) {
            endLocalStart(false);
        } else {
            // success:false, or a 200 that isn't our JSON (a login page, "0").
            handleLocalStartError(localAjaxMessage(answer) || t('localStartFailed'));
        }
    }

    function localStartFail(xhr) {
        var msg = localAjaxMessage(xhr);
        var inText = (xhr && xhr.responseJSON === undefined) ? localJsonInText(xhr.responseText) : null;
        if (msg) {
            handleLocalStartError(msg);
        } else if (inText) {
            localStartDone(inText);
        } else if (localStartMayHaveRun(xhr)) {
            endLocalStart(true);
        } else {
            handleLocalStartError(localStartFailureText(xhr));
        }
    }

    function submitLocalBackup() {
        var parts = [];
        $('input[data-ocm-local-part]:checked').each(function () {
            parts.push($(this).attr('data-ocm-local-part'));
        });
        if (!parts.length) {
            $('#ocm-local-part-warning').show();
            return;
        }
        $('#ocm-local-part-warning').hide();
        setLocalButtonsDisabled(true);
        setLocalProgress('1%', t('localBackupStarted'), 'running');
        beginLocalStart('backup');

        $.post(siteData.ajaxurl, {
            action: 'ocm_local_start_backup',
            nonce: localNonce('startBackup'),
            label: $('#ocm-local-label').val() || '',
            parts: parts
        }).done(localStartDone).fail(localStartFail);
    }

    function saveLocalSettings() {
        var parts = [];
        $('input[data-ocm-local-sched-part]:checked').each(function () {
            parts.push($(this).attr('data-ocm-local-sched-part'));
        });
        var $msg = $('#ocm-local-settings-msg').removeClass('ocm-err ocm-ok').text('');
        if (!parts.length) {
            $msg.addClass('ocm-err').text(t('localSelectPart'));
            return;
        }
        // A lower "keep last" deletes the oldest backups as soon as it is saved.
        var keepLast = parseInt($('#ocm-local-keep-last').val(), 10);
        var listed = $('#ocm-local-backups-list tr[data-backup-id]').length;
        if (keepLast > 0 && keepLast < listed && typeof window.confirm === 'function'
            && !window.confirm(fmt(t('localPruneConfirm'), keepLast, listed - keepLast))) {
            return;
        }
        if (typeof siteData !== 'undefined') {
            siteData.localTelemetryPrompt = 0;
        }
        $('#ocm-local-save-settings').prop('disabled', true);
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_save_settings',
            nonce: localNonce('saveSettings'),
            enabled: $('#ocm-local-sched-enabled').is(':checked') ? 1 : 0,
            frequency: $('#ocm-local-sched-freq').val() || 'daily',
            time: $('#ocm-local-sched-time').val() || '02:00',
            day_of_week: $('#ocm-local-sched-dow').val() || '0',
            day_of_month: $('#ocm-local-sched-dom').val() || '1',
            parts: parts,
            keep_last: $('#ocm-local-keep-last').val(),
            min_free_space_mb: $('#ocm-local-min-free').val(),
            telemetry_enabled: $('#ocm-local-telemetry-enabled').is(':checked') ? 1 : 0
        }).done(function (response) {
            $('#ocm-local-save-settings').prop('disabled', false);
            if (!response || !response.success) {
                $msg.addClass('ocm-err').text((response && response.data && response.data.message) || t('localSettingsFailed'));
                return;
            }
            var d = response.data || {};
            $msg.addClass('ocm-ok').text(t('localSettingsSaved'));
            if (d.storage) renderLocalStorageSummary(d.storage);
            if (d.backups) renderLocalBackupsTable(d.backups);
            if (d.schedule && d.schedule.enabled && d.next_run_formatted) {
                $('#ocm-local-next-run').text(t('localNextRun') + ' ' + d.next_run_formatted);
            } else {
                $('#ocm-local-next-run').text(t('localScheduleDisabled'));
            }
        }).fail(function (xhr) {
            $('#ocm-local-save-settings').prop('disabled', false);
            var errMsg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || t('localSettingsFailed');
            $msg.addClass('ocm-err').text(errMsg);
        });
    }

    function openLocalRestoreModal($btn) {
        activeLocalRestoreId = String($btn.attr('data-backup-id') || '');
        var date = String($btn.attr('data-backup-date') || '');
        var label = String($btn.attr('data-backup-label') || '');
        var size = String($btn.attr('data-backup-size') || '');
        var parts = [];
        try {
            parts = JSON.parse(String($btn.attr('data-backup-parts') || '[]'));
        } catch (e) {
            parts = [];
        }
        var summary = date + (label ? ' — ' + label : '') + (size ? ' (' + size + ')' : '');
        $('#ocm-local-restore-summary').text(summary);
        var html = '';
        for (var i = 0; i < parts.length; i++) {
            var p = parts[i];
            html += '<label class="ocm-check"><input type="checkbox" data-ocm-local-restore-part="' + escHtml(p) + '" checked><span>' + escHtml(localPartLabel(p)) + '</span></label>';
        }
        $('#ocm-local-restore-parts').html(html);
        $('#ocm-local-restore-warning').hide();
        $('#ocm-local-restore-modal').prop('hidden', false).attr('aria-hidden', 'false').addClass('open');
    }

    function closeLocalRestoreModal() {
        $('#ocm-local-restore-modal').prop('hidden', true).attr('aria-hidden', 'true').removeClass('open');
    }

    function confirmLocalRestore() {
        if (!activeLocalRestoreId) return;
        var parts = [];
        $('#ocm-local-restore-parts input[data-ocm-local-restore-part]:checked').each(function () {
            parts.push($(this).attr('data-ocm-local-restore-part'));
        });
        if (!parts.length) {
            $('#ocm-local-restore-warning').show();
            return;
        }
        $('#ocm-local-restore-warning').hide();
        closeLocalRestoreModal();
        setLocalButtonsDisabled(true);
        setLocalProgress('1%', t('localRestoreStarted'), 'running');
        beginLocalStart('restore');

        $.post(siteData.ajaxurl, {
            action: 'ocm_local_start_restore',
            nonce: localNonce('startRestore'),
            backup_id: activeLocalRestoreId,
            parts: parts
        }).done(localStartDone).fail(localStartFail);
    }

    function deleteLocalBackup(backupId) {
        if (!backupId) return;
        if (typeof window.confirm === 'function' && !window.confirm(t('localDeleteConfirm'))) {
            return;
        }
        setLocalActionMsg('ocm-local-backups-msg', '');
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_delete_backup',
            nonce: localNonce('deleteBackup'),
            backup_id: backupId
        }).done(function (response) {
            if (response && response.success === true) {
                if (response.data) {
                    renderLocalStorageSummary(response.data.storage);
                    renderLocalBackupsTable(response.data.backups);
                }
            } else {
                setLocalActionMsg('ocm-local-backups-msg', localAjaxMessage(response) || t('localDeleteFailed'));
            }
        }).fail(function (xhr) {
            // Expired page, an operation in progress (409), a backup in use, a failed delete.
            setLocalActionMsg('ocm-local-backups-msg', localAjaxMessage(xhr) || t('localDeleteFailed'));
        });
    }

    // The server did not stop the run: say why and leave the screen as it is.
    // A restore that has already replaced part of the live site refuses to
    // stop (409 restore_cannot_stop) and keeps running, so keep watching it.
    function localCancelRefused(source) {
        setLocalNotice(localAjaxMessage(source) || t('localCancelFailed'));
        if (localAjaxCode(source) === 'restore_cannot_stop') {
            localCurrentAction = 'restore';
            if (!localPollingIsOn()) {
                startLocalProgressPolling(true);
            }
        }
    }

    function cancelLocalOperation() {
        var question = t(localCurrentAction === 'restore' ? 'localCancelRestoreConfirm' : 'localCancelConfirm');
        if (typeof window.confirm === 'function' && !window.confirm(question)) {
            return;
        }
        $.post(siteData.ajaxurl, {
            action: 'ocm_local_cancel',
            nonce: localNonce('cancel')
        }).done(function (response) {
            if (!response || response.success !== true) {
                localCancelRefused(response);
                return;
            }
            localStartPending = false;
            localStartUnconfirmed = false;
            localCurrentAction = '';
            localPollEpoch++;
            stopLocalProgressPolling();
            setLocalNotice('');
            setLocalButtonsDisabled(false);
            setLocalProgress('0%', t('localIdleText'), 'idle');
            refreshLocalBackups();
        }).fail(localCancelRefused);
    }

    // -------- DOMReady --------
    $(function () {
        var startAction = $('#ocmStartAction').val();

        // Plugin warning
        if (typeof siteData !== 'undefined' && siteData.otherPluginsActive > 0) {
            $('#ocm-plugins-warning').css('display', 'flex');
            $('#ocm-other-plugins-count').text(siteData.otherPluginsActive);
            disableButtons();
        }
        $('#ocm-plugins-ack-checkbox').on('change', function () {
            if ($(this).is(':checked')) {
                enableButtons();
            } else {
                disableButtons();
            }
        });

        // Tab clicks
        $('.ocm-tab').on('click', function () { setActiveTab($(this).data('ocm-tab')); });
        // Decoration only: the click rays from the icon flash once above the pressed button.
        // No timer: the class comes off when the flash ends, so the next click can replay it.
        $('.ocm-submit').on('click', function () { $(this).addClass('ocm-burst'); })
            .on('animationend', function () { $(this).removeClass('ocm-burst'); });

        // Help accordion (single-open behavior)
        $('[data-ocm-help] .ocm-help-toggle').on('click', function () {
            var $item = $(this).closest('[data-ocm-help]');
            var willOpen = !$item.hasClass('open');
            $('[data-ocm-help]').removeClass('open');
            if (willOpen) $item.addClass('open');
        });

        // Advanced toggle
        $('#ocm-adv-toggle').on('click', function () {
            var $btn = $(this);
            var $grid = $('#ocm-adv-grid');
            var open = $grid.toggleClass('open').hasClass('open');
            $btn.toggleClass('open', open).attr('aria-expanded', open ? 'true' : 'false');
        });

        // Restore code formatter (XXXX-XXXX as user types)
        $('#ocm_restore_code').on('input', function () {
            clearRestorePreparation();
            var v = ($(this).val() || '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 8);
            if (v.length > 4) v = v.slice(0, 4) + '-' + v.slice(4);
            $(this).val(v);
        });

        // Submit handlers
        $('#ocm-btn-backup').on('click', function (e) {
            e.preventDefault();
            if (buttonIsDisabled(this)) return;
            submitBackup();
        });
        $('input[data-ocm-exclude]').on('change', clearRestorePreparation);
        $('#ocm-btn-restore').on('click', function (e) {
            e.preventDefault();
            if (buttonIsDisabled(this)) return;
            submitRestore();
        });
        $(document).on('click', 'a[data-ocm-pay]', bannerPayClick);

        // Auto-resume polling if a job is in progress on page load
        if (startAction === 'backup' || startAction === 'restore') {
            setActiveTab(startAction);
            displayProgressBar();
        }

        // Post-restore success modal (flag from PHP option or ?ocm_restore_done=1).
        // Extra-domain candidates keep the same screen open until the admin confirms or closes it.
        if (typeof siteData !== 'undefined' && (ocmFlagOn(siteData.showRestoreSuccess) || extraDomainRows().length)) {
            showRestoreSuccessModal(ocmFlagOn(siteData.showReviewCta));
            // Clean the query arg so refresh does not re-open forever if option already cleared
            if (window.history && window.history.replaceState) {
                try {
                    var u = new URL(window.location.href);
                    if (u.searchParams.has('ocm_restore_done')) {
                        u.searchParams.delete('ocm_restore_done');
                        window.history.replaceState({}, '', u.pathname + u.search + u.hash);
                    }
                } catch (e) { /* ignore */ }
            }
        }
        // Mode switcher & Local Backups wiring
        $('.ocm-mode-btn').on('click', function () {
            setPluginMode($(this).attr('data-ocm-mode'), true);
        });
        syncLocalScheduleFields();
        $('#ocm-local-sched-freq').on('change', syncLocalScheduleFields);
        $('#ocm-local-btn-backup').on('click', function (e) {
            e.preventDefault();
            submitLocalBackup();
        });
        $('#ocm-local-save-settings').on('click', function (e) {
            e.preventDefault();
            saveLocalSettings();
        });
        $(document).on('click', '.ocm-local-restore-open', function () {
            openLocalRestoreModal($(this));
        });
        $(document).on('click', '[data-ocm-local-modal-close]', closeLocalRestoreModal);
        $('#ocm-local-restore-confirm').on('click', confirmLocalRestore);
        $(document).on('click', '.ocm-local-delete-btn', function () {
            deleteLocalBackup($(this).attr('data-backup-id'));
        });
        $('#ocm-local-btn-stop').on('click', function (e) {
            e.preventDefault();
            cancelLocalOperation();
        });
        $('#ocm-local-log-toggle').on('click', function (e) {
            e.preventDefault();
            toggleLocalLogViewer();
        });
        $('#ocm-local-log-refresh').on('click', function (e) {
            e.preventDefault();
            loadLocalLog();
        });
        $('#ocm-local-log-copy').on('click', function (e) {
            e.preventDefault();
            copyLogElement($('#ocm-local-log-content'), $(this));
        });
        $('#ocm-local-log-clear').on('click', function (e) {
            e.preventDefault();
            clearLocalLog();
        });
        $('#ocm-local-clear-activity').on('click', function (e) {
            e.preventDefault();
            clearLocalActivity();
        });
        $(document).on('click', '.ocm-local-view-backup-log', function () {
            openBackupLogModal($(this).attr('data-backup-id'));
        });
        $(document).on('click', '[data-ocm-local-log-modal-close]', closeBackupLogModal);
        $('#ocm-local-log-modal-copy').on('click', function (e) {
            e.preventDefault();
            copyLogElement($('#ocm-local-log-modal-content'), $(this));
        });
        $('#ocm-local-telemetry-allow').on('click', function (e) {
            e.preventDefault();
            saveLocalTelemetryConsent('opted_in');
        });
        $('#ocm-local-telemetry-decline').on('click', function (e) {
            e.preventDefault();
            saveLocalTelemetryConsent('declined');
        });
        $(document).on('click', '[data-ocm-local-telemetry-decline]', function (e) {
            e.preventDefault();
            saveLocalTelemetryConsent('declined');
        });
        $(document).on('keydown.ocmLocalTelemetryModal', function (e) {
            if (e.key === 'Escape' && !$('#ocm-local-telemetry-modal').prop('hidden')) {
                saveLocalTelemetryConsent('declined');
            }
        });
        if (!$('#ocm-mode-local').prop('hidden')) {
            maybeShowLocalTelemetryModal();
        }
        initSearchReplace();
        initSiteCleanup();
        // Links that switch tabs: the cleanup hint on Cloud Migration, and
        // "Start a migration" after a cleanup.
        $(document).on('click', '[data-ocm-goto]', function (e) {
            e.preventDefault();
            setPluginMode($(this).attr('data-ocm-goto'), true);
        });
        var srRunning = typeof siteData !== 'undefined' && srIsRunning(siteData.srRun);
        var clRunning = typeof siteData !== 'undefined' && clRun.isRunning(siteData.cleanupRun);
        if (srRunning && startAction !== 'backup' && startAction !== 'restore') {
            setPluginMode('sr', false);
        } else if (clRunning && startAction !== 'backup' && startAction !== 'restore') {
            setPluginMode('cleanup', false);
        } else if (typeof siteData !== 'undefined' && (siteData.localActiveAction === 'backup' || siteData.localActiveAction === 'restore')) {
            if (startAction !== 'backup' && startAction !== 'restore') {
                setPluginMode('local', false);
            }
            localCurrentAction = siteData.localActiveAction;
            startLocalProgressPolling();
        }
    });
})(jQuery);
