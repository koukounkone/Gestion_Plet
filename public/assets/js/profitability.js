(() => {
    const formatAmount = (amount) => `${Number(amount).toLocaleString('fr-FR')} FCFA`;
    const showFallback = (canvas) => {
        const message = document.createElement('p');
        message.className = 'profitability-chart-fallback';
        message.setAttribute('role', 'status');
        message.textContent = 'Graphique indisponible. Consultez les tableaux comparatifs ci-dessous.';
        canvas.replaceWith(message);
    };

    document.querySelectorAll('[data-profitability-chart]').forEach((canvas) => {
        if (typeof window.Chart !== 'function') {
            showFallback(canvas);
            return;
        }

        try {
            const data = JSON.parse(canvas.dataset.chart || '{}');
            if (!Array.isArray(data.labels) || !Array.isArray(data.values)
                || data.labels.length !== data.values.length) {
                throw new Error('Données de graphique invalides.');
            }

            const colors = data.values.map((value, index) => {
                if (Number(value) < 0) return '#b45349';
                return ['#32885e', '#4e719d', '#a76a19'][index % 3];
            });
            new window.Chart(canvas, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.values,
                        backgroundColor: colors,
                        borderRadius: 5,
                        maxBarThickness: 48,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (context) => formatAmount(context.parsed.y),
                            },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => Number(value).toLocaleString('fr-FR'),
                            },
                        },
                    },
                },
            });
        } catch (error) {
            console.error('Impossible de créer le graphique de rentabilité.', error);
            showFallback(canvas);
        }
    });
})();
