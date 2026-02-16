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

const AUTO_SAVE_DEBOUNCE_MS = 700;
const autoSaveTimers = new Map();

function clearAutoSaveTimer(itemId) {
	if (!autoSaveTimers.has(itemId)) return;
	clearTimeout(autoSaveTimers.get(itemId));
	autoSaveTimers.delete(itemId);
}

function clearAllAutoSaveTimers() {
	autoSaveTimers.forEach((timerId) => clearTimeout(timerId));
	autoSaveTimers.clear();
}

function parseJsonSafely(text, fallbackMessage) {
	try {
		return JSON.parse(text);
	} catch (e) {
		return {
			success: false,
			message: text || fallbackMessage,
		};
	}
}

function runRowAutoSave(itemId) {
	clearAutoSaveTimer(itemId);

	if (document.body.classList.contains("update-stock-ui-locked")) {
		return;
	}

	const row = getRowByItemId(itemId);
	if (!row) return;
	if (row.getAttribute("data-async-lock") === "1") return;

	const fieldEl = document.getElementById("field_" + itemId);
	const levelEl = document.getElementById("level_" + itemId);
	const isDirty = isControlDirty(fieldEl) || isControlDirty(levelEl);
	if (!isDirty) return;

	clearServerValidationErrorsForRow(itemId);

	if (!validateRowControls(itemId)) {
		markRowsAsFailed([itemId]);
		updateBatchSaveSummary();
		return;
	}

	row.classList.remove("is-failed", "is-saved");
	row.classList.add("is-autosaving");
	setRowStatusText(row, "Menyimpan...");
	lockRowForAsync(row, true);

	const formData = new FormData();
	formData.append(
		"field_stock[" + itemId + "]",
		fieldEl ? fieldEl.value : "0",
	);
	if (levelEl) {
		formData.append("level[" + itemId + "]", levelEl.value);
	}

	fetch(window.location.pathname, {
		method: "POST",
		body: formData,
		headers: {
			"X-Requested-With": "XMLHttpRequest",
		},
	})
		.then((response) =>
			response
				.text()
				.then((text) => parseJsonSafely(text, "Kesalahan server")),
		)
		.then((data) => {
			if (data && data.success) {
				if (Array.isArray(data.updated)) {
					const updatedItem = data.updated.find(
						(item) => String(item.id) === String(itemId),
					);
					if (updatedItem) {
						if (fieldEl) fieldEl.value = updatedItem.field_stock;
						if (
							levelEl &&
							Object.prototype.hasOwnProperty.call(
								updatedItem,
								"level",
							)
						) {
							levelEl.value =
								updatedItem.level === null
									? ""
									: updatedItem.level;
						}
						try {
							updateTotal(itemId);
						} catch (e) {}
					}
				}

				persistRowOriginalValues(itemId);
				refreshRowDirtyState(itemId);
				markRowsAsSaved([itemId]);
				return;
			}

			if (data && data.errors && typeof data.errors === "object") {
				applyServerValidationErrors(data.errors);
			}

			markRowsAsFailed([itemId]);
		})
		.catch(() => {
			markRowsAsFailed([itemId]);
		})
		.finally(() => {
			row.classList.remove("is-autosaving");
			lockRowForAsync(row, false);
			updateBatchSaveSummary();
		});
}

function scheduleRowAutoSave(itemId) {
	if (!itemId) return;
	clearAutoSaveTimer(itemId);

	const timerId = setTimeout(() => {
		runRowAutoSave(itemId);
	}, AUTO_SAVE_DEBOUNCE_MS);

	autoSaveTimers.set(itemId, timerId);
}

