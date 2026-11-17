(() => {
    document.querySelectorAll("[data-password-toggle]").forEach((button) => {
        const inputId = button.getAttribute("aria-controls");
        const password = inputId ? document.getElementById(inputId) : null;

        if (!(password instanceof HTMLInputElement)) {
            return;
        }

        button.addEventListener("click", () => {
            const showPassword = password.type === "password";
            password.type = showPassword ? "text" : "password";
            button.setAttribute("aria-pressed", String(showPassword));
            button.setAttribute(
                "aria-label",
                showPassword ? "Masquer le mot de passe" : "Afficher le mot de passe",
            );
        });
    });
})();
