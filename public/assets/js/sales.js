document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-sale-form]');
    const list = document.querySelector('[data-sale-lines]');
    const template = document.querySelector('#sale-line-template');
    const addButton = document.querySelector('[data-add-sale-line]');
    if (!form || !list || !(template instanceof HTMLTemplateElement) || !addButton) {
        return;
    }

    const refresh = () => {
        list.querySelectorAll('[data-sale-line]').forEach((line) => {
            const type = line.querySelector('[data-source-type]');
            const flock = line.querySelector('[data-flock-field]');
            const product = line.querySelector('[data-product-field]');
            if (!type || !flock || !product) {
                return;
            }
            const animalSale = type.value === 'animal';
            flock.hidden = !animalSale && !line.querySelector('[data-product-flock]');
            product.hidden = animalSale;
            const flockSelect = flock.querySelector('select');
            const productSelect = product.querySelector('select');
            const quantity = line.querySelector('[data-sale-key="quantity"]');
            if (flockSelect) {
                flockSelect.required = animalSale;
                if (!animalSale) {
                    flockSelect.value = '';
                }
            }
            if (quantity) {
                quantity.min = animalSale ? '1' : '0.001';
                quantity.step = animalSale ? '1' : '0.001';
            }
            if (productSelect) {
                productSelect.required = !animalSale;
                if (animalSale) {
                    productSelect.value = '';
                }
            }
        });
        addButton.disabled = list.querySelectorAll('[data-sale-line]').length >= 30;
    };

    addButton.addEventListener('click', () => {
        if (list.querySelectorAll('[data-sale-line]').length >= 30) {
            return;
        }
        const clone = template.content.cloneNode(true);
        const indexes = Array.from(list.querySelectorAll('[name^="lines["]'))
            .map((field) => field.name.match(/^lines\[(\d+)\]/))
            .filter(Boolean)
            .map((match) => Number(match[1]));
        const index = indexes.length ? Math.max(...indexes) + 1 : 0;
        clone.querySelectorAll('[data-sale-key]').forEach((field) => {
            field.name = `lines[${index}][${field.dataset.saleKey}]`;
        });
        list.append(clone);
        refresh();
    });
    list.addEventListener('change', (event) => {
        if (event.target instanceof HTMLElement && event.target.matches('[data-source-type]')) {
            refresh();
        }
    });
    list.addEventListener('click', (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-remove-sale-line]')
            : null;
        if (button) {
            button.closest('[data-sale-line]')?.remove();
            refresh();
        }
    });
    refresh();
});
