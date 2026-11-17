(() => {
    const lines = document.querySelector("[data-purchase-lines]");
    const template = document.querySelector("#purchase-line-template");
    const addLine = document.querySelector("[data-add-purchase-line]");

    const renumberPurchaseLines = () => {
        lines?.querySelectorAll("[data-purchase-line]").forEach((line, index) => {
            line.querySelectorAll("[data-field]").forEach((field) => {
                field.name = `items[${index}][${field.dataset.field}]`;
            });
        });
    };

    const updatePurchaseLine = (line) => {
        const product = line.querySelector("[data-product-select]");
        const selected = product?.selectedOptions[0];
        const isEquipment = selected?.dataset.type === "equipment";
        const expiry = line.querySelector("[data-expiry-input]");
        const price = line.querySelector("[data-price-input]");
        if (expiry) {
            expiry.disabled = isEquipment;
            if (isEquipment) {
                expiry.value = "";
            }
        }
        if (price && selected?.dataset.price && price.value === "") {
            price.value = selected.dataset.price;
        }
    };

    if (lines && template && addLine) {
        renumberPurchaseLines();
        lines.querySelectorAll("[data-purchase-line]").forEach((line) => {
            updatePurchaseLine(line);
        });
        addLine.addEventListener("click", () => {
            if (lines.querySelectorAll("[data-purchase-line]").length >= 30) {
                addLine.disabled = true;
                return;
            }
            const fragment = template.content.cloneNode(true);
            const line = fragment.querySelector("[data-purchase-line]");
            lines.append(fragment);
            renumberPurchaseLines();
            const added = lines.lastElementChild;
            updatePurchaseLine(added);
            added.querySelector("select")?.focus();
        });
        lines.addEventListener("change", (event) => {
            if (event.target.matches("[data-product-select]")) {
                updatePurchaseLine(event.target.closest("[data-purchase-line]"));
            }
        });
        lines.addEventListener("click", (event) => {
            const button = event.target.closest("[data-remove-purchase-line]");
            if (!button) {
                return;
            }
            if (lines.querySelectorAll("[data-purchase-line]").length <= 1) {
                return;
            }
            button.closest("[data-purchase-line]").remove();
            renumberPurchaseLines();
            addLine.disabled = lines.querySelectorAll("[data-purchase-line]").length >= 30;
        });
    }

    const movementProduct = document.querySelector("[data-movement-product]");
    const movementType = document.querySelector("[data-movement-type]");
    const lotSelect = document.querySelector("[data-lot-select]");
    const flockSelect = document.querySelector("[data-flock-select]");
    const updateMovement = () => {
        if (!movementProduct || !movementType) {
            return;
        }
        const productOption = movementProduct.selectedOptions[0];
        const isEquipment = productOption?.dataset.type === "equipment";
        movementType.querySelectorAll("[data-equipment-only]").forEach((option) => {
            option.disabled = !isEquipment;
        });
        const selectedType = movementType.value;
        const requiresLot = selectedType === "equipment_issue" || selectedType === "equipment_return";
        const requiresFlock = selectedType === "consumption"
            || selectedType === "equipment_issue"
            || selectedType === "equipment_return";
        movementType.querySelectorAll("option").forEach((option) => {
            if (option.value === "consumption") {
                option.disabled = isEquipment;
            }
        });
        if (movementType.selectedOptions[0]?.disabled) {
            movementType.value = "loss";
        }
        if (lotSelect) {
            lotSelect.required = selectedType === "equipment_return";
            Array.from(lotSelect.options).forEach((option) => {
                if (option.dataset.productId) {
                    option.hidden = option.dataset.productId !== movementProduct.value;
                    option.disabled = option.hidden;
                }
            });
            if (lotSelect.selectedOptions[0]?.disabled) {
                lotSelect.value = "";
            }
            lotSelect.options[0].textContent = requiresLot
                ? "Choisir le lot"
                : "Allocation FEFO automatique";
        }
        if (flockSelect) {
            flockSelect.required = requiresFlock;
        }
    };
    movementProduct?.addEventListener("change", updateMovement);
    movementType?.addEventListener("change", updateMovement);
    updateMovement();

    const inventoryProduct = document.querySelector("[data-inventory-product]");
    const inventoryExpiry = document.querySelector('input[name="expires_on"]');
    const updateInventory = () => {
        if (!inventoryProduct || !inventoryExpiry) {
            return;
        }
        const equipment = inventoryProduct.selectedOptions[0]?.dataset.type === "equipment";
        inventoryExpiry.disabled = equipment;
        if (equipment) {
            inventoryExpiry.value = "";
        }
    };
    inventoryProduct?.addEventListener("change", updateInventory);
    updateInventory();

    const receiptProduct = document.querySelector("[data-receipt-product]");
    const receiptExpiry = document.querySelector("[data-receipt-expiry]");
    const updateReceipt = () => {
        if (!receiptProduct || !receiptExpiry) {
            return;
        }
        const equipment = receiptProduct.selectedOptions[0]?.dataset.type === "equipment";
        receiptExpiry.disabled = equipment;
        if (equipment) {
            receiptExpiry.value = "";
        }
    };
    receiptProduct?.addEventListener("change", updateReceipt);
    updateReceipt();
})();
