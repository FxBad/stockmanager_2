const navBar = document.querySelector("nav"),
	menuBtns = document.querySelectorAll(".menu-icon"),
	overlay = document.querySelector(".overlay");

if (navBar && menuBtns.length > 0) {
	menuBtns.forEach((menuBtn) => {
		menuBtn.addEventListener("click", () => {
			navBar.classList.toggle("open");
		});
	});
}

// Category filter for update-stock page (top-level, not nested)
document.addEventListener("DOMContentLoaded", function () {
	const filter = document.getElementById("category-filter");
	if (!filter) return;

	const tbody = document.querySelector(".table-container table tbody");
	const countEl = document.getElementById("filter-count");

	function applyFilter() {
		const valRaw = (filter.value || "").toString();
		const val = valRaw.trim().toLowerCase();
		const rows = tbody.querySelectorAll("tr[data-item-id]");
		let visible = 0;
		rows.forEach((r) => {
			const catRaw = (r.getAttribute("data-category") || "").toString();
			const cat = catRaw.trim().toLowerCase();
			if (!val) {
				r.style.display = "";
				visible++;
			} else if (cat.indexOf(val) !== -1) {
				r.style.display = "";
				visible++;
			} else {
				r.style.display = "none";
			}
		});
		if (countEl) countEl.textContent = visible + " item(s)";
	}

	filter.addEventListener("change", applyFilter);
	// run once to populate count
	applyFilter();
});
if (overlay && navBar) {
	overlay.addEventListener("click", () => {
		navBar.classList.remove("open");
	});
}

// AJAX submit for update-stock page: submit form via fetch and update table rows in-place
document.addEventListener("DOMContentLoaded", function () {
	const pageTitle = document.querySelector(".main-title h2");
	if (!pageTitle || !/Perbarui/i.test(pageTitle.textContent)) return;

	const form = document.querySelector(".table-container form");
	if (!form) return;

	form.addEventListener("submit", function (e) {
		e.preventDefault();

		const submitBtn = this.querySelector('[type="submit"]');
		if (submitBtn) {
			submitBtn.disabled = true;
			submitBtn.innerHTML =
				'<i class="bx bx-loader-alt bx-spin"></i> Memproses...';
		}

		const formData = new FormData(this);

		fetch(window.location.pathname, {
			method: "POST",
			body: formData,
			headers: {
				"X-Requested-With": "XMLHttpRequest",
			},
		})
			.then((r) =>
				r.text().then((txt) => {
					try {
						return JSON.parse(txt);
					} catch (e) {
						console.warn(
							"Invalid JSON response (global update):",
							txt,
						);
						return {
							success: false,
							message: txt || "Kesalahan server",
						};
					}
				}),
			)
			.then((data) => {
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.innerHTML = "Perbarui Stok";
				}

				const container =
					document.querySelector(".main-container") || document.body;

				if (data && data.success) {
					showModal({
						title: "Berhasil",
						message: data.message || "Stock quantities updated",
						type: "success",
						okText: "OK",
					});
				} else {
					showModal({
						title: "Gagal",
						message:
							data && data.message
								? data.message
								: "Pembaruan gagal",
						type: "error",
						okText: "OK",
					});
				}
			})
			.catch((err) => {
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.innerHTML = "Perbarui Stok";
				}
				showModal({
					title: "Gagal",
					message:
						err && err.message ? err.message : "Kesalahan server",
					type: "error",
					okText: "OK",
				});
			});
	});
});
// Generic increment / decrement that accepts either 'qty_<id>' or 'field_<id>' ids
function normalizeDirtyValue(value) {
	if (value === null || value === undefined) return "";
	return String(value).trim();
}

function getRowByItemId(itemId) {
	return document.querySelector('tr[data-item-id="' + itemId + '"]');
}

function isControlDirty(control) {
	if (!control) return false;
	return (
		normalizeDirtyValue(control.value) !==
		normalizeDirtyValue(control.dataset.originalValue)
	);
}

function refreshRowDirtyState(itemId) {
	const row = getRowByItemId(itemId);
	if (!row) return;

	const fieldEl = document.getElementById("field_" + itemId);
	const levelEl = document.getElementById("level_" + itemId);
	const dirty = isControlDirty(fieldEl) || isControlDirty(levelEl);

	row.classList.toggle("is-dirty", dirty);
}

