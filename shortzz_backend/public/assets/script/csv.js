$(document).ready(function () {
    let csvData = [];
    let headers = [];
    let selectedCells = new Set();
    let selectedRows = new Set();
    let selectedColumns = new Set();
    let activeCell = null;
    let editingCell = null;
    let isSelecting = false;
    let selectionStart = null;
    let selectionEnd = null;
    let copiedData = null;
    let historyStack = [];

    $("#downloadCSV, #addRow, #deleteBtn, #copyBtn, #saveFile").on(
        "click",
        function (e) {
            e.preventDefault();
            const actionMap = {
                downloadCSV: downloadCSV,
                addRow: addRow,
                deleteBtn: deleteSelected,
                copyBtn: copySelection,
                saveFile: () => {
                    const languageId = $("#language_id").val();
                    const code = $("#code").val();
                    const title = $("#title").val();
                    const localizedTitle = $("#localized_title").val();
                    showFormSpinner("#saveFile");
                    saveCSV({
                        languageId,
                        code,
                        title,
                        localizedTitle,
                    });
                },
            };

            const id = this.id;
            if (actionMap[id]) {
                actionMap[id]();
            }
        }
    );

    async function loadCsvFromUrl(url) {
        try {
            const response = await fetch(url);
            const csvText = await response.text();

            // Split CSV into rows and cells (simple parser)
            const rows = csvText
                .trim()
                .split("\n")
                .map((row) => row.split(","));

            headers = rows[0];
            csvData = [...rows.slice(1)];
            renderTable();
        } catch (error) {
            console.error("Failed to load CSV:", error);
            alert("Error loading CSV from URL.");
        }
    }
    console.log(itemBaseUrl);
    loadCsvFromUrl(itemBaseUrl + language.csv_file);

    // File upload handler
    document.getElementById("csvFile").addEventListener("change", function (e) {
        const file = e.target.files[0];
        if (file) {
            Papa.parse(file, {
                header: false,
                complete: function (results) {
                    if (results.data.length > 0) {
                        headers = results.data[0];
                        csvData = results.data
                            .slice(1)
                            .filter((row) =>
                                row.some((cell) => cell && cell.trim() !== "")
                            );
                        renderTable();
                    }
                },
            });
        }
    });

    function renderTable() {
        const table = document.getElementById("csvTable");
        table.innerHTML = "";

        // Create header
        // const thead = document.createElement("thead");
        const thead = document.createElement("div");
        thead.className = "table-header";
        // const headerRow = document.createElement("tr");
        const headerRow = document.createElement("div");
        headerRow.className = "table-row";

        // Corner cell with checkbox for "Select All"
        // const cornerCell = document.createElement("th");
        // cornerCell.className = "corner-select";

        // Create checkbox element
        // const selectAllCheckbox = document.createElement("input");
        // selectAllCheckbox.type = "checkbox";
        // selectAllCheckbox.className = "form-check-input";
        // selectAllCheckbox.id = "selectAllCheckbox";

        // // Append checkbox to header cell
        // cornerCell.appendChild(selectAllCheckbox);

        // // Optional: Attach event listener to checkbox
        // selectAllCheckbox.addEventListener("change", function () {
        //     selectAll(this.checked); // Call your function with checkbox status (true/false)
        // });

        // headerRow.appendChild(cornerCell);
        // thead.appendChild(headerRow);

        // Corner cell for select all
        // const cornerCell = document.createElement("th");
        const cornerCell = document.createElement("div");
        cornerCell.className = "corner-select";
        // cornerCell.onclick = selectAll;
        headerRow.appendChild(cornerCell);

        // $(".corner-select").click(function (e) {
        //     e.preventDefault();

        // });

        // Column headers
        headers.forEach((header, colIndex) => {
            // const th = document.createElement("th");
            const th = document.createElement("div");
            th.className = "column-header";
            th.innerHTML = `<div class="cell cursor-auto border">${header}</div>`;
            // th.onclick = (e) => selectColumn(colIndex, e);
            // th.ondblclick = () => editHeader(colIndex);
            headerRow.appendChild(th);
        });

        thead.appendChild(headerRow);
        table.appendChild(thead);

        // Create body
        // const tbody = document.createElement("tbody");
        const tbody = document.createElement("section");
        tbody.className = "table-body";
        csvData.forEach((row, rowIndex) => {
            // const tr = document.createElement("tr");
            const tr = document.createElement("div");
            tr.className = "rows";

            // Row number
            // const rowNumCell = document.createElement("td");
            const rowNumCell = document.createElement("div");
            rowNumCell.className = "row-number";
            rowNumCell.innerHTML = `<div class="cell border">${
                rowIndex + 1
            }</div>`;
            rowNumCell.onclick = (e) => selectRow(rowIndex, e);
            tr.appendChild(rowNumCell);

            // Data cells
            headers.forEach((header, colIndex) => {
                // const td = document.createElement("td");
                const td = document.createElement("div");
                const cellDiv = document.createElement("div");
                cellDiv.className = "cell border";
                // cellDiv.textContent = row[colIndex] || "";
                 cellDiv.innerHTML = row[colIndex]?.replace(/ /g, '&nbsp;') || "";
                cellDiv.dataset.row = rowIndex;
                cellDiv.dataset.col = colIndex;

                cellDiv.onmousedown = (e) =>
                    startSelection(rowIndex, colIndex, e);
                cellDiv.onmouseover = () => updateSelection(rowIndex, colIndex);
                cellDiv.ondblclick = () => editCell(rowIndex, colIndex);

                td.appendChild(cellDiv);
                tr.appendChild(td);
            });

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        clearSelection();
        updateStats();
    }

    function startSelection(row, col, event) {
        if (editingCell) return;

        event.preventDefault();
        isSelecting = true;
        selectionStart = { row, col };
        selectionEnd = { row, col };

        if (!event.ctrlKey && !event.metaKey && !event.shiftKey) {
            clearSelection();
        }

        if (event.shiftKey && activeCell) {
            // Extend selection from active cell
            selectRange(activeCell.row, activeCell.col, row, col);
        } else {
            setActiveCell(row, col);
            if (!event.ctrlKey && !event.metaKey) {
                selectCell(row, col);
            } else {
                toggleCell(row, col);
            }
        }

        document.addEventListener("mouseup", stopSelection);
    }

    function updateSelection(row, col) {
        if (!isSelecting) return;

        selectionEnd = { row, col };
        selectRange(selectionStart.row, selectionStart.col, row, col);
    }

    function stopSelection() {
        isSelecting = false;
        document.removeEventListener("mouseup", stopSelection);
    }

    function selectCell(row, col) {
        selectedCells.add(`${row},${col}`);
        updateCellVisuals();
    }

    function toggleCell(row, col) {
        const key = `${row},${col}`;
        if (selectedCells.has(key)) {
            selectedCells.delete(key);
        } else {
            selectedCells.add(key);
        }
        updateCellVisuals();
    }

    function selectRange(startRow, startCol, endRow, endCol) {
        const minRow = Math.min(startRow, endRow);
        const maxRow = Math.max(startRow, endRow);
        const minCol = Math.min(startCol, endCol);
        const maxCol = Math.max(startCol, endCol);

        selectedCells.clear();
        for (let row = minRow; row <= maxRow; row++) {
            for (let col = minCol; col <= maxCol; col++) {
                selectedCells.add(`${row},${col}`);
            }
        }
        updateCellVisuals();
    }

    function selectRow(rowIndex, event) {
        if (event.ctrlKey || event.metaKey) {
            if (selectedRows.has(rowIndex)) {
                selectedRows.delete(rowIndex);
            } else {
                selectedRows.add(rowIndex);
            }
        } else if (event.shiftKey && selectedRows.size > 0) {
            const lastRow = Math.max(...selectedRows);
            const minRow = Math.min(rowIndex, lastRow);
            const maxRow = Math.max(rowIndex, lastRow);
            for (let i = minRow; i <= maxRow; i++) {
                selectedRows.add(i);
            }
        } else {
            selectedRows.clear();
            selectedColumns.clear();
            selectedCells.clear();
            selectedRows.add(rowIndex);
        }
        updateVisuals();
    }

    function selectColumn(colIndex, event) {
        if (event.ctrlKey || event.metaKey) {
            if (selectedColumns.has(colIndex)) {
                selectedColumns.delete(colIndex);
            } else {
                selectedColumns.add(colIndex);
            }
        } else if (event.shiftKey && selectedColumns.size > 0) {
            const lastCol = Math.max(...selectedColumns);
            const minCol = Math.min(colIndex, lastCol);
            const maxCol = Math.max(colIndex, lastCol);
            for (let i = minCol; i <= maxCol; i++) {
                selectedColumns.add(i);
            }
        } else {
            selectedRows.clear();
            selectedColumns.clear();
            selectedCells.clear();
            selectedColumns.add(colIndex);
        }
        updateVisuals();
    }

    function selectAll() {
        selectedRows.clear();
        selectedColumns.clear();
        selectedCells.clear();
        for (let row = 0; row < csvData.length; row++) {
            for (let col = 0; col < headers.length; col++) {
                selectedCells.add(`${row},${col}`);
            }
        }
        updateVisuals();
    }

    function clearSelection() {
        selectedCells.clear();
        selectedRows.clear();
        selectedColumns.clear();
        activeCell = null;
        updateVisuals();
    }

    function setActiveCell(row, col) {
        activeCell = { row, col };
        updateCellVisuals();
    }

    function updateVisuals() {
        updateCellVisuals();
        updateRowVisuals();
        updateColumnVisuals();
        updateButtons();
        updateStats();
    }

    function updateCellVisuals() {
        // Clear all cell selections
        document.querySelectorAll(".cell").forEach((cell) => {
            cell.classList.remove("selected", "range-selected", "active");
        });

        // Apply selections
        selectedCells.forEach((key) => {
            const [row, col] = key.split(",").map(Number);
            const cell = document.querySelector(
                `[data-row="${row}"][data-col="${col}"]`
            );
            if (cell) {
                cell.classList.add("selected");
            }
        });

        // Apply row selections
        selectedRows.forEach((rowIndex) => {
            for (let col = 0; col < headers.length; col++) {
                const cell = document.querySelector(
                    `[data-row="${rowIndex}"][data-col="${col}"]`
                );
                if (cell) {
                    cell.classList.add("selected");
                }
            }
        });

        // Apply column selections
        selectedColumns.forEach((colIndex) => {
            for (let row = 0; row < csvData.length; row++) {
                const cell = document.querySelector(
                    `[data-row="${row}"][data-col="${colIndex}"]`
                );
                if (cell) {
                    cell.classList.add("selected");
                }
            }
        });

        // Apply active cell
        if (activeCell) {
            const cell = document.querySelector(
                `[data-row="${activeCell.row}"][data-col="${activeCell.col}"]`
            );
            if (cell) {
                cell.classList.add("active");
            }
        }
    }

    function updateRowVisuals() {
        document.querySelectorAll(".row-number").forEach((cell, index) => {
            cell.classList.toggle("selected", selectedRows.has(index));
        });
    }

    function updateColumnVisuals() {
        document.querySelectorAll(".column-header").forEach((cell, index) => {
            cell.classList.toggle("selected", selectedColumns.has(index));
        });
    }

    function updateButtons() {
        const hasSelection =
            selectedCells.size > 0 ||
            selectedRows.size > 0 ||
            selectedColumns.size > 0;
        document.getElementById("deleteBtn").disabled = !hasSelection;
    }

    function editCell(row, col) {
        if (editingCell) finishEditing();

        const cell = document.querySelector(
            `[data-row="${row}"][data-col="${col}"]`
        );
        if (!cell) return;

        const currentValue = csvData[row][col] || "";
        cell.classList.add("editing");
        cell.innerHTML = `<input type="text">`;

        const input = cell.querySelector("input");
        input.value = currentValue;
        input.focus();
        input.select();

        editingCell = { row, col, cell, input };

        input.addEventListener("blur", finishEditing);
        input.addEventListener("keydown", handleEditKeyDown);
    }

    function editHeader(colIndex) {
        const headerCell = document.querySelector(
            `.column-header:nth-child(${colIndex + 2}) .cell`
        );
        const currentValue = headers[colIndex];

        headerCell.innerHTML = `<input type="text" value="${currentValue}">`;
        const input = headerCell.querySelector("input");
        input.focus();
        input.select();

        input.addEventListener("blur", () => saveHeader(colIndex, input));
        input.addEventListener("keydown", (e) => {
            if (e.key === "Enter") {
                saveHeader(colIndex, input);
            } else if (e.key === "Escape") {
                headerCell.textContent = currentValue;
            }
        });
    }

    function saveHeader(colIndex, input) {
        headers[colIndex] = input.value;
        input.parentElement.textContent = input.value;
    }

    function handleEditKeyDown(event) {
        if (event.key === "Enter") {
            finishEditing();
            navigateCell("down");
        } else if (event.key === "Tab") {
            event.preventDefault();
            finishEditing();
            navigateCell(event.shiftKey ? "left" : "right");
        } else if (event.key === "Escape") {
            cancelEditing();
        }
    }

    function finishEditing() {
        if (!editingCell) return;

        const { row, col, cell, input } = editingCell;
        const newValue = input.value;

        if (!csvData[row]) csvData[row] = [];
        csvData[row][col] = newValue;

        cell.classList.remove("editing");
        cell.textContent = newValue;

        editingCell = null;
        updateStats();
    }

    function cancelEditing() {
        if (!editingCell) return;

        const { row, col, cell } = editingCell;
        const originalValue = csvData[row][col] || "";

        cell.classList.remove("editing");
        cell.textContent = originalValue;

        editingCell = null;
    }

    function navigateCell(direction) {
        if (!activeCell) return;

        let { row, col } = activeCell;

        switch (direction) {
            case "up":
                row = Math.max(0, row - 1);
                break;
            case "down":
                row = Math.min(csvData.length - 1, row + 1);
                break;
            case "left":
                col = Math.max(0, col - 1);
                break;
            case "right":
                col = Math.min(headers.length - 1, col + 1);
                break;
        }

        clearSelection();
        setActiveCell(row, col);
        selectCell(row, col);
    }

    function copySelection() {
        const data = getSelectedData();
        if (!data || data.length === 0) return;

        copiedData = data;
        const clipboardText = data.map((row) => row.join("\t")).join("\n");

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard
                .writeText(clipboardText)
                .then(() => showToast("Copied to clipboard!"))
                .catch(() => {
                    // Fallback to execCommand
                    const textarea = document.createElement("textarea");
                    textarea.value = clipboardText;
                    document.body.appendChild(textarea);
                    textarea.select();
                    try {
                        document.execCommand("copy");
                        showToast("Copied to clipboard (fallback)!");
                    } catch (err) {
                        showToast("Copy failed!");
                    }
                    document.body.removeChild(textarea);
                });
        } else {
            // Fallback to execCommand
            const textarea = document.createElement("textarea");
            textarea.value = clipboardText;
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand("copy");
                showToast("Copied to clipboard !");
            } catch (err) {
                showToast("Copy failed!");
            }
            document.body.removeChild(textarea);
        }
    }

    function getSelectedData() {
        let data = [];

        if (selectedRows.size > 0) {
            // Copy entire rows
            const sortedRows = Array.from(selectedRows).sort((a, b) => a - b);
            data = sortedRows.map((row) => [...csvData[row]]);
        } else if (selectedColumns.size > 0) {
            // Copy entire columns
            const sortedCols = Array.from(selectedColumns).sort(
                (a, b) => a - b
            );
            data = csvData.map((row) =>
                sortedCols.map((col) => row[col] || "")
            );
        } else if (selectedCells.size > 0) {
            // Copy selected cells
            const cells = Array.from(selectedCells).map((key) => {
                const [row, col] = key.split(",").map(Number);
                return { row, col, value: csvData[row][col] || "" };
            });

            const minRow = Math.min(...cells.map((c) => c.row));
            const maxRow = Math.max(...cells.map((c) => c.row));
            const minCol = Math.min(...cells.map((c) => c.col));
            const maxCol = Math.max(...cells.map((c) => c.col));

            for (let row = minRow; row <= maxRow; row++) {
                const rowData = [];
                for (let col = minCol; col <= maxCol; col++) {
                    const cell = cells.find(
                        (c) => c.row === row && c.col === col
                    );
                    rowData.push(cell ? cell.value : "");
                }
                data.push(rowData);
            }
        }

        return data;
    }

    function undoLastAction() {
        if (historyStack.length === 0) return;
        const previousState = historyStack.pop();
        csvData = JSON.parse(JSON.stringify(previousState));
        renderTable();
    }

    document.addEventListener("keydown", function (e) {
        const isUndo =
            (e.ctrlKey || e.metaKey) &&
            e.key.toLowerCase() === "z" &&
            !e.shiftKey;

        if (isUndo) {
            e.preventDefault();
            undoLastAction();
        }
    });

    async function pasteFromClipboard() {
        if (navigator.clipboard && navigator.clipboard.readText) {
            try {
                const clipboardText = await navigator.clipboard.readText();

                console.log("clipboardText,clipboardText");
                if (clipboardText) {
                    historyStack.push(JSON.parse(JSON.stringify(csvData)));
                    pasteData(parseClipboardData(clipboardText));
                    return;
                }
            } catch (error) {
                console.error("Clipboard read failed:", error);
            }
        } else {
            console.warn("Clipboard API not supported or insecure context");
        }

        // Fallback to internal copiedData
        if (copiedData) {
            pasteData(copiedData);
        } else {
            showToast("Nothing to paste!");
        }
    }

    function parseClipboardData(text) {
        const lines = text.trim().split("\n");
        return lines.map((line) => {
            if (line.includes("\t")) {
                return line.split("\t");
            } else {
                return line.split(",").map((cell) => cell.trim());
            }
        });
    }

    function pasteData(data) {
        if (!data || data.length === 0) return;

        let startRow = 0;
        let startCol = 0;

        if (activeCell) {
            startRow = activeCell.row;
            startCol = activeCell.col;
        } else if (selectedCells.size > 0) {
            const cells = Array.from(selectedCells).map((key) => {
                const [row, col] = key.split(",").map(Number);
                return { row, col };
            });
            startRow = Math.min(...cells.map((c) => c.row));
            startCol = Math.min(...cells.map((c) => c.col));
        }

        // Ensure we have enough rows
        const requiredRows = startRow + data.length;
        while (csvData.length < requiredRows) {
            csvData.push(new Array(headers.length).fill(""));
        }

        // Ensure we have enough columns
        const maxCols = Math.max(...data.map((row) => row.length));
        const requiredCols = startCol + maxCols;
        if (headers.length < requiredCols) {
            while (headers.length < requiredCols) {
                headers.push(`Column ${headers.length + 1}`);
            }
            csvData.forEach((row) => {
                while (row.length < headers.length) {
                    row.push("");
                }
            });
        }

        // Paste the data
        data.forEach((row, rowOffset) => {
            row.forEach((value, colOffset) => {
                const targetRow = startRow + rowOffset;
                const targetCol = startCol + colOffset;
                if (targetRow < csvData.length && targetCol < headers.length) {
                    csvData[targetRow][targetCol] = value;
                }
            });
        });

        renderTable();
        showToast(`Pasted ${data.length} row!`);
    }

    function addRow() {
        csvData.splice(0, 0, new Array(headers.length).fill(""));
        renderTable();
    }

    function deleteSelected() {
        if (selectedRows.size > 0) {
            const sortedRows = Array.from(selectedRows).sort((a, b) => b - a);
            sortedRows.forEach((row) => csvData.splice(row, 1));
        } else if (selectedColumns.size > 0) {
            const sortedCols = Array.from(selectedColumns).sort(
                (a, b) => b - a
            );
            sortedCols.forEach((col) => {
                headers.splice(col, 1);
                csvData.forEach((row) => row.splice(col, 1));
            });
        } else if (selectedCells.size > 0) {
            selectedCells.forEach((key) => {
                const [row, col] = key.split(",").map(Number);
                csvData[row][col] = "";
            });
        }
        renderTable();
    }

    // function downloadCSV() {
    //     // const csvContent = [headers, ...csvData];
    //     const trimmedData = csvData.map((row) =>
    //         row.map((cell) => String(cell).trim())
    //     );

    //     const trimmedHeaders = headers.map((header) => String(header).trim());

    //     const csvContent = [trimmedHeaders, ...trimmedData];

    //     const csv = Papa.unparse(csvContent);
    //     const blob = new Blob([csv], { type: "text/csv" });
    //     const url = window.URL.createObjectURL(blob);
    //     const a = document.createElement("a");
    //     a.href = url;
    //     a.download = "data.csv";
    //     a.click();
    //     window.URL.revokeObjectURL(url);
    // }

    const escapeCSV = (value) => {
        const str = String(value).trim();

        // If cell contains a comma or newline, wrap in quotes, but don't escape quotes
        if (/[,\n]/.test(str)) {
            return `"${str}"`; // keep inner quotes as-is
        }

        return str;
    };

    function downloadCSV() {

        const rows = [headers, ...csvData];

        const csv = rows
            .map((row) => row.map((cell) => escapeCSV(cell)).join(","))
            .join("\n");

        const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "data.csv";
        a.click();
        window.URL.revokeObjectURL(url);
    }

    function saveCSV({ languageId, code, title, localizedTitle }) {
        const trimmedData = csvData.map((row) =>
            row.map((cell) => String(cell).trim())
        );

        const trimmedHeaders = headers.map((header) => String(header).trim());

        const csvContent = [trimmedHeaders, ...trimmedData];
        const csv = csvContent
            .map((row) => row.map((cell) => escapeCSV(cell)).join(","))
            .join("\n");

        const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
        const file = new File([blob], "data.csv", { type: "text/csv" });

        const formData = new FormData();
        formData.append("csv_file", file);
        formData.append("language_id", languageId);
        formData.append("code", code);
        formData.append("title", title);
        formData.append("localized_title", localizedTitle);

        $.ajax({
            url: `${domainUrl}updateLanguage`,
            type: "POST",
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                console.log("File uploaded successfully", response);
                showSuccessToast(response.message);
                hideFormSpinner("#saveFile");
            },
            error: function (xhr, status, error) {
                console.error("File upload failed", error);
                hideFormSpinner("#saveFile");

            },
        });
    }

    function updateStats() {
        const statsText = document.getElementById("statsText");
        let text = `${csvData.length} rows, ${headers.length} columns`;

        const totalSelected =
            selectedCells.size +
            selectedRows.size * headers.length +
            selectedColumns.size * csvData.length;

        if (totalSelected > 0) {
            text += ` (${totalSelected} cells selected)`;
        }

        statsText.textContent = text;
    }

    function showToast(message) {
        showSuccessToast(message);
        // const existingToast = document.querySelector(".toast");
        // if (existingToast) existingToast.remove();

        // const toast = document.createElement("div");
        // toast.className = "toast";
        // toast.textContent = message;
        // document.body.appendChild(toast);

        // setTimeout(() => toast.classList.add("show"), 10);
        // setTimeout(() => {
        //     toast.classList.remove("show");
        //     setTimeout(() => toast.remove(), 300);
        // }, 3000);
    }

    // Keyboard shortcuts
    document.addEventListener("keydown", function (e) {
        if (editingCell) return;

        if (e.ctrlKey || e.metaKey) {
            switch (e.key) {
                case "c":
                    e.preventDefault();
                    copySelection();
                    break;
                case "v":
                    e.preventDefault();
                    pasteFromClipboard();
                    break;
                case "a":
                    e.preventDefault();
                    selectAll();
                    break;
            }
        } else {
            switch (e.key) {
                case "Delete":
                case "Backspace":
                    deleteSelected();
                    break;
                case "ArrowUp":
                    e.preventDefault();
                    navigateCell("up");
                    break;
                case "ArrowDown":
                    e.preventDefault();
                    navigateCell("down");
                    break;
                case "ArrowLeft":
                    e.preventDefault();
                    navigateCell("left");
                    break;
                case "ArrowRight":
                    e.preventDefault();
                    navigateCell("right");
                    break;
                case "Enter":
                    if (activeCell) {
                        editCell(activeCell.row, activeCell.col);
                    }
                    break;
                case "F2":
                    if (activeCell) {
                        editCell(activeCell.row, activeCell.col);
                    }
                    break;
            }
        }
    });

    // Global click handler
    document.addEventListener("click", function (e) {
        if (editingCell && !editingCell.cell.contains(e.target)) {
            finishEditing();
        }
    });
});
