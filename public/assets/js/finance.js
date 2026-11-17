(() => {
    const form = document.querySelector("[data-finance-budget-form]");
    const lines = form?.querySelector("[data-budget-lines]");
    const template = form?.querySelector("[data-budget-line-template]");
    const addButton = form?.querySelector("[data-budget-add]");

    if (!form || !lines || !template || !addButton) {
        return;
    }

    let nextIndex = Array.from(lines.querySelectorAll("[data-budget-line]"))
        .reduce((highest, line) => {
            const match = line.querySelector("[name]")?.name.match(/^lines\[(\d+)\]/);
            return match ? Math.max(highest, Number(match[1]) + 1) : highest;
        }, 0);

    addButton.addEventListener("click", () => {
        if (lines.querySelectorAll("[data-budget-line]").length >= 30) {
            addButton.disabled = true;
            return;
        }

        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll("[name]").forEach((field) => {
            field.name = field.name.replaceAll("__INDEX__", String(nextIndex));
        });
        nextIndex += 1;
        lines.append(fragment);
        lines.querySelector("[data-budget-line]:last-child select")?.focus();
        updateAddButton();
    });

    lines.addEventListener("click", (event) => {
        const button = event.target.closest("[data-budget-remove]");
        if (!button) {
            return;
        }
        const row = button.closest("[data-budget-line]");
        if (row && lines.querySelectorAll("[data-budget-line]").length > 1) {
            row.remove();
        }
        updateAddButton();
    });

    function updateAddButton() {
        addButton.disabled = lines.querySelectorAll("[data-budget-line]").length >= 30;
    }

    updateAddButton();
})();