// AJAX submit for update-stock page: submit form via fetch and update table rows in-place
document.addEventListener("DOMContentLoaded", function () {
	const pageTitle = document.querySelector(".main-title h2");
	if (!pageTitle || !/Perbarui/i.test(pageTitle.textContent)) return;

	const form = document.getElementById("update-stock-form");
	if (!form) return;

	document.addEventListener(
		"click",
		function (event) {
			if (!document.body.classList.contains("update-stock-ui-locked")) {
				return;
			}

			const navControl = event.target.closest(
				"nav .nav-link, nav .menu-icon",
			);
			if (!navControl) return;

			event.preventDefault();
			event.stopPropagation();
		},
		true,
	);

	form.addEventListener("submit", function (e) {
		e.preventDefault();
		clearAllAutoSaveTimers();

		const dirtySummary = getDirtySummary();
		if (dirtySummary.rowCount < 1) {
			showModal({
				title: "Info",
				message: "Belum ada perubahan data untuk disimpan.",
				type: "info",
				okText: "OK",
			});
			return;
		}

		clearServerValidationErrors();

		const validationResult = validateUpdateStockForm();
		if (!validationResult.isValid) {
			if (validationResult.firstInvalidControl) {
				validationResult.firstInvalidControl.focus();
			}
			showModal({
				title: "Validasi Gagal",
				message:
					"Periksa input yang ditandai merah. Nilai harus bilangan bulat non-negatif.",
				type: "warning",
				okText: "OK",
			});
			return;
		}

		const stickyBtn = document.getElementById("batch-save-btn");
		const fabBtn = document.getElementById("batch-save-fab");
		const defaultStickyText = stickyBtn
			? stickyBtn.getAttribute("data-default-text") ||
				stickyBtn.textContent
			: "Simpan Semua Perubahan";
		const defaultFabHtml = fabBtn
			? fabBtn.getAttribute("data-default-html") || fabBtn.innerHTML
			: "";
		const dirtyRows = getDirtyRows();
		const pendingRowIds = dirtyRows
			.map((row) => row.getAttribute("data-item-id"))
			.filter(Boolean);

		clearFailedStateForRows(pendingRowIds);

		if (stickyBtn) {
			stickyBtn.disabled = true;
			stickyBtn.innerHTML =
				'<i class="bx bx-loader-alt bx-spin"></i> Memproses...';
		}
		if (fabBtn) {
			fabBtn.disabled = true;
			fabBtn.classList.add("is-loading");
			fabBtn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i>';
		}

		const formData = new FormData();
		dirtyRows.forEach((row) => {
			const itemId = row.getAttribute("data-item-id");
			if (!itemId) return;

			const fieldEl = document.getElementById("field_" + itemId);
			const levelEl = document.getElementById("level_" + itemId);

			formData.append(
				"field_stock[" + itemId + "]",
				fieldEl ? fieldEl.value : "0",
			);

			if (levelEl) {
				formData.append("level[" + itemId + "]", levelEl.value);
			}
		});

		setUpdateStockUiLock(true);

		fetch(window.location.pathname, {
			method: "POST",
			body: formData,
			headers: {
				"X-Requested-With": "XMLHttpRequest",
			},
		})
			.then((r) =>
				r
					.text()
					.then((txt) => parseJsonSafely(txt, "Kesalahan server")),
			)
			.then((data) => {
				if (stickyBtn) {
					stickyBtn.disabled = false;
					stickyBtn.innerHTML = defaultStickyText;
				}
				if (fabBtn) {
					fabBtn.disabled = false;
					fabBtn.classList.remove("is-loading");
					fabBtn.innerHTML = defaultFabHtml;
				}

				if (data && data.success) {
					if (Array.isArray(data.updated)) {
						data.updated.forEach((it) => {
							const itemId = String(it.id);
							const fieldEl = document.getElementById(
								"field_" + itemId,
							);
							const levelEl = document.getElementById(
								"level_" + itemId,
							);
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
						});
					}

					persistAllRowOriginalValues();
					markRowsAsSaved(pendingRowIds);
					updateBatchSaveSummary();

					showModal({
						title: "Berhasil",
						message:
							data.message ||
							"Perubahan stok berhasil disinkronkan",
						type: "success",
						okText: "OK",
					});
				} else if (
					data &&
					data.errors &&
					typeof data.errors === "object"
				) {
					const firstInvalidControl = applyServerValidationErrors(
						data.errors,
					);
					markRowsAsFailed(Object.keys(data.errors));
					if (firstInvalidControl) {
						firstInvalidControl.focus();
					}
					showModal({
						title: "Validasi Server",
						message:
							data.message ||
							"Terdapat data tidak valid pada beberapa baris.",
						type: "warning",
						okText: "OK",
					});
				} else {
					markRowsAsFailed(pendingRowIds);
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
				if (stickyBtn) {
					stickyBtn.disabled = false;
					stickyBtn.innerHTML = defaultStickyText;
				}
				if (fabBtn) {
					fabBtn.disabled = false;
					fabBtn.classList.remove("is-loading");
					fabBtn.innerHTML = defaultFabHtml;
				}
				markRowsAsFailed(pendingRowIds);
				showModal({
					title: "Gagal",
					message:
						err && err.message ? err.message : "Kesalahan server",
					type: "error",
					okText: "OK",
				});
			})
			.finally(() => {
				setUpdateStockUiLock(false);
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

function getControlCell(control) {
	if (!control || !control.closest) return null;
	return control.closest("td") || null;
}

function clearControlInlineError(control) {
	if (!control) return;
	control.classList.remove("input-inline-error");
	control.removeAttribute("aria-invalid");

	const cell = getControlCell(control);
	if (!cell) return;

	const id = control.id || "";
	const existing = cell.querySelector(
		'.cell-inline-error[data-for="' + id + '"]',
	);
	if (existing) {
		existing.remove();
	}
}

function setControlInlineError(control, message) {
	if (!control) return;

	clearControlInlineError(control);
	control.classList.add("input-inline-error");
	control.setAttribute("aria-invalid", "true");

	const cell = getControlCell(control);
	if (!cell) return;

	const errorEl = document.createElement("div");
	errorEl.className = "cell-inline-error";
	errorEl.setAttribute("role", "alert");
	errorEl.setAttribute("data-for", control.id || "");
	errorEl.textContent = message;
	cell.appendChild(errorEl);
}

function clearServerValidationErrorsForRow(itemId) {
	const row = getRowByItemId(itemId);
	if (!row) return;

	row.querySelectorAll(".cell-inline-error.server-validation").forEach(
		(el) => {
			el.remove();
		},
	);

	const fieldEl = document.getElementById("field_" + itemId);
	const levelEl = document.getElementById("level_" + itemId);

	[fieldEl, levelEl].forEach((control) => {
		if (!control) return;
		if (control.dataset.serverValidationError === "1") {
			clearControlInlineError(control);
			delete control.dataset.serverValidationError;
		}
	});
}

function clearServerValidationErrors() {
	document.querySelectorAll("tr[data-item-id]").forEach((row) => {
		const itemId = row.getAttribute("data-item-id");
		if (!itemId) return;
		clearServerValidationErrorsForRow(itemId);
	});
}

function setServerValidationError(control, message) {
	if (!control) return;
	setControlInlineError(control, message);
	control.dataset.serverValidationError = "1";

	const cell = getControlCell(control);
	if (!cell) return;
	const id = control.id || "";
	const errorEl = cell.querySelector(
		'.cell-inline-error[data-for="' + id + '"]',
	);
	if (errorEl) {
		errorEl.classList.add("server-validation");
	}
}

function setRowServerValidationError(itemId, message) {
	const row = getRowByItemId(itemId);
	if (!row) return;

	const targetCell = row.querySelector('td[data-label="Nama Barang"]');
	if (!targetCell) return;

	const errorEl = document.createElement("div");
	errorEl.className =
		"cell-inline-error server-validation server-validation-row";
	errorEl.setAttribute("role", "alert");
	errorEl.textContent = message;
	targetCell.appendChild(errorEl);
}

function applyServerValidationErrors(errorsByItem) {
	if (!errorsByItem || typeof errorsByItem !== "object") {
		return null;
	}

	clearServerValidationErrors();

	let firstInvalidControl = null;
	Object.keys(errorsByItem).forEach((itemId) => {
		const itemErrors = errorsByItem[itemId];
		if (!itemErrors || typeof itemErrors !== "object") return;

		if (itemErrors.field_stock) {
			const control = document.getElementById("field_" + itemId);
			if (control) {
				setServerValidationError(
					control,
					String(itemErrors.field_stock),
				);
				if (!firstInvalidControl) firstInvalidControl = control;
			}
		}

		if (itemErrors.level) {
			const control = document.getElementById("level_" + itemId);
			if (control) {
				setServerValidationError(control, String(itemErrors.level));
				if (!firstInvalidControl) firstInvalidControl = control;
			}
		}

		if (itemErrors._row) {
			setRowServerValidationError(itemId, String(itemErrors._row));
		}
	});

	return firstInvalidControl;
}

function isNonNegativeIntegerValue(rawValue, allowEmpty = false) {
	const value = String(rawValue ?? "").trim();
	if (!value) {
		return allowEmpty;
	}
	return /^\d+$/.test(value);
}

function validateStockControl(control) {
	if (!control) return true;
	const raw = String(control.value ?? "").trim();
	if (!isNonNegativeIntegerValue(raw, false)) {
		setControlInlineError(control, "Stok wajib bilangan bulat ≥ 0.");
		return false;
	}
	clearControlInlineError(control);
	return true;
}

function validateLevelControl(control, itemId) {
	if (!control) return true;

	const row = getRowByItemId(itemId);
	const hasLevel = row && row.dataset && row.dataset.hasLevel === "1";
	const raw = String(control.value ?? "").trim();

	if (!hasLevel && raw !== "") {
		setControlInlineError(
			control,
			"Level hanya boleh diisi untuk item yang mendukung level.",
		);
		return false;
	}

	if (hasLevel && !isNonNegativeIntegerValue(raw, true)) {
		setControlInlineError(control, "Level wajib bilangan bulat ≥ 0.");
		return false;
	}

	clearControlInlineError(control);
	return true;
}

function validateRowControls(itemId) {
	const fieldEl = document.getElementById("field_" + itemId);
	const levelEl = document.getElementById("level_" + itemId);

	const fieldOk = validateStockControl(fieldEl);
	const levelOk = validateLevelControl(levelEl, itemId);

	return fieldOk && levelOk;
}

function validateUpdateStockForm() {
	const rows = document.querySelectorAll("tr[data-item-id]");
	let firstInvalidControl = null;
	let isValid = true;

	rows.forEach((row) => {
		const itemId = row.getAttribute("data-item-id");
		if (!itemId) return;

		const rowOk = validateRowControls(itemId);
		if (!rowOk) {
			isValid = false;
			if (!firstInvalidControl) {
				firstInvalidControl =
					row.querySelector(".input-inline-error") ||
					row.querySelector("input[type='number']");
			}
		}
	});

	return { isValid, firstInvalidControl };
}

function getDirtyRows() {
	return Array.from(document.querySelectorAll("tr[data-item-id].is-dirty"));
}

function getRowStatusTextElement(row) {
	if (!row) return null;
	return row.querySelector(".row-dirty-text");
}

function setRowStatusText(row, text) {
	const textEl = getRowStatusTextElement(row);
	if (!textEl) return;
	textEl.textContent = text;
}

function markRowsAsSaved(itemIds) {
	(itemIds || []).forEach((itemId) => {
		const row = getRowByItemId(itemId);
		if (!row) return;

		row.classList.remove("is-dirty", "is-failed");
		row.classList.add("is-saved");
		setRowStatusText(row, "Berhasil disimpan");
	});
}

function markRowsAsFailed(itemIds) {
	(itemIds || []).forEach((itemId) => {
		const row = getRowByItemId(itemId);
		if (!row) return;

		row.classList.remove("is-saved");
		row.classList.add("is-failed");
		setRowStatusText(row, "Gagal diperbarui");
	});
}

function clearFailedStateForRows(itemIds) {
	(itemIds || []).forEach((itemId) => {
		const row = getRowByItemId(itemId);
		if (!row) return;

		row.classList.remove("is-failed");
		if (row.classList.contains("is-dirty")) {
			setRowStatusText(row, "Belum disimpan");
		}
	});
}

function setControlLockedState(control, locked) {
	if (!control) return;

	if (locked) {
		if (
			!Object.prototype.hasOwnProperty.call(
				control.dataset,
				"lockPrevDisabled",
			)
		) {
			control.dataset.lockPrevDisabled = control.disabled ? "1" : "0";
		}
		control.disabled = true;
		return;
	}

	const prev = control.dataset.lockPrevDisabled;
	if (prev === "1") {
		control.disabled = true;
	} else {
		control.disabled = false;
	}
	delete control.dataset.lockPrevDisabled;
}

function lockRowForAsync(row, locked) {
	if (!row) return;

	if (locked) {
		row.classList.add("is-locked");
		row.setAttribute("aria-busy", "true");
		row.setAttribute("data-async-lock", "1");
	} else {
		row.classList.remove("is-locked");
		row.removeAttribute("aria-busy");
		row.removeAttribute("data-async-lock");
	}

	const controls = row.querySelectorAll("input, button, select, textarea");
	controls.forEach((control) => {
		setControlLockedState(control, locked);
	});
}

function setUpdateStockUiLock(locked) {
	const body = document.body;
	if (!body) return;

	if (locked) {
		getDirtyRows().forEach((row) => lockRowForAsync(row, true));
		body.classList.add("update-stock-ui-locked");
		setControlLockedState(document.getElementById("category-filter"), true);
		return;
	}

	document
		.querySelectorAll('tr[data-item-id][data-async-lock="1"]')
		.forEach((row) => lockRowForAsync(row, false));
	body.classList.remove("update-stock-ui-locked");
	setControlLockedState(document.getElementById("category-filter"), false);
}

function refreshRowDirtyState(itemId) {
	const row = getRowByItemId(itemId);
	if (!row) return;

	const fieldEl = document.getElementById("field_" + itemId);
	const levelEl = document.getElementById("level_" + itemId);
	const dirty = isControlDirty(fieldEl) || isControlDirty(levelEl);

	if (dirty) {
		row.classList.add("is-dirty");
		row.classList.remove("is-saved", "is-failed");
		setRowStatusText(row, "Belum disimpan");
	} else {
		row.classList.remove("is-dirty", "is-failed");
		if (!row.classList.contains("is-saved")) {
			setRowStatusText(row, "Tidak ada perubahan");
		}
	}

	updateBatchSaveSummary();
}

function persistRowOriginalValues(itemId) {
	const fieldEl = document.getElementById("field_" + itemId);
	const levelEl = document.getElementById("level_" + itemId);

	if (fieldEl) fieldEl.dataset.originalValue = fieldEl.value;
	if (levelEl) levelEl.dataset.originalValue = levelEl.value;
	updateBatchSaveSummary();
}

function persistAllRowOriginalValues() {
	const rows = document.querySelectorAll("tr[data-item-id]");
	rows.forEach((row) => {
		const itemId = row.getAttribute("data-item-id");
		if (!itemId) return;
		persistRowOriginalValues(itemId);
		refreshRowDirtyState(itemId);
	});
}

function getDirtySummary() {
	const rows = document.querySelectorAll("tr[data-item-id]");
	let rowCount = 0;
	let fieldCount = 0;

	rows.forEach((row) => {
		const itemId = row.getAttribute("data-item-id");
		if (!itemId) return;

		const fieldEl = document.getElementById("field_" + itemId);
		const levelEl = document.getElementById("level_" + itemId);
		const fieldDirty = isControlDirty(fieldEl);
		const levelDirty = isControlDirty(levelEl);

		if (fieldDirty || levelDirty) {
			rowCount += 1;
			if (fieldDirty) fieldCount += 1;
			if (levelDirty) fieldCount += 1;
		}
	});

	return { rowCount, fieldCount };
}

function updateBatchSaveSummary() {
	const summaryEl = document.getElementById("batch-save-summary");
	const saveBtn = document.getElementById("batch-save-btn");
	const fabBtn = document.getElementById("batch-save-fab");
	const fabCountEl = document.getElementById("batch-save-fab-count");
	if (!summaryEl || !saveBtn) return;

	const { rowCount, fieldCount } = getDirtySummary();
	const rowLabel = rowCount === 1 ? "item berubah" : "item berubah";
	const fieldLabel = fieldCount === 1 ? "field diubah" : "field diubah";
	summaryEl.textContent =
		rowCount + " " + rowLabel + " • " + fieldCount + " " + fieldLabel;

	const buttonText =
		rowCount > 0
			? "Simpan Semua Perubahan (" + rowCount + ")"
			: "Simpan Semua Perubahan (0)";
	saveBtn.textContent = buttonText;
	saveBtn.setAttribute("data-default-text", buttonText);
	saveBtn.disabled = rowCount < 1;

	if (fabBtn) {
		fabBtn.disabled = rowCount < 1;
		fabBtn.setAttribute(
			"aria-label",
			"Simpan Semua Perubahan (" + rowCount + " item)",
		);
	}

	if (fabCountEl) {
		fabCountEl.textContent = rowCount > 99 ? "99+" : String(rowCount);
	}

	if (fabBtn) {
		const fabCountText = rowCount > 99 ? "99+" : String(rowCount);
		const fabDefaultHtml =
			'<i class="bx bx-save"></i><span class="batch-save-fab-count" id="batch-save-fab-count">' +
			fabCountText +
			"</span>";
		fabBtn.setAttribute("data-default-html", fabDefaultHtml);
		if (!fabBtn.classList.contains("is-loading")) {
			fabBtn.innerHTML = fabDefaultHtml;
		}
	}
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
				clearServerValidationErrorsForRow(itemId);
				delete fieldEl.dataset.serverValidationError;
				validateStockControl(fieldEl);
				refreshRowDirtyState(itemId);
			});
			fieldEl.addEventListener("change", function () {
				clearServerValidationErrorsForRow(itemId);
				delete fieldEl.dataset.serverValidationError;
				validateStockControl(fieldEl);
				refreshRowDirtyState(itemId);
			});
			fieldEl.addEventListener("blur", function () {
				scheduleRowAutoSave(itemId);
			});
		}

		if (levelEl) {
			levelEl.addEventListener("input", function () {
				clearServerValidationErrorsForRow(itemId);
				delete levelEl.dataset.serverValidationError;
				validateLevelControl(levelEl, itemId);
				refreshRowDirtyState(itemId);
			});
			levelEl.addEventListener("change", function () {
				clearServerValidationErrorsForRow(itemId);
				delete levelEl.dataset.serverValidationError;
				validateLevelControl(levelEl, itemId);
				refreshRowDirtyState(itemId);
			});
			levelEl.addEventListener("blur", function () {
				scheduleRowAutoSave(itemId);
			});
		}

		validateRowControls(itemId);
		refreshRowDirtyState(itemId);
	});

	updateBatchSaveSummary();
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
		if (this.id === "update-stock-form") return;

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
	const isServerGrid = filterForm && filterForm.dataset.serverGrid === "1";
	const pageInput = filterForm
		? filterForm.querySelector('input[name="page"]')
		: null;
	const expandedStateInput = filterForm
		? filterForm.querySelector('input[name="expanded"]')
		: null;
	const resetFilterButton = filterForm
		? filterForm.querySelector("#reset-filter-btn")
		: null;
	const categoryFilter = filterForm
		? filterForm.querySelector('select[name="category"]')
		: null;
	const statusFilter = filterForm
		? filterForm.querySelector('select[name="status"]')
		: null;
	const perPageFilter = filterForm
		? filterForm.querySelector('select[name="per_page"]')
		: null;
	const activeFilterChips = document.getElementById("active-filter-chips");
	const resultCount = document.getElementById("filter-result-count");
	const tableBody = document.querySelector(".table-container table tbody");

	if (!searchInput || !autocompleteList) return;

	let currentFocus = -1;
	let activeFilterController = null;

	// Debounce function to limit API calls
	function debounce(func, delay) {
		let timerId;
		return function (...args) {
			clearTimeout(timerId);
			timerId = setTimeout(() => func.apply(this, args), delay);
		};
	}

	const debouncedUpdateTableRows = debounce(function () {
		updateTableRows();
	}, 300);

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
		if (!filterForm) return;

		const url = new URL(window.location.href);
		const formData = new FormData(filterForm);
		const keys = [
			"search",
			"category",
			"status",
			"sort",
			"dir",
			"per_page",
			"page",
			"expanded",
		];

		keys.forEach((key) => {
			const rawValue = formData.get(key);
			const value = (rawValue === null ? "" : String(rawValue)).trim();

			if (!value || (key === "page" && value === "1")) {
				url.searchParams.delete(key);
				return;
			}

			url.searchParams.set(key, value);
		});

		history.replaceState(
			null,
			"",
			`${url.pathname}${url.search}${url.hash}`,
		);
	}

	function updateResultCount() {
		if (!resultCount || !tableBody) return;

		const noDataCell = tableBody.querySelector("td.no-data");
		const rowCount = noDataCell
			? 0
			: tableBody.querySelectorAll("tr").length;
		resultCount.textContent = `${rowCount} item ditemukan`;
	}

	function resetFiltersAndRefresh() {
		searchInput.value = "";
		if (categoryFilter) {
			categoryFilter.value = "";
		}
		if (statusFilter) {
			statusFilter.value = "";
		}
		if (pageInput) {
			pageInput.value = "1";
		}
		if (expandedStateInput) {
			expandedStateInput.value = "";
		}

		hideAutocomplete();
		toggleClearButton();
		renderActiveFilterChips();
		syncFilterQueryToUrl();
		if (isServerGrid) {
			filterForm.submit();
			return;
		}

		updateTableRows();
		searchInput.focus();
	}

	function renderActiveFilterChips() {
		if (!activeFilterChips) return;

		const chips = [];
		const searchValue = searchInput.value.trim();
		const categoryValue = categoryFilter ? categoryFilter.value : "";
		const statusValue = statusFilter ? statusFilter.value : "";

		if (searchValue) {
			chips.push({
				key: "search",
				label: `Pencarian: ${searchValue}`,
			});
		}

		if (categoryValue && categoryFilter) {
			const selectedCategory =
				categoryFilter.options[categoryFilter.selectedIndex];
			chips.push({
				key: "category",
				label: `Kategori: ${selectedCategory ? selectedCategory.text : categoryValue}`,
			});
		}

		if (statusValue && statusFilter) {
			const selectedStatus =
				statusFilter.options[statusFilter.selectedIndex];
			chips.push({
				key: "status",
				label: `Status: ${selectedStatus ? selectedStatus.text : statusValue}`,
			});
		}

		activeFilterChips.innerHTML = "";

		if (!chips.length) {
			activeFilterChips.classList.remove("show");
			return;
		}

		chips.forEach((chip) => {
			const chipEl = document.createElement("button");
			chipEl.type = "button";
			chipEl.className = "filter-chip";
			chipEl.dataset.filterKey = chip.key;
			chipEl.setAttribute("aria-label", `Hapus filter ${chip.label}`);
			chipEl.innerHTML = `${chip.label}<span class="chip-remove" aria-hidden="true">&times;</span>`;
			chipEl.addEventListener("click", function () {
				if (chip.key === "search") {
					searchInput.value = "";
					hideAutocomplete();
					toggleClearButton();
				} else if (chip.key === "category" && categoryFilter) {
					categoryFilter.value = "";
				} else if (chip.key === "status" && statusFilter) {
					statusFilter.value = "";
				}

				if (pageInput) {
					pageInput.value = "1";
				}
				if (expandedStateInput) {
					expandedStateInput.value = "";
				}

				renderActiveFilterChips();
				syncFilterQueryToUrl();
				if (isServerGrid) {
					filterForm.submit();
					return;
				}

				updateTableRows();
			});
			activeFilterChips.appendChild(chipEl);
		});

		activeFilterChips.classList.add("show");
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
				updateResultCount();
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
		if (pageInput) {
			pageInput.value = "1";
		}
		if (expandedStateInput) {
			expandedStateInput.value = "";
		}
		renderActiveFilterChips();
		syncFilterQueryToUrl();
		if (isServerGrid) {
			filterForm.submit();
			return;
		}

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
		if (pageInput) {
			pageInput.value = "1";
		}
		if (expandedStateInput) {
			expandedStateInput.value = "";
		}
		renderActiveFilterChips();
		syncFilterQueryToUrl();
		if (isServerGrid) {
			return;
		}

		debouncedUpdateTableRows();
	});

	if (categoryFilter) {
		categoryFilter.addEventListener("change", function () {
			if (pageInput) {
				pageInput.value = "1";
			}
			if (expandedStateInput) {
				expandedStateInput.value = "";
			}
			renderActiveFilterChips();
			syncFilterQueryToUrl();
			if (isServerGrid) {
				return;
			}

			updateTableRows();
		});
	}

	if (statusFilter) {
		statusFilter.addEventListener("change", function () {
			if (pageInput) {
				pageInput.value = "1";
			}
			if (expandedStateInput) {
				expandedStateInput.value = "";
			}
			renderActiveFilterChips();
			syncFilterQueryToUrl();
			if (isServerGrid) {
				return;
			}
			if (perPageFilter) {
				perPageFilter.addEventListener("change", function () {
					if (pageInput) {
						pageInput.value = "1";
					}
					if (expandedStateInput) {
						expandedStateInput.value = "";
					}
					syncFilterQueryToUrl();
					if (isServerGrid) {
						return;
					}

					updateTableRows();
				});
			}

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

	// Keep filtering in live-update mode (avoid full page reload on submit/Enter)
	searchInput.closest("form").addEventListener("submit", function (e) {
		if (pageInput) {
			pageInput.value = "1";
		}

		syncFilterQueryToUrl();

		if (isServerGrid) {
			return;
		}

		e.preventDefault();

		if (autocompleteList.classList.contains("show")) {
			const items =
				autocompleteList.querySelectorAll(".autocomplete-item");
			if (currentFocus > -1 && items[currentFocus]) {
				selectItem(items[currentFocus].dataset.value);
			}
		}
	});

	if (clearButton) {
		clearButton.addEventListener("click", function () {
			searchInput.value = "";
			if (pageInput) {
				pageInput.value = "1";
			}
			if (expandedStateInput) {
				expandedStateInput.value = "";
			}
			hideAutocomplete();
			toggleClearButton();
			renderActiveFilterChips();
			syncFilterQueryToUrl();
			if (isServerGrid) {
				filterForm.submit();
				return;
			}

			updateTableRows();
			searchInput.focus();
		});
	}

	if (tableBody) {
		tableBody.addEventListener("click", function (event) {
			const resetBtn = event.target.closest(".empty-reset-btn");
			if (!resetBtn) return;
			resetFiltersAndRefresh();
		});
	}

	if (resetFilterButton) {
		resetFilterButton.addEventListener("click", function () {
			resetFiltersAndRefresh();
		});
	}

	toggleClearButton();
	renderActiveFilterChips();
	if (!isServerGrid) {
		updateResultCount();
	}
});
