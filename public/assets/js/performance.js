(() => {
    const chartCanvases = document.querySelectorAll("[data-performance-chart]");
    if (chartCanvases.length === 0) {
        return;
    }
    if (typeof window.Chart !== "function") {
        console.error("Chart.js is unavailable; performance charts could not be initialized.");
        chartCanvases.forEach((canvas) => {
            const fallback = document.createElement("p");
            fallback.className = "performance-chart-error";
            fallback.textContent = "Le graphique ne peut pas être chargé. Rechargez la page ou contactez l’administrateur.";
            canvas.replaceWith(fallback);
        });
        return;
    }

    chartCanvases.forEach((canvas) => {
        try {
            const data = JSON.parse(canvas.dataset.chart || "{}");
            const isEggChart = canvas.dataset.performanceChart === "eggs";
            const values = isEggChart ? data.rates : data.weights;
            const actualLabel = isEggChart ? "Œufs / sujet vivant" : "Poids moyen";
            const unit = isEggChart ? "%" : " g/tête";
            const datasets = [{
                label: actualLabel,
                data: values,
                borderColor: "#32885e",
                backgroundColor: "rgba(50, 136, 94, .12)",
                pointBackgroundColor: "#287451",
                pointRadius: 3,
                pointHoverRadius: 5,
                borderWidth: 2,
                tension: .2,
                spanGaps: false,
                fill: true
            }];
            if (data.targets.some((value) => value !== null)) {
                datasets.push({
                    label: "Objectif documenté",
                    data: data.targets,
                    borderColor: "#a76a19",
                    backgroundColor: "transparent",
                    pointRadius: 2,
                    borderWidth: 1.5,
                    borderDash: [5, 4],
                    tension: 0,
                    spanGaps: false,
                    fill: false
                });
            }
            new window.Chart(canvas, {
                type: "line",
                data: { labels: data.labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: "index", intersect: false },
                    plugins: {
                        legend: { display: true, position: "bottom", labels: { usePointStyle: true, boxWidth: 7 } },
                        tooltip: {
                            callbacks: {
                                label(context) {
                                    const value = context.parsed.y;
                                    return `${context.dataset.label}: ${value === null ? "—" : `${Number(value).toLocaleString("fr-FR", { maximumFractionDigits: 2 })}${unit}`}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
                        },
                        y: {
                            beginAtZero: isEggChart,
                            title: { display: true, text: unit.trim() },
                            ticks: {
                                callback(value) {
                                    return Number(value).toLocaleString("fr-FR", { maximumFractionDigits: 1 });
                                }
                            }
                        }
                    }
                }
            });
        } catch (error) {
            console.error("Could not render a performance chart.", error);
            const fallback = document.createElement("p");
            fallback.className = "performance-chart-error";
            fallback.textContent = "Les données du graphique sont invalides ; les tableaux restent disponibles.";
            canvas.replaceWith(fallback);
        }
    });
})();
