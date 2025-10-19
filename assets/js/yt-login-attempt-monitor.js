/**
 * YT Login Attempt Monitor - Admin JavaScript
 *
 * @package YT_Login_Attempt_Monitor
 * @version 1.0.0
 */

(function($) {
	'use strict';

	/**
	 * Login Attempt Monitor Handler
	 */
	var LoginMonitor = {

		/**
		 * Initialize the plugin.
		 */
		init: function() {
			this.bindEvents();
			this.setupFilters();
			this.setupAutoRefresh();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			// Delete single log
			$(document).on('click', '.yt-lam-delete-log', this.deleteLog);

			// Clear all logs
			$(document).on('click', '#yt-lam-clear-logs', this.clearAllLogs);

			// Export logs
			$(document).on('click', '#yt-lam-export-logs', this.exportLogs);

			// Filter form submit
			$('.yt-lam-filters form').on('submit', function(e) {
				// Allow form to submit naturally
			});

			// Status filter change
			$('#filter-status').on('change', function() {
				$(this).closest('form').submit();
			});
		},

		/**
		 * Delete single log entry.
		 *
		 * @param {Event} e Click event.
		 */
		deleteLog: function(e) {
			e.preventDefault();

			var $button = $(this);
			var logId = $button.data('log-id');
			var $row = $button.closest('tr');

			if (!confirm(ytLamData.strings.confirmDelete)) {
				return;
			}

			// Disable button
			$button.prop('disabled', true).text('Deleting...');

			// AJAX request
			$.ajax({
				url: ytLamData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'yt_lam_delete_log',
					nonce: ytLamData.nonce,
					log_id: logId
				},
				success: function(response) {
					if (response.success) {
						// Fade out and remove row
						$row.fadeOut(300, function() {
							$(this).remove();

							// Check if table is empty
							if ($('.yt-lam-table tbody tr').length === 0) {
								$('.yt-lam-table tbody').html(
									'<tr><td colspan="6" class="yt-lam-no-logs">' +
									'No login attempts found.' +
									'</td></tr>'
								);
							}

							// Update statistics
							LoginMonitor.updateStatistics();
						});

						LoginMonitor.showMessage(ytLamData.strings.deleteSuccess, 'success');
					} else {
						LoginMonitor.showMessage(response.data.message || ytLamData.strings.error, 'error');
						$button.prop('disabled', false).text('Delete');
					}
				},
				error: function() {
					LoginMonitor.showMessage(ytLamData.strings.error, 'error');
					$button.prop('disabled', false).text('Delete');
				}
			});
		},

		/**
		 * Clear all log entries.
		 *
		 * @param {Event} e Click event.
		 */
		clearAllLogs: function(e) {
			e.preventDefault();

			if (!confirm(ytLamData.strings.confirmClearAll)) {
				return;
			}

			var $button = $(this);

			// Disable button
			$button.prop('disabled', true).text('Clearing...');

			// AJAX request
			$.ajax({
				url: ytLamData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'yt_lam_clear_logs',
					nonce: ytLamData.nonce
				},
				success: function(response) {
					if (response.success) {
						// Reload page to show empty state
						location.reload();
					} else {
						LoginMonitor.showMessage(response.data.message || ytLamData.strings.error, 'error');
						$button.prop('disabled', false).text('Clear All Logs');
					}
				},
				error: function() {
					LoginMonitor.showMessage(ytLamData.strings.error, 'error');
					$button.prop('disabled', false).text('Clear All Logs');
				}
			});
		},

		/**
		 * Export logs to CSV.
		 *
		 * @param {Event} e Click event.
		 */
		exportLogs: function(e) {
			e.preventDefault();

			var $button = $(this);

			// Show loading state
			$button.prop('disabled', true).text('Exporting...');

			// Create form and submit
			var url = ytLamData.ajaxUrl + '?action=yt_lam_export_logs&nonce=' + ytLamData.nonce;

			// Create temporary iframe for download
			var $iframe = $('<iframe>', {
				src: url,
				style: 'display:none;'
			}).appendTo('body');

			// Re-enable button after a delay
			setTimeout(function() {
				$button.prop('disabled', false).text('Export CSV');
				LoginMonitor.showMessage('CSV export started. Check your downloads.', 'success');

				// Remove iframe after download
				setTimeout(function() {
					$iframe.remove();
				}, 5000);
			}, 1000);
		},

		/**
		 * Setup filter functionality.
		 */
		setupFilters: function() {
			// Add clear search button
			var $searchInput = $('.yt-lam-filters input[type="search"]');

			if ($searchInput.val()) {
				var $clearBtn = $('<button>', {
					type: 'button',
					class: 'button yt-lam-clear-search',
					text: '×',
					title: 'Clear search'
				});

				$searchInput.after($clearBtn);

				$clearBtn.on('click', function() {
					$searchInput.val('');
					$(this).closest('form').submit();
				});
			}

			// Highlight search term in results
			if ($searchInput.val()) {
				LoginMonitor.highlightSearchTerm($searchInput.val());
			}
		},

		/**
		 * Highlight search term in table.
		 *
		 * @param {string} term Search term.
		 */
		highlightSearchTerm: function(term) {
			if (!term) return;

			var regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');

			$('.yt-lam-table tbody td').each(function() {
				var $td = $(this);
				var html = $td.html();

				// Skip if already highlighted or contains HTML tags
				if (html.indexOf('<mark>') !== -1 || html.indexOf('<') !== -1) {
					return;
				}

				var newHtml = html.replace(regex, '<mark>$1</mark>');
				$td.html(newHtml);
			});
		},

		/**
		 * Setup auto-refresh functionality.
		 */
		setupAutoRefresh: function() {
			// Add refresh button to page
			var $refreshBtn = $('<button>', {
				type: 'button',
				class: 'button yt-lam-refresh-page',
				html: '↻ Refresh',
				title: 'Refresh log entries'
			});

			$('.yt-lam-bulk-actions').prepend($refreshBtn);

			$refreshBtn.on('click', function() {
				location.reload();
			});

			// Optional: Auto-refresh every 30 seconds (commented out by default)
			// setInterval(function() {
			// 	LoginMonitor.softRefresh();
			// }, 30000);
		},

		/**
		 * Soft refresh (AJAX load new entries).
		 */
		softRefresh: function() {
			// This would require an AJAX endpoint to fetch new entries
			// Implementation depends on requirements
			console.log('Soft refresh triggered');
		},

		/**
		 * Update statistics display.
		 */
		updateStatistics: function() {
			// Decrement total count
			var $totalStat = $('.yt-lam-stat-total .yt-lam-stat-value');
			var currentTotal = parseInt($totalStat.text().replace(/,/g, ''), 10);

			if (!isNaN(currentTotal) && currentTotal > 0) {
				$totalStat.text((currentTotal - 1).toLocaleString());
			}

			// Note: This is a simplified version
			// In production, you might want to fetch updated stats via AJAX
		},

		/**
		 * Show notification message.
		 *
		 * @param {string} message Message text.
		 * @param {string} type    Message type (success, error, info).
		 */
		showMessage: function(message, type) {
			type = type || 'info';

			var $message = $('<div>', {
				class: 'yt-lam-message yt-lam-message-' + type,
				text: message
			});

			// Remove existing messages
			$('.yt-lam-message').remove();

			// Insert message
			$('.wrap h1').after($message);

			// Fade in
			$message.hide().fadeIn(300);

			// Auto remove after 5 seconds
			setTimeout(function() {
				$message.fadeOut(300, function() {
					$(this).remove();
				});
			}, 5000);
		},

		/**
		 * Format timestamp for display.
		 *
		 * @param {string} timestamp MySQL timestamp.
		 * @return {string} Formatted time.
		 */
		formatTimestamp: function(timestamp) {
			var date = new Date(timestamp);
			return date.toLocaleString();
		},

		/**
		 * Add keyboard shortcuts.
		 */
		addKeyboardShortcuts: function() {
			$(document).on('keydown', function(e) {
				// Ctrl/Cmd + R: Refresh
				if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
					e.preventDefault();
					location.reload();
				}

				// Ctrl/Cmd + E: Export
				if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
					e.preventDefault();
					$('#yt-lam-export-logs').click();
				}
			});
		},

		/**
		 * Setup row actions (show on hover).
		 */
		setupRowActions: function() {
			$('.yt-lam-table tbody tr').on('mouseenter', function() {
				$(this).addClass('yt-lam-row-hover');
			}).on('mouseleave', function() {
				$(this).removeClass('yt-lam-row-hover');
			});
		},

		/**
		 * Add IP address lookup.
		 */
		addIPLookup: function() {
			$(document).on('click', 'code', function() {
				var ip = $(this).text().trim();

				if (ip && ip !== '0.0.0.0') {
					var lookupUrl = 'https://whatismyipaddress.com/ip/' + encodeURIComponent(ip);
					window.open(lookupUrl, '_blank');
				}
			});

			// Add cursor pointer to IP addresses
			$('code').css('cursor', 'pointer').attr('title', 'Click to lookup IP address');
		},

		/**
		 * Add user agent tooltip.
		 */
		addUserAgentTooltip: function() {
			$('.yt-lam-user-agent').each(function() {
				var fullUA = $(this).attr('title');

				if (fullUA && fullUA.length > 50) {
					// Truncate display but keep full version in tooltip
					var shortUA = $(this).text();
					$(this).attr('data-full-ua', fullUA);
				}
			});
		},

		/**
		 * Setup column sorting.
		 */
		setupColumnSorting: function() {
			$('.yt-lam-table thead th').on('click', function() {
				var $th = $(this);
				var $table = $th.closest('table');
				var columnIndex = $th.index();
				var sortOrder = $th.hasClass('yt-lam-sort-asc') ? 'desc' : 'asc';

				// Remove existing sort classes
				$table.find('th').removeClass('yt-lam-sort-asc yt-lam-sort-desc');

				// Add new sort class
				$th.addClass('yt-lam-sort-' + sortOrder);

				// Sort rows
				LoginMonitor.sortTable($table, columnIndex, sortOrder);
			});
		},

		/**
		 * Sort table by column.
		 *
		 * @param {jQuery} $table Table element.
		 * @param {number} columnIndex Column index.
		 * @param {string} order Sort order (asc/desc).
		 */
		sortTable: function($table, columnIndex, order) {
			var $tbody = $table.find('tbody');
			var $rows = $tbody.find('tr').get();

			$rows.sort(function(a, b) {
				var aVal = $(a).find('td').eq(columnIndex).text().trim();
				var bVal = $(b).find('td').eq(columnIndex).text().trim();

				if (order === 'asc') {
					return aVal.localeCompare(bVal);
				} else {
					return bVal.localeCompare(aVal);
				}
			});

			$.each($rows, function(index, row) {
				$tbody.append(row);
			});
		}
	};

	/**
	 * Initialize when DOM is ready.
	 */
	$(document).ready(function() {
		// Check if we're on the login log page
		if ($('.yt-lam-table').length > 0) {
			LoginMonitor.init();
			LoginMonitor.addKeyboardShortcuts();
			LoginMonitor.setupRowActions();
			LoginMonitor.addIPLookup();
			LoginMonitor.addUserAgentTooltip();
		}
	});

})(jQuery);
