/* Secure Files administration. Local DataTables; authenticated, nonce-protected AJAX actions. */
jQuery(function ($) {
    'use strict';

    $('.nelxsdst-role-select').select2({ width: '100%', placeholder: nelxsdstAdmin.roles, allowClear: true });

    function showNotification(message, type) {
        var container = $('#nelxsdst-notification-container');
        if (!container.length) { return; }
        var id = 'nelxsdst-notification-' + Date.now();
        var notice = $('<div class="nelxsdst-notification nelxsdst-notification-' + (type === 'error' ? 'error' : 'success') + '"/>').attr('id', id);
        var content = $('<div class="nelxsdst-notification-content"/>');
        content.append($('<span class="nelxsdst-notification-message"/>').text(message));
        content.append($('<button type="button" class="nelxsdst-notification-close" aria-label="' + nelxsdstAdmin.close + '">&times;</button>'));
        notice.append(content).hide();
        container.append(notice);
        notice.slideDown(300);
        var timer = window.setTimeout(function () { dismissNotification(id); }, 5000);
        notice.find('.nelxsdst-notification-close').on('click', function () { window.clearTimeout(timer); dismissNotification(id); });
        notice.on('mouseenter', function () { window.clearTimeout(timer); }).on('mouseleave', function () { timer = window.setTimeout(function () { dismissNotification(id); }, 3000); });
    }

    function dismissNotification(id) {
        var notice = $('#' + id);
        if (notice.length) { notice.slideUp(300, function () { $(this).remove(); }); }
    }

    $('[data-ns-upload]').on('click', function () {
        var section = document.getElementById('nelxsdst-upload');
        if (section) {
            section.open = !section.open;
            if (section.open) { window.setTimeout(function () { $(section).find('[data-ns-dropzone]').trigger('focus'); }, 50); }
        }
    });

    var table;
    if ($('#nelxsdst-files-table').length) {
        table = $('#nelxsdst-files-table').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 10,
            lengthMenu: [[10, 20, 50, 100, -1], [10, 20, 50, 100, nelxsdstAdmin.table.all]],
            searchDelay: 350,
            order: [[4, 'desc']],
            language: nelxsdstAdmin.table,
            layout: { topStart: 'pageLength', topEnd: 'search', bottomStart: 'info', bottomEnd: 'paging' },
            columnDefs: [{ targets: 6, orderable: false, searchable: false }],
            ajax: { url: nelxsdstAdmin.ajaxUrl, type: 'POST', data: function (data) {
                data.action = 'nelxsdst_files_table';
                data._ajax_nonce = nelxsdstAdmin.tableNonce;
                data.source = $('#nsf-source').val();
            } }
        });
        $('#nsf-source').on('change', function () { table.ajax.reload(); });
        table.on('xhr', function (event, settings, json) {
            if (json) { $('[data-ns-count]').text(json.recordsFiltered); $('[data-ns-bytes]').text(json.bytes); }
        });
    }

    function formatBytes(bytes) {
        bytes = Number(bytes || 0);
        if (bytes >= 1073741824) { return (bytes / 1073741824).toFixed(2) + ' GB'; }
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(2) + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(2) + ' KB'; }
        return bytes + ' B';
    }

    function initUploader(root) {
        var input = root.querySelector('.nelxsdst-upload-input');
        var dropzone = root.querySelector('[data-ns-dropzone]');
        var browse = root.querySelector('[data-ns-browse]');
        var list = root.querySelector('[data-ns-upload-files]');
        var error = root.querySelector('[data-ns-upload-error]');
        if (!input || !dropzone || !list) { return; }
        var dragCounter = 0;

        function showError(message) {
            error.textContent = message;
            error.hidden = false;
            window.setTimeout(function () { error.hidden = true; }, 5000);
        }

        function choose() { input.click(); }
        browse.addEventListener('click', function (event) { event.stopPropagation(); choose(); });
        dropzone.addEventListener('click', function (event) { if (!event.target.closest('button')) { choose(); } });
        dropzone.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(); } });
        ['dragenter', 'dragover'].forEach(function (name) { dropzone.addEventListener(name, function (event) { event.preventDefault(); dragCounter++; dropzone.classList.add('is-dragover'); }); });
        ['dragleave', 'drop'].forEach(function (name) { dropzone.addEventListener(name, function (event) { event.preventDefault(); dragCounter = Math.max(0, dragCounter - 1); if (name === 'drop' || !dragCounter) { dropzone.classList.remove('is-dragover'); } }); });
        dropzone.addEventListener('drop', function (event) { uploadFiles(Array.from(event.dataTransfer.files || [])); });
        input.addEventListener('change', function () { uploadFiles(Array.from(input.files || [])); input.value = ''; });

        function uploadFiles(files) {
            files.forEach(function (file) {
                if (Number(nelxsdstAdmin.maxUploadSize || 0) && file.size > Number(nelxsdstAdmin.maxUploadSize)) { showError(nelxsdstAdmin.upload.tooLarge); return; }
                uploadFile(file);
            });
        }

        function uploadFile(file) {
            var card = document.createElement('div');
            card.className = 'nelxsdst-upload-file';
            card.innerHTML = '<div class="nelxsdst-upload-file-icon"><span class="dashicons dashicons-media-document"></span></div>' +
                '<div class="nelxsdst-upload-file-body"><strong class="nelxsdst-upload-file-name"></strong><div class="nelxsdst-upload-file-meta"><span class="nelxsdst-upload-file-size"></span><span>•</span><span class="nelxsdst-upload-file-status"></span></div><div class="nelxsdst-upload-progress-row"><div class="nelxsdst-upload-progress"><span></span></div><strong class="nelxsdst-upload-percent">0%</strong></div></div>' +
                '<button type="button" class="nelxsdst-upload-file-remove" aria-label="' + nelxsdstAdmin.upload.cancel + '">&times;</button>';
            card.querySelector('.nelxsdst-upload-file-name').textContent = file.name;
            card.querySelector('.nelxsdst-upload-file-size').textContent = formatBytes(file.size);
            card.querySelector('.nelxsdst-upload-file-status').textContent = nelxsdstAdmin.upload.preparing;
            list.prepend(card);

            var status = card.querySelector('.nelxsdst-upload-file-status');
            var bar = card.querySelector('.nelxsdst-upload-progress span');
            var percent = card.querySelector('.nelxsdst-upload-percent');
            var remove = card.querySelector('.nelxsdst-upload-file-remove');
            var xhr = new XMLHttpRequest();
            var complete = false;
            remove.addEventListener('click', function () { if (!complete) { xhr.abort(); } card.remove(); });

            var data = new FormData();
            data.append('action', 'nelxsdst_upload');
            data.append('nonce', nelxsdstAdmin.uploadNonce);
            data.append('secure_file', file, file.name);
            status.textContent = nelxsdstAdmin.upload.uploading;
            xhr.open('POST', nelxsdstAdmin.ajaxUrl, true);
            xhr.upload.addEventListener('progress', function (event) {
                if (!event.lengthComputable) { return; }
                var progress = Math.min(100, Math.round((event.loaded / event.total) * 100));
                bar.style.width = progress + '%'; percent.textContent = progress + '%';
            });
            xhr.addEventListener('load', function () {
                complete = true;
                var response = {};
                try { response = JSON.parse(xhr.responseText || '{}'); } catch (e) { response = {}; }
                var message = response && response.data && response.data.message ? response.data.message : nelxsdstAdmin.upload.failed;
                if (xhr.status >= 200 && xhr.status < 300 && response.success) {
                    card.classList.add('is-uploaded');
                    bar.style.width = '100%'; percent.textContent = '100%';
                    status.textContent = nelxsdstAdmin.upload.uploaded;
                    remove.setAttribute('aria-label', nelxsdstAdmin.upload.dismiss);
                    showNotification(message, 'success');
                    if (table) { table.ajax.reload(null, false); }
                } else {
                    card.classList.add('is-error');
                    status.textContent = message;
                    showNotification(message, 'error');
                }
            });
            xhr.addEventListener('error', function () { complete = true; card.classList.add('is-error'); status.textContent = nelxsdstAdmin.upload.failed; showNotification(nelxsdstAdmin.upload.failed, 'error'); });
            xhr.addEventListener('abort', function () { complete = true; });
            xhr.send(data);
        }
    }
    document.querySelectorAll('[data-ns-uploader]').forEach(initUploader);

    $(document).on('submit', '.nelxsdst-delete-form', function (event) {
        if (!window.confirm(nelxsdstAdmin.deleteConfirm)) { event.preventDefault(); return; }
        $(this).find('button[type=submit]').prop('disabled', true).text(nelxsdstAdmin.working);
    });

    $('.nelxsdst-action-form').on('submit', function (event) {
        var form = $(this), button = form.find('button[type=submit]').last();
        // The Integrations save action is outside its form so it can stay sticky across the page.
        if (!button.length && this.id === 'nelxsdst-integrations-form') {
            button = $('button[form="nelxsdst-integrations-form"]').last();
        }
        var original = button.text();
        if (form.find('[name=action]').val() !== 'nelxsdst_save') {
            button.prop('disabled', true).text(nelxsdstAdmin.working);
            return;
        }
        event.preventDefault();
        button.prop('disabled', true).text(nelxsdstAdmin.working);
        $.ajax({ url: nelxsdstAdmin.ajaxUrl, type: 'POST', data: form.serialize(), dataType: 'json' })
            .done(function (result) {
                var message = result && result.data && result.data.message;
                showNotification(message || nelxsdstAdmin.saveFailed, result && result.success ? 'success' : 'error');
            }).fail(function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                showNotification(message || nelxsdstAdmin.saveFailed, 'error');
            }).always(function () { button.prop('disabled', false).text(original); });
    });

    function updateFormControls(card) {
        var secured = card.find('[name="nelxsdst_secure_forms[]"]').prop('checked');
        var grant = card.find('[name="nelxsdst_form_level_access[]"]').prop('checked');
        card.find('.nelxsdst-form-access').prop('hidden', !secured);
        card.find('.nelxsdst-form-roles').prop('hidden', !secured || !grant);
    }
    $('.nelxsdst-form-card').each(function () { updateFormControls($(this)); });
    $('.nelxsdst-form-card input[type=checkbox]').on('change', function () { updateFormControls($(this).closest('.nelxsdst-form-card')); });

    function copyText(text, button) {
        var original = button ? button.textContent : '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                if (button && original.trim()) { button.textContent = nelxsdstAdmin.copied; window.setTimeout(function () { button.textContent = original; }, 1600); }
                else { showNotification(nelxsdstAdmin.copied, 'success'); }
            }).catch(function () { showNotification(nelxsdstAdmin.copyFailed, 'error'); });
            return;
        }
        var area = document.createElement('textarea');
        area.value = text; area.style.position = 'fixed'; area.style.opacity = '0'; document.body.appendChild(area); area.select();
        try { document.execCommand('copy'); showNotification(nelxsdstAdmin.copied, 'success'); } catch (e) { showNotification(nelxsdstAdmin.copyFailed, 'error'); }
        area.remove();
    }

    $(document).on('click', '[data-ns-copy]', function () { copyText(this.dataset.nsCopy || '', this); });

    var formPage = 1; var formCards = $('.nelxsdst-form-card');
    function updateForms() {
        var term = ($('.nelxsdst-form-search').val() || '').toLowerCase();
        var matching = formCards.filter(function () { return (this.dataset.formName || '').includes(term); });
        var pages = Math.max(1, Math.ceil(matching.length / 10)); formPage = Math.min(formPage, pages);
        formCards.prop('hidden', true); matching.slice((formPage - 1) * 10, formPage * 10).prop('hidden', false);
        $('[data-ns-form-page]').text(formPage + ' / ' + pages);
        $('[data-ns-prev]').prop('disabled', formPage <= 1); $('[data-ns-next]').prop('disabled', formPage >= pages);
        $('.nelxsdst-form-pager').prop('hidden', formCards.length <= 10 && !term);
    }
    $('.nelxsdst-form-search').on('input', function () { formPage = 1; updateForms(); });
    $('[data-ns-prev]').on('click', function () { formPage--; updateForms(); });
    $('[data-ns-next]').on('click', function () { formPage++; updateForms(); });
    updateForms();

    function setCardOpen(card, open) {
        card.toggleClass('is-open', open);
        card.find('.nelxsdst-shortcode-card-body').prop('hidden', !open);
        card.find('.nelxsdst-shortcode-card-header').attr('aria-expanded', open ? 'true' : 'false');
    }

    function shortcodeForCard(card) {
        var form = card.find('.nelxsdst-shortcode-form');
        var type = form.find('[name="preset[type]"]').val() === 'file' ? 'file' : 'files';
        var field = $.trim(form.find('[name="preset[field]"]').val() || '');
        var context = form.find('[name="preset[context]"]').val() || 'auto';
        var objectId = parseInt(form.find('[name="preset[object_id]"]').val(), 10) || 0;
        var attachmentId = parseInt(form.find('[name="preset[attachment_id]"]').val(), 10) || 0;
        var perPage = parseInt(form.find('[name="preset[per_page]"]').val(), 10) || 20;
        var repeater = form.find('[name="preset[repeater]"][type=checkbox]').prop('checked') ? 'yes' : 'no';
        var layout = form.find('[name="preset[layout]"]').val() === 'table' ? 'table' : 'cards';
        var presetId = form.find('[name=preset_id]').val() || card.data('preset-id') || '';
        var attrs = [];
        if (field) {
            attrs.push('field="' + field.replace(/"/g, '&quot;') + '"');
            if (context !== 'auto') { attrs.push('context="' + context + '"'); }
            if (objectId) { attrs.push('object_id="' + objectId + '"'); }
            if (type === 'files') {
                attrs.push('repeater="' + repeater + '"');
                if (repeater === 'yes') { attrs.push('layout="' + layout + '"'); }
            }
        } else if (type === 'file' && attachmentId) {
            attrs.push('id="' + attachmentId + '"');
        } else if (type === 'files' && perPage !== 20) {
            attrs.push('per_page="' + Math.max(1, Math.min(100, perPage)) + '"');
        }
        if (presetId) { attrs.push('preset="' + presetId + '"'); }
        return '[nelx_secure_' + type + (attrs.length ? ' ' + attrs.join(' ') : '') + ']';
    }

    function updateShortcodeCard(card) {
        var form = card.find('.nelxsdst-shortcode-form');
        if (!form.length) { return; }
        var type = form.find('[name="preset[type]"]').val();
        var field = $.trim(form.find('[name="preset[field]"]').val() || '');
        var repeater = form.find('[name="preset[repeater]"][type=checkbox]').prop('checked');
        var layout = form.find('[name="preset[layout]"]').val();
        form.find('[data-ns-contextual]').prop('hidden', !field);
        form.find('[data-ns-attachment]').prop('hidden', type !== 'file' || !!field);
        form.find('[data-ns-per-page]').prop('hidden', type !== 'files' || !!field);
        form.find('[data-ns-repeater]').prop('hidden', type !== 'files' || !field);
        form.find('[data-ns-layout]').prop('hidden', type !== 'files' || !field || !repeater);
        form.find('[data-ns-style-cards]').prop('hidden', !(type === 'files' && field && repeater && layout === 'cards'));
        form.find('[data-ns-style-table]').prop('hidden', !(type === 'files' && ((!field) || (field && repeater && layout === 'table'))));
        var code = shortcodeForCard(card);
        card.find('[data-ns-shortcode-title], [data-ns-shortcode-output]').text(code);
    }

    $(document).on('click', '[data-ns-shortcode-toggle]', function (event) {
        if ($(event.target).closest('button').length) { return; }
        var card = $(this).closest('.nelxsdst-shortcode-card'); setCardOpen(card, !card.hasClass('is-open'));
    }).on('keydown', '[data-ns-shortcode-toggle]', function (event) {
        if ((event.key === 'Enter' || event.key === ' ') && !$(event.target).closest('button').length) { event.preventDefault(); $(this).trigger('click'); }
    });

    $(document).on('input change', '.nelxsdst-shortcode-form input, .nelxsdst-shortcode-form select', function () { updateShortcodeCard($(this).closest('.nelxsdst-shortcode-card')); });
    $('.nelxsdst-shortcode-card').each(function () { updateShortcodeCard($(this)); });

    $(document).on('click', '[data-ns-sc-copy]', function (event) { event.stopPropagation(); var card = $(this).closest('.nelxsdst-shortcode-card'); copyText(card.find('[data-ns-shortcode-title]').text(), this); });

    $(document).on('click', '[data-ns-sc-create]', function () {
        var button = $(this), original = button.html(); button.prop('disabled', true).text(nelxsdstAdmin.working);
        $.post(nelxsdstAdmin.ajaxUrl, { action: 'nelxsdst_shortcode_create', nonce: nelxsdstAdmin.shortcodeNonce }, null, 'json')
            .done(function (response) {
                if (!response.success) { showNotification(response.data && response.data.message ? response.data.message : nelxsdstAdmin.saveFailed, 'error'); return; }
                $('[data-ns-shortcode-empty]').remove();
                var card = $(response.data.html); $('[data-ns-shortcode-list]').append(card); updateShortcodeCard(card); setCardOpen(card, true);
                if (card[0] && card[0].scrollIntoView) { card[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                showNotification(response.data.message, 'success');
            }).fail(function () { showNotification(nelxsdstAdmin.saveFailed, 'error'); })
            .always(function () { button.prop('disabled', false).html(original); });
    });

    function saveShortcodeForm(form) {
        if (!form.length || form.data('ns-saving')) { return; }
        var card = form.closest('.nelxsdst-shortcode-card');
        var button = form.find('[data-ns-sc-save]');
        var original = button.html();
        form.data('ns-saving', true);
        button.prop('disabled', true).text(nelxsdstAdmin.working);

        var payload = new FormData(form[0]);
        if (!payload.get('action')) { payload.set('action', 'nelxsdst_shortcode_save'); }
        if (!payload.get('nonce')) { payload.set('nonce', nelxsdstAdmin.shortcodeNonce); }

        $.ajax({
            url: nelxsdstAdmin.ajaxUrl,
            type: 'POST',
            data: payload,
            dataType: 'json',
            processData: false,
            contentType: false
        }).done(function (response) {
            if (!response.success) {
                showNotification(response.data && response.data.message ? response.data.message : nelxsdstAdmin.saveFailed, 'error');
                return;
            }
            if (response.data.html) {
                var replacement = $(response.data.html);
                card.replaceWith(replacement);
                updateShortcodeCard(replacement);
                setCardOpen(replacement, true);
            } else {
                card.attr('data-preset-id', response.data.id);
                form.find('[name=preset_id]').val(response.data.id);
                card.find('[data-ns-shortcode-title], [data-ns-shortcode-output]').text(response.data.shortcode);
            }
            showNotification(response.data.message, 'success');
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
            showNotification(message || nelxsdstAdmin.saveFailed, 'error');
        }).always(function () {
            form.data('ns-saving', false);
            if (document.body.contains(button[0])) { button.prop('disabled', false).html(original); }
        });
    }

    $(document).on('click', '[data-ns-sc-save]', function (event) {
        event.preventDefault();
        event.stopPropagation();
        saveShortcodeForm($(this).closest('.nelxsdst-shortcode-form'));
    });

    $(document).on('submit', '.nelxsdst-shortcode-form', function (event) {
        event.preventDefault();
        saveShortcodeForm($(this));
    });

    $(document).on('click', '[data-ns-sc-duplicate]', function (event) {
        event.stopPropagation(); var card = $(this).closest('.nelxsdst-shortcode-card');
        $.post(nelxsdstAdmin.ajaxUrl, { action: 'nelxsdst_shortcode_duplicate', nonce: nelxsdstAdmin.shortcodeNonce, preset_id: card.data('preset-id') }, null, 'json').done(function (response) {
            if (response.success) { var clone = $(response.data.html); card.after(clone); updateShortcodeCard(clone); showNotification(response.data.message, 'success'); }
            else { showNotification(response.data && response.data.message ? response.data.message : nelxsdstAdmin.saveFailed, 'error'); }
        }).fail(function () { showNotification(nelxsdstAdmin.saveFailed, 'error'); });
    });

    $(document).on('click', '[data-ns-sc-delete]', function (event) {
        event.stopPropagation(); if (!window.confirm(nelxsdstAdmin.shortcodes.deleteConfirm)) { return; }
        var card = $(this).closest('.nelxsdst-shortcode-card');
        $.post(nelxsdstAdmin.ajaxUrl, { action: 'nelxsdst_shortcode_delete', nonce: nelxsdstAdmin.shortcodeNonce, preset_id: card.data('preset-id') }, null, 'json').done(function (response) {
            if (response.success) { card.slideUp(200, function () { $(this).remove(); if (!$('.nelxsdst-shortcode-card').length) { $('[data-ns-shortcode-list]').append('<div class="nelxsdst-shortcode-empty" data-ns-shortcode-empty><p>' + nelxsdstAdmin.shortcodes.empty + '</p></div>'); } }); showNotification(response.data.message, 'success'); }
            else { showNotification(response.data && response.data.message ? response.data.message : nelxsdstAdmin.saveFailed, 'error'); }
        }).fail(function () { showNotification(nelxsdstAdmin.saveFailed, 'error'); });
    });

    function closeLightbox() {
        var lightbox = $('#nelxsdst-lightbox');
        lightbox.fadeOut(200, function () { lightbox.prop('hidden', true); $('#nelxsdst-lightbox-img').attr('src', ''); });
        $('body').removeClass('nelxsdst-lightbox-open');
    }
    $('.nelxsdst-qr-lightbox').on('click', function (event) {
        event.preventDefault(); $('#nelxsdst-lightbox-img').attr('src', $(this).attr('href')); $('#nelxsdst-lightbox').prop('hidden', false).hide().fadeIn(200); $('body').addClass('nelxsdst-lightbox-open'); $('#nelxsdst-lightbox-close').trigger('focus');
    });
    $('#nelxsdst-lightbox-close').on('click', closeLightbox);
    $('#nelxsdst-lightbox').on('click', function (event) { if (event.target === this) { closeLightbox(); } });
    $(document).on('keyup', function (event) { if (event.key === 'Escape' && !$('#nelxsdst-lightbox').prop('hidden')) { closeLightbox(); } });
});