function persistRowOriginalValues(itemId) {
	const fieldEl = document.getElementById("field_" + itemId);
	const levelEl = document.getElementById("level_" + itemId);

	if (fieldEl) fieldEl.dataset.originalValue = fieldEl.value;
	if (levelEl) levelEl.dataset.originalValue = levelEl.value;
}

function markRowDirtyByFieldId(fieldId) {
	if (!fieldId) return;
	const parts = fieldId.split("_");
	const itemId = parts[parts.length - 1];
	if (!itemId) return;
	refreshRowDirtyState(itemId);
}

document.addEventListener("DOMContentLoaded", function () {
	const rows = document.querySelectorAll("tr[data-item-id]");
	if (!rows.length) return;

	rows.forEach((row) => {
		const itemId = row.getAttribute("data-item-id");
		if (!itemId) return;

		const fieldEl = document.getElementById("field_" + itemId);
		const levelEl = document.getElementById("level_" + itemId);

		if (fieldEl && fieldEl.dataset.originalValue === undefined) {
			fieldEl.dataset.originalValue = fieldEl.value;
		}
		if (levelEl && levelEl.dataset.originalValue === undefined) {
			levelEl.dataset.originalValue = levelEl.value;
		}

		if (fieldEl) {
			fieldEl.addEventListener("input", function () {
				refreshRowDirtyState(itemId);
			});
			fieldEl.addEventListener("change", function () {
				refreshRowDirtyState(itemId);
			});
		}

		if (levelEl) {
			levelEl.addEventListener("input", function () {
				refreshRowDirtyState(itemId);
			});
			levelEl.addEventListener("change", function () {
				refreshRowDirtyState(itemId);
			});
		}

		refreshRowDirtyState(itemId);
	});
});

function incrementQty(fieldId) {
	const input = document.getElementById(fieldId);
	if (!input) return;
	input.value = parseInt(input.value || 0) + 1;
	// If there is a matching total element, update it
	const parts = fieldId.split("_");
	const id = parts[parts.length - 1];
	if (document.getElementById("total_" + id)) updateTotal(id);
	markRowDirtyByFieldId(fieldId);
}

function decrementQty(fieldId) {
	const input = document.getElementById(fieldId);
	if (!input) return;
	const newValue = parseInt(input.value || 0) - 1;
	if (newValue >= 0) {
		input.value = newValue;
		const parts = fieldId.split("_");
		const id = parts[parts.length - 1];
		if (document.getElementById("total_" + id)) updateTotal(id);
		markRowDirtyByFieldId(fieldId);
	}
}

// Form Submit Loading State
document.querySelectorAll("form").forEach((form) => {
	form.addEventListener("submit", function (e) {
		const submitBtn = this.querySelector('[type="submit"]');
		if (submitBtn) {
			// Delay disabling so the browser includes the button's name/value
			// in the submitted form data. Disabling immediately can prevent
			// the control from being serialized.
			setTimeout(() => {
				submitBtn.disabled = true;
				submitBtn.innerHTML =
					'<i class="bx bx-loader-alt bx-spin"></i> Memproses...';
			}, 0);
		}
	});
});

// Smooth Scroll to Error Messages
document.addEventListener("DOMContentLoaded", function () {
	const alert = document.querySelector(".alert.error");
	if (alert) {
		alert.scrollIntoView({ behavior: "smooth", block: "center" });
	}
	// Auto-dismiss alerts after 3000ms
	const AUTO_DISMISS_MS = 3000;
	function scheduleAutoDismiss(el) {
		if (!el) return;
		setTimeout(() => {
			if (el && el.parentNode) el.parentNode.removeChild(el);
		}, AUTO_DISMISS_MS);
	}

	// Dismiss any existing alerts
	document.querySelectorAll(".alert").forEach(scheduleAutoDismiss);

	// Observe for new alerts added dynamically and dismiss them as well
	const observer = new MutationObserver((mutations) => {
		for (const m of mutations) {
			for (const node of m.addedNodes) {
				if (node.nodeType === 1 && node.matches(".alert")) {
					scheduleAutoDismiss(node);
				}
				if (node.nodeType === 1) {
					node.querySelectorAll &&
						node
							.querySelectorAll(".alert")
							.forEach(scheduleAutoDismiss);
				}
			}
		}
	});
	observer.observe(document.body, { childList: true, subtree: true });
});

