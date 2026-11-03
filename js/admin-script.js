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
                        } else {
                            $('#ocm-top-banner').hide().empty();
                        }

                        // Bar + pct
                        $('#ocm-pg-fill').css('width', response.value).addClass('running');
                        $('#ocm-pg-pct').text(response.value);

                        var responseHead = statusHead(response.text);

                        if (response.isStopped) {
                            clearInterval(progressIntervalId);
                            clearTimeout(progressTimeoutId);
                            $('#ocm-pg-fill').removeClass('running');
                            enableButtons();
                        } else if ('100%' === response.value) {
                            $('#ocm-pg-fill').removeClass('running');
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

    function restoreStartFailed(message) {
        stopProgressPolling();
        clearRestorePreparation();
        $('#ocm_restore_code, input[data-ocm-exclude]').prop('disabled', false);
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

    function submitRestore() {
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
                restoreStartFailed(response && response.data && response.data.message);
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
                || t('checkFailed'));
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
    });
})(jQuery);
