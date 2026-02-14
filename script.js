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

document.addEventListener("DOMContentLoaded", function () {
	requestAnimationFrame(function () {
		document.documentElement.classList.remove("dashboard-loading");
	});
});

document.addEventListener("DOMContentLoaded", function () {
	const viewTable = document.querySelector(
		".view-page .table-container table",
	);
	if (!viewTable) return;

	function getRowActionButtons(row) {
		if (!row) return [];
		return Array.from(
			row.querySelectorAll(".row-actions-inline .row-action-btn"),
		);
	}

	function closeAllQuickPreviews(exceptId) {
		const openRows = viewTable.querySelectorAll(".item-preview-row");
		openRows.forEach((row) => {
			if (exceptId && row.id === exceptId) return;
			row.hidden = true;
		});

		const toggleButtons = viewTable.querySelectorAll(".js-preview-toggle");
		toggleButtons.forEach((btn) => {
			const targetId = btn.dataset.previewTarget || "";
			if (exceptId && targetId === exceptId) return;
			btn.setAttribute("aria-expanded", "false");
			btn.dataset.expanded = "0";
			const label = btn.querySelector("span");
			if (label) label.textContent = "Lihat Detail";
			const icon = btn.querySelector("i");
			if (icon) {
				icon.classList.remove("bx-chevron-up");
				icon.classList.add("bx-chevron-down");
			}
		});
	}

	function openPreviewById(previewId, options = {}) {
		if (!previewId) return;
		const previewRow = document.getElementById(previewId);
		if (!previewRow) return;

		closeAllQuickPreviews(previewId);
		previewRow.hidden = false;

		const toggleButton = viewTable.querySelector(
			`.js-preview-toggle[data-preview-target="${previewId}"]`,
		);
		if (toggleButton) {
			toggleButton.setAttribute("aria-expanded", "true");
			toggleButton.dataset.expanded = "1";
			const label = toggleButton.querySelector("span");
			if (label) label.textContent = "Sembunyikan";
			const icon = toggleButton.querySelector("i");
			if (icon) {
				icon.classList.remove("bx-chevron-down");
				icon.classList.add("bx-chevron-up");
			}
		}

		if (options.focusHistory) {
			const historyBlock = previewRow.querySelector(
				".preview-history-block",
			);
			if (historyBlock) {
				historyBlock.scrollIntoView({
					behavior: "smooth",
					block: "nearest",
				});
			}
		}
	}

	function openOrTogglePreview(toggleButton) {
		if (!toggleButton) return;
		const previewId = toggleButton.dataset.previewTarget || "";
		const previewRow = previewId
			? document.getElementById(previewId)
			: null;
		if (!previewRow) return;

		if (previewRow.hidden) {
			openPreviewById(previewId, { focusHistory: false });
		} else {
			closeAllQuickPreviews();
		}
	}

	document.addEventListener("click", function (event) {
		const toggleButton = event.target.closest(".js-preview-toggle");
		if (toggleButton && viewTable.contains(toggleButton)) {
			openOrTogglePreview(toggleButton);
			return;
		}

		const historyButton = event.target.closest(".js-preview-history");
		if (historyButton && viewTable.contains(historyButton)) {
			const previewId = historyButton.dataset.previewTarget || "";
			openPreviewById(previewId, { focusHistory: true });
		}
	});

	document.addEventListener("keydown", function (event) {
		if (!document.body.classList.contains("view-page")) return;

		if (event.key === "Escape") {
			closeAllQuickPreviews();
			return;
		}

		const focusedEl = document.activeElement;
		if (!(focusedEl instanceof HTMLElement)) return;

		if (
			event.key === "Enter" &&
			focusedEl.classList.contains("js-preview-toggle")
		) {
			event.preventDefault();
			openOrTogglePreview(focusedEl);
			return;
		}

		if (!focusedEl.classList.contains("row-action-btn")) return;

		const currentRow = focusedEl.closest("tr.item-data-row");
		if (!currentRow) return;

		const currentButtons = getRowActionButtons(currentRow);
		const currentIndex = currentButtons.indexOf(focusedEl);
		if (currentIndex < 0) return;

		if (event.key === "ArrowRight") {
			event.preventDefault();
			const nextBtn =
				currentButtons[currentIndex + 1] || currentButtons[0];
			if (nextBtn) nextBtn.focus();
			return;
		}

		if (event.key === "ArrowLeft") {
			event.preventDefault();
			const prevBtn =
				currentButtons[currentIndex - 1] ||
				currentButtons[currentButtons.length - 1];
			if (prevBtn) prevBtn.focus();
			return;
		}

		if (event.key === "ArrowDown" || event.key === "ArrowUp") {
			event.preventDefault();
			const rows = Array.from(
				viewTable.querySelectorAll("tbody tr.item-data-row"),
			);
			const rowIndex = rows.indexOf(currentRow);
			if (rowIndex < 0) return;
			const targetIndex =
				event.key === "ArrowDown"
					? Math.min(rows.length - 1, rowIndex + 1)
					: Math.max(0, rowIndex - 1);
			const targetButtons = getRowActionButtons(rows[targetIndex]);
			const targetBtn =
				targetButtons[currentIndex] ||
				targetButtons[targetButtons.length - 1];
			if (targetBtn) targetBtn.focus();
		}
	});
});

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