// Centralized Level toggle: show/hide level input groups based on has_level checkbox
function initLevelToggle(root = document) {
	const nameEl = root.querySelector("#name");
	const hasLevelEl = root.querySelector("#has_level");
	const levelGroup = root.querySelector("#level-group");
	if (!levelGroup) return;
	function toggle() {
		if (hasLevelEl) {
			levelGroup.style.display = hasLevelEl.checked ? "" : "none";
			return;
		}

		if (
			nameEl &&
			nameEl.value &&
			nameEl.value.trim().toUpperCase() === "DMDS"
		) {
			levelGroup.style.display = "";
		} else {
			levelGroup.style.display = "none";
		}
	}
	if (hasLevelEl) {
		hasLevelEl.addEventListener("change", toggle);
	}
	if (nameEl) {
		nameEl.addEventListener("input", toggle);
	}
	// run once on init
	toggle();
}

// Auto-init on DOM ready for page-level forms
document.addEventListener("DOMContentLoaded", function () {
	initLevelToggle(document);
});

// Table Row Click Feedback
document.querySelectorAll("tbody tr").forEach((row) => {
	row.addEventListener("click", function () {
		this.style.backgroundColor = "#f8f9fa";
		setTimeout(() => {
			this.style.backgroundColor = "";
		}, 200);
	});
});

// Placeholder area intentionally left without auto-fetch on load

function editItem(itemId) {
	window.location.href = "edit-item.php?id=" + itemId;
}

function updateTotal(itemId) {
	const fieldEl = document.getElementById("field_" + itemId);
	const fieldStock = fieldEl ? parseInt(fieldEl.value) || 0 : 0;
	const qtyEl = document.getElementById("qty_" + itemId);
	const qty = qtyEl ? parseInt(qtyEl.value) || 0 : null;
	const totalElement = document.getElementById("total_" + itemId);
	if (totalElement) {
		if (qty !== null) {
			totalElement.textContent = qty;
		} else {
			totalElement.textContent = fieldStock;
		}
	}
}

function formatLocalDateTime(dateString) {
	if (!dateString)
		return '<span class="never-login"><i class="bx bx-x-circle"></i>Tidak Pernah</span>';

	const date = new Date(dateString);
	const options = {
		day: "2-digit",
		month: "2-digit",
		year: "numeric",
		hour: "2-digit",
		minute: "2-digit",
		hour12: false,
	};

	return `<span class="timestamp"><i class="bx bx-time-five"></i>${date.toLocaleString(
		"en-GB",
		options,
	)}</span>`;
}

