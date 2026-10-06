(function () {
	'use strict';

	function initResetForm() {
		var form = document.querySelector('[data-etm-reset-form]');

		if (!form) {
			return;
		}

		var checkboxes = Array.prototype.slice.call(
			form.querySelectorAll('input[type="checkbox"][name="reset_groups[]"]')
		);
		var reviewButton = form.querySelector('[data-etm-review]');
		var confirmButton = form.querySelector('[data-etm-confirm]');
		var cancelButton = form.querySelector('[data-etm-cancel]');
		var reviewPanel = form.querySelector('[data-etm-review-panel]');
		var reviewList = form.querySelector('[data-etm-review-list]');
		var countLabel = form.querySelector('[data-etm-selection-count]');
		var helpLabel = form.querySelector('[data-etm-selection-help]');
		var selectAllButton = form.querySelector('[data-etm-select-all]');
		var clearButton = form.querySelector('[data-etm-clear-selection]');

		function getSelected() {
			return checkboxes.filter(function (checkbox) {
				return checkbox.checked;
			});
		}

		function updateSelection() {
			var selected = getSelected();

			checkboxes.forEach(function (checkbox) {
				var card = checkbox.closest('.etm-category-card');
				if (card) {
					card.classList.toggle('is-selected', checkbox.checked);
				}
			});

			reviewButton.disabled = selected.length === 0;
			countLabel.textContent = selected.length + (selected.length === 1 ? ' selected' : ' selected');
			helpLabel.textContent = selected.length === 0
				? 'Select one or more typography properties to continue.'
				: 'Selected properties are ready to review.';

			reviewList.textContent = '';
			selected.forEach(function (checkbox) {
				var item = document.createElement('li');
				item.textContent = checkbox.getAttribute('data-group-label') || checkbox.value;
				reviewList.appendChild(item);
			});
		}

		function showReview() {
			if (getSelected().length === 0) {
				updateSelection();
				return;
			}

			form.setAttribute('data-etm-stage', 'review');
			reviewButton.hidden = true;
			confirmButton.hidden = false;
			cancelButton.hidden = false;
			reviewPanel.hidden = false;
			reviewPanel.scrollIntoView({ block: 'nearest' });
			confirmButton.focus();
		}

		checkboxes.forEach(function (checkbox) {
			checkbox.addEventListener('change', updateSelection);
		});

		selectAllButton.addEventListener('click', function () {
			checkboxes.forEach(function (checkbox) {
				checkbox.checked = true;
			});
			updateSelection();
		});

		clearButton.addEventListener('click', function () {
			checkboxes.forEach(function (checkbox) {
				checkbox.checked = false;
			});
			updateSelection();
			checkboxes[0].focus();
		});

		form.addEventListener('submit', function (event) {
			if (form.getAttribute('data-etm-stage') === 'confirmed') {
				return;
			}

			event.preventDefault();
			showReview();
		});

		confirmButton.addEventListener('click', function () {
			form.setAttribute('data-etm-stage', 'confirmed');
		});

		cancelButton.addEventListener('click', function () {
			form.setAttribute('data-etm-stage', 'selection');
			reviewPanel.hidden = true;
			reviewButton.hidden = false;
			confirmButton.hidden = true;
			updateSelection();
			reviewButton.focus();
		});

		updateSelection();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initResetForm);
	} else {
		initResetForm();
	}
}());