document.addEventListener("DOMContentLoaded", function () {
	const tools = document.querySelector(".dashboard-table-tools");
	if (!tools) return;

	const searchInput = document.getElementById("dashboard-item-search");
	const filterButtons = document.querySelectorAll(
		".dashboard-filter-buttons button",
	);
	const detailToggle = document.getElementById("dashboard-detail-toggle");
	const detailPanels = document.querySelectorAll(".dashboard-detail-panel");
	const rows = document.querySelectorAll(
		".table-container tbody tr[data-status]",
	);

	if (!rows.length) return;

	let activeFilter = "all";
	let detailExpanded = false;

	function setDetailExpanded(nextState) {
		detailExpanded = !!nextState;

		detailPanels.forEach((panel) => {
			panel.hidden = !detailExpanded;
		});

		if (detailToggle) {
			detailToggle.dataset.expanded = detailExpanded ? "1" : "0";
			detailToggle.setAttribute(
				"aria-expanded",
				detailExpanded ? "true" : "false",
			);
			detailToggle.textContent = detailExpanded
				? "Sembunyikan Detail"
				: "Tampilkan Detail Lanjutan";
		}

		applyDashboardFilters();
	}

	function applyDashboardFilters() {
		const searchValue = (
			searchInput && searchInput.value ? searchInput.value : ""
		)
			.toString()
			.trim()
			.toLowerCase();

		rows.forEach((row) => {
			const status = (
				row.getAttribute("data-status") || ""
			).toLowerCase();
			const isCritical = row.getAttribute("data-critical") === "1";
			const isDefaultVisible =
				row.getAttribute("data-default-visible") === "1";
			const searchable = (
				row.getAttribute("data-search") || ""
			).toLowerCase();

			const matchesSearch =
				!searchValue || searchable.indexOf(searchValue) !== -1;

			if (!detailExpanded) {
				row.style.display = isDefaultVisible ? "" : "none";
				return;
			}

			let matchesFilter = true;
			if (activeFilter === "critical") {
				matchesFilter = isCritical;
			} else if (activeFilter === "in-stock") {
				matchesFilter = status === "in-stock";
			}

			row.style.display = matchesSearch && matchesFilter ? "" : "none";
		});
	}

	filterButtons.forEach((btn) => {
		btn.addEventListener("click", function () {
			activeFilter = this.getAttribute("data-filter") || "all";
			filterButtons.forEach((item) => item.classList.remove("active"));
			this.classList.add("active");
			applyDashboardFilters();
		});
	});

	if (searchInput) {
		searchInput.addEventListener("input", applyDashboardFilters);
	}

	if (detailToggle) {
		detailToggle.addEventListener("click", function () {
			setDetailExpanded(!detailExpanded);
		});
	}

	setDetailExpanded(false);
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
	const unitConversionGroup = root.querySelector("#unit-group-conversion");
	const levelConversionGroup = root.querySelector("#level-group-conversion");
	const levelModeGroup = root.querySelector("#level-group-mode");
	const levelModeSelect = root.querySelector("#calculation_mode");
	const customConversionGroup = root.querySelector(
		"#level-group-custom-conversion",
	);
	const customConversionInput = root.querySelector(
		"#custom_conversion_factor",
	);
	if (!levelGroup) return;
	function toggle() {
		const currentMode = levelModeSelect
			? String(levelModeSelect.value || "combined").toLowerCase()
			: "combined";
		if (hasLevelEl) {
			const levelEnabled = hasLevelEl.checked;
			const useMultiplied = levelEnabled && currentMode === "multiplied";
			levelGroup.style.display = levelEnabled ? "" : "none";
			if (unitConversionGroup) {
				unitConversionGroup.style.display = useMultiplied ? "none" : "";
			}
			if (levelConversionGroup) {
				levelConversionGroup.style.display =
					levelEnabled && !useMultiplied ? "" : "none";
			}
			if (levelModeGroup) {
				levelModeGroup.style.display = levelEnabled ? "" : "none";
			}
			if (customConversionGroup) {
				customConversionGroup.style.display = useMultiplied
					? ""
					: "none";
			}
			if (customConversionInput) {
				customConversionInput.required = useMultiplied;
			}
			if (!hasLevelEl.checked && levelModeSelect) {
				levelModeSelect.value = "combined";
			}
			return;
		}

		if (
			nameEl &&
			nameEl.value &&
			nameEl.value.trim().toUpperCase() === "DMDS"
		) {
			levelGroup.style.display = "";
			if (unitConversionGroup) {
				unitConversionGroup.style.display =
					currentMode === "multiplied" ? "none" : "";
			}
			if (levelConversionGroup) {
				levelConversionGroup.style.display =
					currentMode === "multiplied" ? "none" : "";
			}
			if (levelModeGroup) {
				levelModeGroup.style.display = "";
			}
			if (customConversionGroup) {
				customConversionGroup.style.display =
					currentMode === "multiplied" ? "" : "none";
			}
			if (customConversionInput) {
				customConversionInput.required = currentMode === "multiplied";
			}
		} else {
			levelGroup.style.display = "none";
			if (unitConversionGroup) {
				unitConversionGroup.style.display = "";
			}
			if (levelConversionGroup) {
				levelConversionGroup.style.display = "none";
			}
			if (levelModeGroup) {
				levelModeGroup.style.display = "none";
			}
			if (customConversionGroup) {
				customConversionGroup.style.display = "none";
			}
			if (customConversionInput) {
				customConversionInput.required = false;
			}
			if (levelModeSelect) {
				levelModeSelect.value = "combined";
			}
		}
	}
	if (hasLevelEl) {
		hasLevelEl.addEventListener("change", toggle);
	}
	if (nameEl) {
		nameEl.addEventListener("input", toggle);
	}
	if (levelModeSelect) {
		levelModeSelect.addEventListener("change", toggle);
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
	const chipsContainer = document.getElementById("active-filter-chips");
	const resetFiltersButton = document.getElementById("reset-filters-btn");
	const advancedToggleButton = document.getElementById(
		"toggle-advanced-filters",
	);
	const advancedPanel = document.getElementById("advanced-filters-panel");
	const sortFilter = document.getElementById("advanced-sort");
	const dirFilter = document.getElementById("advanced-dir");
	const totalSummaryValue = document.getElementById("summary-total-items");
	const criticalSummaryValue = document.getElementById(
		"summary-critical-items",
	);
	const filterCountLabel = document.getElementById(
		"summary-filter-count-label",
	);
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
	let applyTimer;
	let currentFocus = -1;
	let activeFilterController = null;
	const storageKey = "stockmanager.view.filters.v1";
	const defaultSort =
		(filterForm && filterForm.dataset.defaultSort) || "name";
	const defaultDir = (filterForm && filterForm.dataset.defaultDir) || "asc";

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
		searchInput.setAttribute("aria-expanded", "true");

		items.forEach((item, index) => {
			const div = document.createElement("div");
			div.className = "autocomplete-item";
			div.id = `autocomplete-item-${index}`;
			div.setAttribute("role", "option");
			div.setAttribute("aria-selected", "false");
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
		searchInput.setAttribute("aria-expanded", "true");
	}

	// Show no results message
	function showNoResults() {
		autocompleteList.innerHTML =
			'<div class="autocomplete-no-results">Tidak ada hasil ditemukan</div>';
		autocompleteList.classList.add("show");
		searchInput.setAttribute("aria-expanded", "true");
	}

	// Hide autocomplete dropdown
	function hideAutocomplete() {
		autocompleteList.innerHTML = "";
		autocompleteList.classList.remove("show");
		currentFocus = -1;
		searchInput.setAttribute("aria-expanded", "false");
		searchInput.removeAttribute("aria-activedescendant");
	}

	function toggleClearButton() {
		if (!clearButton) return;
		clearButton.classList.toggle("show", searchInput.value.length > 0);
	}

	function getCurrentState() {
		return {
			search: searchInput.value.trim(),
			category: categoryFilter ? categoryFilter.value : "",
			status: statusFilter ? statusFilter.value : "",
			sort: sortFilter ? sortFilter.value : defaultSort,
			dir: dirFilter ? dirFilter.value : defaultDir,
		};
	}

	function applyStateToControls(state) {
		if (!state) return;
		searchInput.value = state.search || "";
		if (categoryFilter) categoryFilter.value = state.category || "";
		if (statusFilter) statusFilter.value = state.status || "";
		if (sortFilter) sortFilter.value = state.sort || defaultSort;
		if (dirFilter) dirFilter.value = state.dir || defaultDir;
	}

	function saveState(state) {
		try {
			sessionStorage.setItem(storageKey, JSON.stringify(state));
		} catch (error) {
			console.warn("Cannot persist filter state", error);
		}
	}

	function getStoredState() {
		try {
			const raw = sessionStorage.getItem(storageKey);
			if (!raw) return null;
			const parsed = JSON.parse(raw);
			if (!parsed || typeof parsed !== "object") return null;
			return parsed;
		} catch (error) {
			return null;
		}
	}

	function stateFromUrl() {
		const urlParams = new URLSearchParams(window.location.search);
		return {
			search: urlParams.get("search") || "",
			category: urlParams.get("category") || "",
			status: urlParams.get("status") || "",
			sort: urlParams.get("sort") || defaultSort,
			dir: urlParams.get("dir") || defaultDir,
		};
	}

	function hasAnyQueryParams() {
		const query = new URLSearchParams(window.location.search);
		return (
			query.has("search") ||
			query.has("category") ||
			query.has("status") ||
			query.has("sort") ||
			query.has("dir")
		);
	}

	function syncFilterQueryToUrl(state) {
		const url = new URL(window.location.href);
		const nextSearch = state.search;
		const nextCategory = state.category;
		const nextStatus = state.status;
		const nextSort = state.sort || defaultSort;
		const nextDir = state.dir || defaultDir;

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

		if (nextSort && nextSort !== defaultSort) {
			url.searchParams.set("sort", nextSort);
		} else {
			url.searchParams.delete("sort");
		}

		if (nextDir && nextDir !== defaultDir) {
			url.searchParams.set("dir", nextDir);
		} else {
			url.searchParams.delete("dir");
		}

		history.replaceState(
			null,
			"",
			`${url.pathname}${url.search}${url.hash}`,
		);
	}

	function updateSummaryCounts() {
		if (!tableBody) return;
		const rows = tableBody.querySelectorAll("tr");
		let dataRows = 0;
		let criticalRows = 0;

		rows.forEach((row) => {
			const noDataCell = row.querySelector("td.no-data");
			if (noDataCell) return;
			dataRows += 1;
			const statusEl = row.querySelector("span.status");
			if (
				statusEl &&
				(statusEl.classList.contains("low-stock") ||
					statusEl.classList.contains("warning-stock") ||
					statusEl.classList.contains("out-stock"))
			) {
				criticalRows += 1;
			}
		});

		if (totalSummaryValue) {
			totalSummaryValue.textContent = dataRows.toLocaleString("id-ID");
		}
		if (criticalSummaryValue) {
			criticalSummaryValue.textContent =
				criticalRows.toLocaleString("id-ID");
		}
	}

	function renderFilterChips(state) {
		if (!chipsContainer) return;
		chipsContainer.innerHTML = "";

		const chipConfigs = [];
		if (state.search) {
			chipConfigs.push({
				key: "search",
				label: `Cari: ${state.search}`,
			});
		}
		if (state.category) {
			chipConfigs.push({
				key: "category",
				label: `Kategori: ${state.category}`,
			});
		}
		if (state.status) {
			let statusLabel = state.status;
			if (statusFilter) {
				const selected = statusFilter.querySelector(
					`option[value="${state.status}"]`,
				);
				if (selected) statusLabel = selected.textContent.trim();
			}
			chipConfigs.push({
				key: "status",
				label: `Status: ${statusLabel}`,
			});
		}
		if (state.sort && state.sort !== defaultSort) {
			let sortLabel = state.sort;
			if (sortFilter) {
				const selected = sortFilter.querySelector(
					`option[value="${state.sort}"]`,
				);
				if (selected) sortLabel = selected.textContent.trim();
			}
			chipConfigs.push({
				key: "sort",
				label: `Urut: ${sortLabel}`,
			});
		}
		if (state.dir && state.dir !== defaultDir) {
			const dirLabel = state.dir === "desc" ? "Turun" : "Naik";
			chipConfigs.push({
				key: "dir",
				label: `Arah: ${dirLabel}`,
			});
		}

		if (filterCountLabel) {
			filterCountLabel.textContent = `Filter Aktif (${chipConfigs.length})`;
		}

		if (!chipConfigs.length) {
			const empty = document.createElement("p");
			empty.className = "summary-muted";
			empty.id = "summary-filter-empty";
			empty.textContent = "Semua data tanpa filter";
			chipsContainer.appendChild(empty);
			return;
		}

		chipConfigs.forEach((chip) => {
			const button = document.createElement("button");
			button.type = "button";
			button.className = "summary-filter-chip summary-filter-chip-action";
			button.dataset.filterKey = chip.key;
			button.setAttribute("aria-label", `Hapus filter ${chip.label}`);
			button.innerHTML = `<span>${chip.label}</span><i class='bx bx-x' aria-hidden="true"></i>`;
			chipsContainer.appendChild(button);
		});
	}

	function clearFilterByKey(filterKey) {
		if (filterKey === "search") searchInput.value = "";
		if (filterKey === "category" && categoryFilter)
			categoryFilter.value = "";
		if (filterKey === "status" && statusFilter) statusFilter.value = "";
		if (filterKey === "sort" && sortFilter) sortFilter.value = defaultSort;
		if (filterKey === "dir" && dirFilter) dirFilter.value = defaultDir;
		toggleClearButton();
		runApply();
	}

	async function updateTableRows() {
		if (!tableBody || !filterForm) return;
		const currentState = getCurrentState();

		syncFilterQueryToUrl(currentState);
		saveState(currentState);
		renderFilterChips(currentState);

		if (activeFilterController) {
			activeFilterController.abort();
		}

		activeFilterController = new AbortController();
		const filterContext =
			(filterForm && filterForm.dataset.filterContext) || "view";
		const params = new URLSearchParams({
			search: currentState.search,
			category: currentState.category,
			status: currentState.status,
			sort: currentState.sort,
			dir: currentState.dir,
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
				updateSummaryCounts();
			}
		} catch (error) {
			if (error.name !== "AbortError") {
				console.error("Live filter error:", error);
			}
		}
	}

	function runApply(delay) {
		clearTimeout(applyTimer);
		if (delay && delay > 0) {
			applyTimer = setTimeout(updateTableRows, delay);
			return;
		}
		updateTableRows();
	}

	// Select an item from dropdown
	function selectItem(value) {
		searchInput.value = value;
		hideAutocomplete();
		toggleClearButton();
		runApply();
	}

	// Add active class to items
	function addActive(items) {
		if (!items || items.length === 0) return false;
		removeActive(items);
		if (currentFocus >= items.length) currentFocus = 0;
		if (currentFocus < 0) currentFocus = items.length - 1;
		items[currentFocus].classList.add("active");
		items[currentFocus].setAttribute("aria-selected", "true");
		if (items[currentFocus].id) {
			searchInput.setAttribute(
				"aria-activedescendant",
				items[currentFocus].id,
			);
		}
	}

	// Remove active class from all items
	function removeActive(items) {
		items.forEach((item) => {
			item.classList.remove("active");
			item.setAttribute("aria-selected", "false");
		});
		searchInput.removeAttribute("aria-activedescendant");
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
		runApply(400);
	});

	if (categoryFilter) {
		categoryFilter.addEventListener("change", function () {
			runApply();
		});
	}

	if (statusFilter) {
		statusFilter.addEventListener("change", function () {
			runApply();
		});
	}

	if (sortFilter) {
		sortFilter.addEventListener("change", function () {
			runApply();
		});
	}

	if (dirFilter) {
		dirFilter.addEventListener("change", function () {
			runApply();
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
		e.preventDefault();
		if (autocompleteList.classList.contains("show")) {
			const items =
				autocompleteList.querySelectorAll(".autocomplete-item");
			if (currentFocus > -1 && items[currentFocus]) {
				selectItem(items[currentFocus].dataset.value);
				return;
			}
		}
		runApply();
	});

	if (clearButton) {
		clearButton.addEventListener("click", function () {
			searchInput.value = "";
			hideAutocomplete();
			toggleClearButton();
			runApply();
			searchInput.focus();
		});
	}

	if (filterForm) {
		filterForm.addEventListener("keydown", function (event) {
			const isArrowKey =
				event.key === "ArrowRight" ||
				event.key === "ArrowLeft" ||
				event.key === "ArrowDown" ||
				event.key === "ArrowUp";
			if (!isArrowKey) return;

			const controls = Array.from(
				filterForm.querySelectorAll(
					"input, select, button, a, [tabindex]:not([tabindex='-1'])",
				),
			).filter((el) => !el.disabled && el.offsetParent !== null);

			const currentIndex = controls.indexOf(document.activeElement);
			if (currentIndex < 0) return;

			event.preventDefault();
			const delta =
				event.key === "ArrowRight" || event.key === "ArrowDown"
					? 1
					: -1;
			const nextIndex = Math.max(
				0,
				Math.min(controls.length - 1, currentIndex + delta),
			);
			controls[nextIndex].focus();
		});
	}

	if (chipsContainer) {
		chipsContainer.addEventListener("click", function (event) {
			const removeButton = event.target.closest("[data-filter-key]");
			if (!removeButton) return;
			clearFilterByKey(removeButton.dataset.filterKey || "");
		});
	}

	if (resetFiltersButton) {
		resetFiltersButton.addEventListener("click", function () {
			searchInput.value = "";
			if (categoryFilter) categoryFilter.value = "";
			if (statusFilter) statusFilter.value = "";
			if (sortFilter) sortFilter.value = defaultSort;
			if (dirFilter) dirFilter.value = defaultDir;
			toggleClearButton();
			hideAutocomplete();
			runApply();
		});
	}

	if (advancedToggleButton && advancedPanel) {
		advancedToggleButton.addEventListener("click", function () {
			const isExpanded =
				advancedToggleButton.getAttribute("aria-expanded") === "true";
			advancedToggleButton.setAttribute(
				"aria-expanded",
				isExpanded ? "false" : "true",
			);
			advancedPanel.hidden = isExpanded;
		});

		document.addEventListener("keydown", function (event) {
			if (event.key !== "Escape") return;
			if (advancedPanel.hidden) return;
			advancedPanel.hidden = true;
			advancedToggleButton.setAttribute("aria-expanded", "false");
			advancedToggleButton.focus();
		});
	}

	const currentUrlState = stateFromUrl();
	applyStateToControls(currentUrlState);

	if (!hasAnyQueryParams()) {
		const persistedState = getStoredState();
		if (persistedState) {
			applyStateToControls({
				search: persistedState.search || "",
				category: persistedState.category || "",
				status: persistedState.status || "",
				sort: persistedState.sort || defaultSort,
				dir: persistedState.dir || defaultDir,
			});
		}
	}

	if (sortFilter && sortFilter.value !== defaultSort) {
		if (advancedPanel) advancedPanel.hidden = false;
		if (advancedToggleButton) {
			advancedToggleButton.setAttribute("aria-expanded", "true");
		}
	}

	if (dirFilter && dirFilter.value !== defaultDir) {
		if (advancedPanel) advancedPanel.hidden = false;
		if (advancedToggleButton) {
			advancedToggleButton.setAttribute("aria-expanded", "true");
		}
	}

	toggleClearButton();
	renderFilterChips(getCurrentState());
	updateSummaryCounts();

	if (!hasAnyQueryParams() && getStoredState()) {
		runApply();
	} else {
		saveState(getCurrentState());
	}
});