// Centralized DOM ready behavior: AJAX delete
document.addEventListener("DOMContentLoaded", function () {
	// Timestamp conversion removed - now handled by PHP server-side

	// Delegate click for AJAX delete buttons
	document.addEventListener("click", function (e) {
		if (!e.target.closest) return;
		const btn = e.target.closest(".confirm-delete");
		if (!btn) return;

		const form = btn.closest("form");
		if (!form) return;

		showModal({
			title: "Konfirmasi",
			message: "Apakah Anda yakin ingin menghapus barang ini?",
			type: "warning",
			okText: "Hapus",
			cancelText: "Batal",
			showCancel: true,
			callback: function (ok) {
				if (!ok) return;

				const formData = new FormData(form);
				const itemId = formData.get("item_id");

				fetch("actions/delete-item.php", {
					method: "POST",
					body: formData,
					headers: {
						"X-Requested-With": "XMLHttpRequest",
					},
				})
					.then((r) =>
						r.text().then((txt) => {
							try {
								return JSON.parse(txt);
							} catch (e) {
								console.warn(
									"Invalid JSON response (delete):",
									txt,
								);
								return {
									success: false,
									message: txt || "Kesalahan server",
								};
							}
						}),
					)
					.then((data) => {
						if (data && data.success) {
							const row = document.querySelector(
								'tr[data-item-id="' + itemId + '"]',
							);
							if (row) row.remove();
							showModal({
								title: "Berhasil",
								message:
									data.message ||
									"Barang berhasil diarsipkan",
								type: "success",
								okText: "OK",
							});
						} else {
							showModal({
								title: "Gagal",
								message:
									data && data.message
										? data.message
										: "Penghapusan gagal",
								type: "error",
								okText: "OK",
							});
						}
					})
					.catch((err) => {
						showModal({
							title: "Gagal",
							message:
								err && err.message
									? err.message
									: "Kesalahan server",
							type: "error",
							okText: "OK",
						});
					});
			},
		});
	});

	// Delegate click for inline save buttons on update-stock page
	document.addEventListener("click", function (e) {
		const btn = e.target.closest && e.target.closest(".btn-save-row");
		if (!btn) return;

		const itemId = btn.getAttribute("data-item-id");
		if (!itemId) return;

		// find inputs for this row
		const fieldEl = document.getElementById("field_" + itemId);
		const levelEl = document.getElementById("level_" + itemId);

		const fd = new FormData();
		fd.append("field_stock[" + itemId + "]", fieldEl ? fieldEl.value : "0");
		if (levelEl) fd.append("level[" + itemId + "]", levelEl.value);

		// Mark a visible indicator
		const origText = btn.textContent;
		btn.disabled = true;
		btn.textContent = "Saving...";

		fetch(window.location.pathname, {
			method: "POST",
			body: fd,
			headers: { "X-Requested-With": "XMLHttpRequest" },
		})
			.then((r) =>
				r.text().then((txt) => {
					try {
						return JSON.parse(txt);
					} catch (e) {
						console.warn(
							"Invalid JSON response (inline-save):",
							txt,
						);
						return { success: false, message: txt };
					}
				}),
			)
			.then((data) => {
				btn.disabled = false;
				btn.textContent = origText;

				const container =
					document.querySelector(".main-container") || document.body;
				if (data && data.success) {
					// find updated item in response (server returns updated array)
					if (Array.isArray(data.updated)) {
						const it = data.updated.find(
							(x) => String(x.id) === String(itemId),
						);
						if (it) {
							if (fieldEl) fieldEl.value = it.field_stock;
							if (
								levelEl &&
								Object.prototype.hasOwnProperty.call(
									it,
									"level",
								)
							) {
								levelEl.value =
									it.level === null ? "" : it.level;
							}

							try {
								updateTotal(itemId);
							} catch (e) {}
						}
					}

					persistRowOriginalValues(itemId);
					refreshRowDirtyState(itemId);

					showModal({
						title: "Berhasil",
						message: data.message || "Stok berhasil diperbarui",
						type: "success",
						okText: "OK",
					});
				} else {
					showModal({
						title: "Gagal",
						message:
							data && data.message
								? data.message
								: "Pembaruan gagal",
						type: "error",
						okText: "OK",
					});
				}
			})
			.catch((err) => {
				btn.disabled = false;
				btn.textContent = origText;
				showModal({
					title: "Gagal",
					message:
						err && err.message ? err.message : "Kesalahan server",
					type: "error",
					okText: "OK",
				});
			});
	});
});

// Custom Modal Utility
function showModal({
	title = "",
	message = "",
	type = "info", // success, error, warning, info, confirm
	okText = "OK",
	cancelText = "Batal",
	showCancel = false,
	icon = "",
	callback = null,
}) {
	// Remove any existing modal
	const old = document.querySelector(".custom-modal-overlay");
	if (old) old.remove();

	const overlay = document.createElement("div");
	overlay.className = "custom-modal-overlay";

	const modal = document.createElement("div");
	modal.className = "custom-modal";

	// Icon
	let iconHtml = "";
	if (icon) {
		iconHtml = `<span class="modal-icon ${type}"><i class="bx ${icon}"></i></span>`;
	} else {
		if (type === "success")
			iconHtml =
				'<span class="modal-icon success"><i class="bx bx-check-circle"></i></span>';
		else if (type === "error")
			iconHtml =
				'<span class="modal-icon error"><i class="bx bx-x-circle"></i></span>';
		else if (type === "warning")
			iconHtml =
				'<span class="modal-icon warning"><i class="bx bx-error"></i></span>';
		else
			iconHtml =
				'<span class="modal-icon info"><i class="bx bx-info-circle"></i></span>';
	}

	modal.innerHTML = `
		<button class="modal-close" aria-label="Close">&times;</button>
		${iconHtml}
		<div class="modal-title">${title}</div>
		<div class="modal-message">${message}</div>
		<div class="modal-actions">
			${showCancel ? `<button class="modal-btn cancel">${cancelText}</button>` : ""}
			<button class="modal-btn ok">${okText}</button>
		</div>
	`;

	overlay.appendChild(modal);
	document.body.appendChild(overlay);

	function closeModal() {
		overlay.remove();
	}

	modal.querySelector(".modal-close").onclick = closeModal;
	modal.querySelector(".modal-btn.ok").onclick = function () {
		closeModal();
		if (callback) callback(true);
	};
	if (showCancel) {
		modal.querySelector(".modal-btn.cancel").onclick = function () {
			closeModal();
			if (callback) callback(false);
		};
	}
	overlay.onclick = function (e) {
		if (e.target === overlay) closeModal();
	};
}

