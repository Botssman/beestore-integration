/* BeeStore Integration admin JS */

(function($) {
        'use strict';

        if (typeof bsiAdmin === 'undefined') {
                return;
        }

        function appendLog(message, level) {
                level = level || 'info';
                var $output = $('.bsi-log-output');
                if ($output.length === 0) {
                        return;
                }
                var now = new Date().toLocaleTimeString();
                var $line = $('<div class="log-line log-' + level + '">[' + now + '] ' + message + '</div>');
                $output.append($line);
                $output.scrollTop($output[0].scrollHeight);
        }

        function setProgress(percent) {
                $('.bsi-progress-fill').css('width', percent + '%');
        }

        function disableButtons(disabled) {
                $('#bsi-import-ftp, #bsi-import-csv').prop('disabled', disabled);
        }

        // Импорт с FTP.
        $('#bsi-import-ftp').on('click', function(e) {
                e.preventDefault();
                if (!confirm(bsiAdmin.i18n.confirm)) {
                        return;
                }
                $('#bsi-import-status').show();
                $('.bsi-log-output').empty();
                setProgress(20);
                appendLog(bsiAdmin.i18n.importing, 'info');
                disableButtons(true);

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_manual_import',
                        nonce: bsiAdmin.nonce,
                        mode: 'ftp'
                }, function(response) {
                        disableButtons(false);
                        setProgress(100);
                        if (response.success) {
                                var data = response.data;
                                appendLog(bsiAdmin.i18n.importDone, 'info');
                                appendLog('Строк обработано: ' + (data.rows_processed || 0), 'info');
                                appendLog('Затрачено: ' + (data.elapsed_seconds || 0) + ' сек', 'info');
                        } else {
                                appendLog(bsiAdmin.i18n.importError + ' ' + (response.data.message || ''), 'error');
                        }
                }).fail(function() {
                        disableButtons(false);
                        setProgress(0);
                        appendLog('AJAX error — смотрите error.log PHP', 'error');
                });
        });

        // Импорт CSV по пути.
        $('#bsi-import-csv').on('click', function(e) {
                e.preventDefault();
                var csvPath = $('#bsi-csv-path').val().trim();
                if (!csvPath) {
                        alert('Укажите путь к CSV');
                        return;
                }
                $('#bsi-import-status').show();
                $('.bsi-log-output').empty();
                setProgress(20);
                appendLog('Запуск импорта CSV: ' + csvPath, 'info');
                disableButtons(true);

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_manual_import',
                        nonce: bsiAdmin.nonce,
                        mode: 'csv',
                        csv_path: csvPath
                }, function(response) {
                        disableButtons(false);
                        setProgress(100);
                        if (response.success) {
                                var data = response.data;
                                appendLog(bsiAdmin.i18n.importDone, 'info');
                                appendLog('Строк обработано: ' + (data.rows_processed || 0), 'info');
                                appendLog('Затрачено: ' + (data.elapsed_seconds || 0) + ' сек', 'info');
                        } else {
                                appendLog(bsiAdmin.i18n.importError + ' ' + (response.data.message || ''), 'error');
                        }
                }).fail(function() {
                        disableButtons(false);
                        setProgress(0);
                        appendLog('AJAX error', 'error');
                });
        });

        // Backfill картинок.
        var backfillOffset = 0;
        var backfillTotal = 0;

        $('#bsi-backfill-images').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $status = $('#bsi-backfill-status');
                $btn.prop('disabled', true);
                $('#bsi-backfill-progress').show();
                backfillOffset = 0;
                backfillTotal = 0;
                $status.html('<span class="bsi-spinner"></span> Запуск...');
                runBackfill();
        });

        function runBackfill() {
                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_backfill_images',
                        nonce: bsiAdmin.nonce,
                        offset: backfillOffset,
                        batch_size: 20
                }, function(response) {
                        if (response.success) {
                                var d = response.data;
                                backfillTotal = d.total;
                                $('#bsi-backfill-counter').text((d.next_offset) + ' / ' + d.total);
                                var percent = d.total > 0 ? Math.round((d.next_offset / d.total) * 100) : 100;
                                $('.bsi-progress-fill').css('width', percent + '%');
                                $('#bsi-backfill-status').html(
                                        '<span style="color:#2e7d32;">✓ Успешно: ' + d.success + ', ошибок: ' + d.failed + '</span>'
                                );

                                if (d.has_more) {
                                        backfillOffset = d.next_offset;
                                        runBackfill();
                                } else {
                                        $('#bsi-backfill-images').prop('disabled', false);
                                        $('#bsi-backfill-status').html(
                                                '<span style="color:#2e7d32;">✓ Готово! Всего: ' + d.total + ', скачано: ' + d.success + ', ошибок: ' + d.failed + '</span>'
                                        );
                                }
                        } else {
                                $('#bsi-backfill-images').prop('disabled', false);
                                $('#bsi-backfill-status').html(
                                        '<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>'
                                );
                        }
                }).fail(function() {
                        $('#bsi-backfill-images').prop('disabled', false);
                        $('#bsi-backfill-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
                });
        }

})(jQuery);
