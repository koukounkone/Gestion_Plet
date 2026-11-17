(() => {
    const toggle = document.querySelector("[data-menu-toggle]");
    const sidebar = document.querySelector("#sidebar");
    const backdrop = document.querySelector("[data-menu-close]");
    const loadingToggle = document.querySelector("[data-loading-toggle]");
    const demoContent = document.querySelector("#demo-content");
    const loadingPreview = document.querySelector("#loading-preview");
    const mobileQuery = window.matchMedia("(max-width: 760px)");
    let returnFocusTo = null;

    document.addEventListener("error", (event) => {
        const image = event.target;
        if (!(image instanceof HTMLImageElement)
            || !image.dataset.fallbackSrc
            || image.dataset.fallbackUsed === "true") {
            return;
        }

        image.dataset.fallbackUsed = "true";
        image.src = image.dataset.fallbackSrc;
    }, true);

    if (toggle && sidebar && backdrop) {
        const setMenuOpen = (open) => {
            const isMobile = mobileQuery.matches;
            document.body.classList.toggle("menu-open", open && isMobile);
            toggle.setAttribute("aria-expanded", String(open && isMobile));
            toggle.setAttribute("aria-label", open && isMobile ? "Fermer le menu" : "Ouvrir le menu");
            sidebar.setAttribute("aria-hidden", String(isMobile && !open));

            if (open && isMobile) {
                returnFocusTo = toggle;
                const firstLink = sidebar.querySelector("a[href]");
                if (firstLink) {
                    firstLink.focus();
                }
            } else if (!open && returnFocusTo && isMobile) {
                returnFocusTo.focus();
            }
        };

        toggle.addEventListener("click", () => {
            setMenuOpen(toggle.getAttribute("aria-expanded") !== "true");
        });
        backdrop.addEventListener("click", () => setMenuOpen(false));
        sidebar.querySelectorAll("a[href]").forEach((link) => {
            link.addEventListener("click", () => {
                if (mobileQuery.matches) {
                    setMenuOpen(false);
                }
            });
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape" && document.body.classList.contains("menu-open")) {
                setMenuOpen(false);
            }
        });
        const handleViewportChange = () => {
            setMenuOpen(false);
        };
        if (mobileQuery.addEventListener) {
            mobileQuery.addEventListener("change", handleViewportChange);
        } else {
            mobileQuery.addListener(handleViewportChange);
        }
        setMenuOpen(false);
    }

    if (loadingToggle && demoContent && loadingPreview) {
        loadingToggle.addEventListener("click", () => {
            const showLoading = loadingToggle.getAttribute("aria-pressed") !== "true";
            loadingToggle.setAttribute("aria-pressed", String(showLoading));
            loadingToggle.textContent = showLoading
                ? "Revenir à l’aperçu"
                : "Voir l’état de chargement";
            demoContent.hidden = showLoading;
            loadingPreview.hidden = !showLoading;
        });
    }

    document.querySelectorAll("form[data-confirm]").forEach((form) => {
        form.addEventListener("submit", (event) => {
            if (!window.confirm(form.dataset.confirm || "Confirmer cette opération ?")) {
                event.preventDefault();
            }
        });
    });
})();