// Autocomplete functionality for search input
document.addEventListener("DOMContentLoaded", function () {
	const searchInput = document.getElementById("search-input");
	const autocompleteList = document.getElementById("autocomplete-list");
	const clearButton = document.getElementById("search-clear-btn");
	const filterForm = searchInput ? searchInput.closest("form") : null;
	const categoryFilter = filterForm
		? filterForm.querySelector('select[name="category"]')
		: null;
	const statusFilter = filterForm
		? filterForm.querySelector('select[name="status"]')
		: null;
	const tableBody = document.querySelector(".table-container table tbody");

	if (!searchInput || !autocompleteList) return;

	let debounceTimer;
	let currentFocus = -1;
	let activeFilterController = null;

	// Debounce function to limit API calls
	function debounce(func, delay) {
		return function (...args) {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(() => func.apply(this, args), delay);
		};
	}

	// Highlight matching text
	function highlightMatch(text, query) {
		const regex = new RegExp(`(${escapeRegex(query)})`, "gi");
		return text.replace(regex, "<strong>$1</strong>");
	}

	// Escape special regex characters
	function escapeRegex(str) {
		return str.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
	}

	// Fetch autocomplete suggestions
	async function fetchSuggestions(query) {
		if (query.length < 1) {
			hideAutocomplete();
			return;
		}

		try {
			// Show loading state
			showLoading();

			const response = await fetch(
				`actions/autocomplete-items.php?term=${encodeURIComponent(query)}`,
			);

			if (!response.ok) {
				throw new Error("Network response was not ok");
			}

			const items = await response.json();

			if (items.length === 0) {
				showNoResults();
			} else {
				displaySuggestions(items, query);
			}
		} catch (error) {
			console.error("Autocomplete error:", error);
			hideAutocomplete();
		}
	}

	// Display suggestions in dropdown
	function displaySuggestions(items, query) {
		autocompleteList.innerHTML = "";
		currentFocus = -1;

		items.forEach((item, index) => {
			const div = document.createElement("div");
			div.className = "autocomplete-item";
			div.innerHTML = highlightMatch(item, query);
			div.dataset.value = item;
			div.dataset.index = index;

			div.addEventListener("click", function () {
				selectItem(this.dataset.value);
			});

			autocompleteList.appendChild(div);
		});

		autocompleteList.classList.add("show");
	}

	// Show loading state
	function showLoading() {
		autocompleteList.innerHTML =
			'<div class="autocomplete-loading">Mencari</div>';
		autocompleteList.classList.add("show");
	}

	// Show no results message
	function showNoResults() {
		autocompleteList.innerHTML =
			'<div class="autocomplete-no-results">Tidak ada hasil ditemukan</div>';
		autocompleteList.classList.add("show");
	}

	// Hide autocomplete dropdown
	function hideAutocomplete() {
		autocompleteList.innerHTML = "";
		autocompleteList.classList.remove("show");
		currentFocus = -1;
	}

	function toggleClearButton() {
		if (!clearButton) return;
		clearButton.classList.toggle("show", searchInput.value.length > 0);
	}

	function getSortParams() {
		const urlParams = new URLSearchParams(window.location.search);
		const defaultSort =
			(filterForm && filterForm.dataset.defaultSort) || "name";
		const defaultDir =
			(filterForm && filterForm.dataset.defaultDir) || "asc";
		return {
			sort: urlParams.get("sort") || defaultSort,
			dir: urlParams.get("dir") || defaultDir,
		};
	}

	function syncFilterQueryToUrl() {
		const url = new URL(window.location.href);
		const nextSearch = searchInput.value.trim();
		const nextCategory = categoryFilter ? categoryFilter.value : "";
		const nextStatus = statusFilter ? statusFilter.value : "";

		if (nextSearch) {
			url.searchParams.set("search", nextSearch);
		} else {
			url.searchParams.delete("search");
		}

		if (nextCategory) {
			url.searchParams.set("category", nextCategory);
		} else {
			url.searchParams.delete("category");
		}

		if (nextStatus) {
			url.searchParams.set("status", nextStatus);
		} else {
			url.searchParams.delete("status");
		}

		history.replaceState(
			null,
			"",
			`${url.pathname}${url.search}${url.hash}`,
		);
	}

	async function updateTableRows() {
		if (!tableBody || !filterForm) return;

		syncFilterQueryToUrl();

		if (activeFilterController) {
			activeFilterController.abort();
		}

		activeFilterController = new AbortController();
		const sortParams = getSortParams();
		const filterContext =
			(filterForm && filterForm.dataset.filterContext) || "view";
		const params = new URLSearchParams({
			search: searchInput.value,
			category: categoryFilter ? categoryFilter.value : "",
			status: statusFilter ? statusFilter.value : "",
			sort: sortParams.sort,
			dir: sortParams.dir,
			context: filterContext,
		});

		try {
			const response = await fetch(
				`actions/filter-items.php?${params.toString()}`,
				{
					headers: {
						"X-Requested-With": "XMLHttpRequest",
					},
					signal: activeFilterController.signal,
				},
			);

			if (!response.ok) {
				throw new Error("Failed to fetch filtered data");
			}

			const data = await response.json();
			if (typeof data.html === "string") {
				tableBody.innerHTML = data.html;
			}
		} catch (error) {
			if (error.name !== "AbortError") {
				console.error("Live filter error:", error);
			}
		}
	}

	// Select an item from dropdown
	function selectItem(value) {
		searchInput.value = value;
		hideAutocomplete();
		toggleClearButton();
		updateTableRows();
		// Optionally submit the form automatically
		// searchInput.closest('form').submit();
	}

	// Add active class to items
	function addActive(items) {
		if (!items || items.length === 0) return false;
		removeActive(items);
		if (currentFocus >= items.length) currentFocus = 0;
		if (currentFocus < 0) currentFocus = items.length - 1;
		items[currentFocus].classList.add("active");
	}

	// Remove active class from all items
	function removeActive(items) {
		items.forEach((item) => item.classList.remove("active"));
	}

	// Handle keyboard navigation
	searchInput.addEventListener("keydown", function (e) {
		const items = autocompleteList.querySelectorAll(".autocomplete-item");

		if (e.keyCode === 40) {
			// Arrow down
			e.preventDefault();
			currentFocus++;
			addActive(items);
		} else if (e.keyCode === 38) {
			// Arrow up
			e.preventDefault();
			currentFocus--;
			addActive(items);
		} else if (e.keyCode === 13) {
			// Enter
			if (currentFocus > -1 && items[currentFocus]) {
				e.preventDefault();
				selectItem(items[currentFocus].dataset.value);
			}
		} else if (e.keyCode === 27) {
			// Escape
			hideAutocomplete();
		}
	});

	// Handle input event with debounce
	searchInput.addEventListener(
		"input",
		debounce(function () {
			const query = this.value.trim();
			toggleClearButton();
			fetchSuggestions(query);
		}, 300),
	);

	searchInput.addEventListener("input", function () {
		updateTableRows();
	});

	if (categoryFilter) {
		categoryFilter.addEventListener("change", function () {
			updateTableRows();
		});
	}

	if (statusFilter) {
		statusFilter.addEventListener("change", function () {
			updateTableRows();
		});
	}

	// Handle focus event
	searchInput.addEventListener("focus", function () {
		const query = this.value.trim();
		if (query.length >= 1) {
			fetchSuggestions(query);
		}
	});

	// Close autocomplete when clicking outside
	document.addEventListener("click", function (e) {
		if (e.target !== searchInput && e.target !== autocompleteList) {
			hideAutocomplete();
		}
	});

	// Prevent form submission when autocomplete is open and user presses Enter
	searchInput.closest("form").addEventListener("submit", function (e) {
		if (autocompleteList.classList.contains("show")) {
			const items =
				autocompleteList.querySelectorAll(".autocomplete-item");
			if (currentFocus > -1 && items[currentFocus]) {
				e.preventDefault();
				selectItem(items[currentFocus].dataset.value);
			}
		}
	});

	if (clearButton) {
		clearButton.addEventListener("click", function () {
			searchInput.value = "";
			hideAutocomplete();
			toggleClearButton();
			updateTableRows();
			searchInput.focus();
		});
	}

	toggleClearButton();
});
