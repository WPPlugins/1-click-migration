/* OneClick Migration 3.0 admin UI — vanilla JS, no jQuery UI accordion. */
(function ($) {
    'use strict';

    // -------- helpers --------
    var EMAIL_RE = /^([a-zA-Z0-9_.+\-])+@(([a-zA-Z0-9\-])+\.)+([a-zA-Z0-9]{2,})+$/;
    var noticeList = [];
    var timer = null;
    var timerInitiated = 0;
    var stopTimer = false;
    var isRestarted = false;
    var isRestarting = 0;

    function validateEmail(email) {
        return EMAIL_RE.test(email);
    }

    function disableButtons() {
        $('.ocm-button.backup-button, .ocm-button.restore-button').addClass('one-click-disabled');
    }
    function enableButtons() {
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
        if (text.match(/Error/)) {
            if (text.indexOf('SYSLOG: "[PHP ERR][FATAL]') === -1) {
                $('.ocm-info.error').html(text).addClass('show').show();
            }
        } else if (text.match(/Notice:/)) {
            if (noticeList.indexOf(text) === -1) {
                noticeList.push(text);
                $('.ocm-info.notice').append('<span>' + text + '</span><br>').addClass('show').show();
            }
        } else {
            $('#ocm-pg-status').html(text);
        }
    }

    // -------- progress polling --------
    function displayProgressBar() {
        clearMessages();
        var timeoutId, intervalId;

        timeoutId = setTimeout(function () {
            intervalId = setInterval(function () {
                $.ajax({
                    url: siteData.progressUrl,
                    method: 'GET',
                    beforeSend: function (xhr) { xhr.setRequestHeader('X-WP-Nonce', siteData.restNonce); },
                    success: function (response) {
                        if (response.maintenanceMode) {
                            $('.ocm-maintenance-mode-indicator').show();
                        } else {
                            $('.ocm-maintenance-mode-indicator').hide();
                        }

                        renderMessage(response.text);
                        if (response.customNotice) {
                            renderMessage(response.customNotice);
                        }

                        if (response.topBanner) {
                            $('#ocm-top-banner').html(response.topBanner).show();
                        } else {
                            $('#ocm-top-banner').hide().empty();
                        }

                        // Bar + pct
                        $('#ocm-pg-fill').css('width', response.value).addClass('running');
                        $('#ocm-pg-pct').text(response.value);

                        var responseText = response.text || '';

                        if (response.isStopped) {
                            clearInterval(intervalId);
                            clearTimeout(timeoutId);
                            $('#ocm-pg-fill').removeClass('running');
                            enableButtons();
                        } else if ('100%' === response.value) {
                            $('#ocm-pg-fill').removeClass('running');
                            $('.ocm-timer').hide();
                            $('.restart-button').hide();
                            enableButtons();
                            if (responseText.indexOf('Backup completed') !== -1 || responseText.indexOf('Restore completed') !== -1) {
                                clearInterval(intervalId);
                                clearTimeout(timeoutId);
                                if (responseText.indexOf('Restore completed') !== -1 || response.restoreCompleted) {
                                    redirectToPluginPageAfterRestore();
                                }
                            }
                        }

                        if (responseText.indexOf('Process is Restarting') !== -1) {
                            if (isRestarting === 0) {
                                isRestarted = true;
                                isRestarting = 1;
                                displayDecreasingTimer();
                            }
                        }
                    },
                    error: function (xhr) {
                        if (xhr.status === 403) {
                            clearInterval(intervalId);
                            clearTimeout(timeoutId);
                            setTimeout(function () { window.location.reload(); }, 5000);
                        }
                    }
                });
            }, 1000);
        }, 2000);
    }

    function displayDecreasingTimer() {
        var timeOut = siteData.timeout;
        if (timerInitiated === 0 || isRestarted === true) {
            timer = setInterval(function () {
                if (isRestarted) { clearInterval(timer); }
                timeOut = timeOut - 1;
                if (timeOut === 0) {
                    stopTimer = true;
                    isRestarting = 0;
                    clearInterval(timer);
                }
            }, 1000);
            timerInitiated = 1;
            isRestarted = false;
        }
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
            $('#ocm-pg-status').html('<span style="color:var(--ocm-err)">Please enter a valid email.</span>');
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
        setProgress('1%', 'Backup started');
        displayProgressBar();
        displayDecreasingTimer();
        $.get(url).fail(function () { enableButtons(); });
    }

    function submitRestore() {
        clearMessages();
        var raw = ($('#ocm_restore_code').val() || '').replace(/[\s\-]+/g, '').toUpperCase();
        if (raw.length < 6 || raw.length > 32) {
            $('#ocm-pg-status').html('<span style="color:var(--ocm-err)">Paste the 8-character restore code we emailed you (e.g. <code>XXXX-XXXX</code>).</span>');
            return;
        }
        var excludes = getSelectedExcludes();
        if (excludes.length >= 4) {
            $('.ocm-exclusion-warning').show();
            return;
        }
        $('.ocm-exclusion-warning').hide();
        disableButtons();
        var url = $('#ocm-restore-href').attr('href')
            + '&restore_code=' + encodeURIComponent(raw)
            + '&selected=' + encodeURIComponent(excludes.join(','))
            + '&_wpnonce=' + siteData.restoreNonce;
        setProgress('1%', 'Restore started');
        displayProgressBar();
        displayDecreasingTimer();
        $.get(url).fail(function () { enableButtons(); });
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
    function ensureRestoreSuccessModal(withReviewCta) {
        $('#ocm-restore-success-modal').remove();
        var reviewUrl = (typeof siteData !== 'undefined' && siteData.reviewUrl)
            ? siteData.reviewUrl
            : 'https://wordpress.org/plugins/1-click-migration/reviews/#new-post';
        var dismissUrl = (typeof siteData !== 'undefined' && siteData.dismissReviewUrl)
            ? siteData.dismissReviewUrl
            : '';
        var bodyText = withReviewCta
            ? 'Your site was restored successfully. If this plugin helped, leave a quick review on WordPress.org.'
            : 'Your site was restored successfully.';
        var reviewBlock = '';
        if (withReviewCta) {
            reviewBlock = ''
                + '      <a class="ocm-btn ocm-btn-primary ocm-modal-review" id="ocm-review-btn" href="' + reviewUrl + '" target="_blank" rel="noopener noreferrer">Leave a review</a>'
                + (dismissUrl
                    ? '      <a class="ocm-btn-link ocm-modal-never" id="ocm-dismiss-review" href="' + dismissUrl + '">Don\'t ask again</a>'
                    : '');
        }
        var html = ''
            + '<div id="ocm-restore-success-modal" class="ocm-modal" role="dialog" aria-modal="true" aria-labelledby="ocm-restore-success-title" hidden>'
            + '  <div class="ocm-modal-backdrop" data-ocm-modal-close></div>'
            + '  <div class="ocm-modal-card">'
            + '    <button type="button" class="ocm-modal-close" data-ocm-modal-close aria-label="Close">&times;</button>'
            + '    <div class="ocm-modal-icon" aria-hidden="true">✓</div>'
            + '    <h2 id="ocm-restore-success-title" class="ocm-modal-title">Restore complete</h2>'
            + '    <p class="ocm-modal-body">' + bodyText + '</p>'
            + '    <div class="ocm-modal-actions">'
            + reviewBlock
            + '      <button type="button" class="ocm-btn ocm-btn-ghost ocm-modal-dismiss" data-ocm-modal-close>Close</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        $('body').append(html);
        $('#ocm-restore-success-modal').on('click', '[data-ocm-modal-close]', function () {
            hideRestoreSuccessModal();
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
            var $focus = withReviewCta ? $('#ocm-review-btn') : $m.find('[data-ocm-modal-close]').first();
            $focus.trigger('focus');
        }, 50);
    }

    function hideRestoreSuccessModal() {
        var $m = $('#ocm-restore-success-modal');
        if (!$m.length) return;
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
        $('#ocm-btn-restore').on('click', function (e) {
            e.preventDefault();
            if (buttonIsDisabled(this)) return;
            submitRestore();
        });

        // Auto-resume polling if a job is in progress on page load
        if (startAction === 'backup' || startAction === 'restore') {
            setActiveTab(startAction);
            displayProgressBar();
        }

        // Post-restore success modal (flag from PHP option or ?ocm_restore_done=1)
        if (typeof siteData !== 'undefined' && ocmFlagOn(siteData.showRestoreSuccess)) {
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
    });
})(jQuery);
