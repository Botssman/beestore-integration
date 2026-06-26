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

})(jQuery);
