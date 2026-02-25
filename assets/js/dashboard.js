/**
 * Hummingbot Dashboard - Auto Refresh & Inline Editing
 */

(function () {
    'use strict';

    const script = document.currentScript;
    const refreshInterval = parseInt(script.getAttribute('data-refresh-interval'), 10) || 60;

    function updateTime() {
        const timeElement = document.getElementById('updateTime');
        if (timeElement) {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const year = now.getFullYear();

            timeElement.textContent = `${day}.${month}.${year} ${hours}:${minutes}:${seconds}`;
        }
    }

    function refreshDashboard() {
        // Skip refresh if a transfers input is currently focused
        if (document.activeElement && document.activeElement.classList.contains('transfers-input')) {
            return;
        }

        const container = document.querySelector('.container-fluid');
        if (container) {
            container.classList.add('loading');

            fetch(window.location.href)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    const newTable = doc.querySelector('.table');
                    const newInfo = doc.querySelector('.dashboard-info');
                    const newAlert = doc.querySelector('.alert');
                    const newTotalBox = doc.querySelector('.total-nav-box');

                    const currentTable = document.querySelector('.table');
                    const currentAlert = document.querySelector('.alert');
                    const currentInfo = document.querySelector('.dashboard-info');
                    const currentTotalBox = document.querySelector('.total-nav-box');

                    if (newTable && currentTable) {
                        currentTable.style.opacity = '0.5';
                        currentTable.style.transition = 'opacity 0.3s ease';

                        setTimeout(() => {
                            currentTable.outerHTML = newTable.outerHTML;
                            bindTransfersInputs();
                            const updatedTable = document.querySelector('.table');
                            if (updatedTable) {
                                updatedTable.style.opacity = '0.5';
                                updatedTable.style.transition = 'opacity 0.3s ease';
                                setTimeout(() => {
                                    updatedTable.style.opacity = '1';
                                }, 50);
                            }
                        }, 150);
                    }

                    if (newAlert && currentAlert) {
                        currentAlert.outerHTML = newAlert.outerHTML;
                    }

                    if (newInfo && currentInfo) {
                        currentInfo.innerHTML = newInfo.innerHTML;
                    }

                    if (newTotalBox && currentTotalBox) {
                        currentTotalBox.innerHTML = newTotalBox.innerHTML;
                    }

                    updateTime();
                    container.classList.remove('loading');
                })
                .catch(error => {
                    console.error('Error refreshing dashboard:', error);
                    container.classList.remove('loading');
                });
        }
    }

    /**
     * Save transfers value via AJAX
     */
    function saveTransfers(input) {
        const strategyId = input.dataset.strategyId;
        const value = parseFloat(input.value) || 0;

        input.style.borderColor = '#ffc107';

        fetch('api/transfers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: parseInt(strategyId, 10), transfers: value })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                input.style.borderColor = '#28a745';
                setTimeout(() => { input.style.borderColor = ''; }, 1500);
            } else {
                input.style.borderColor = '#dc3545';
                console.error('Save failed:', data.message);
            }
        })
        .catch(error => {
            input.style.borderColor = '#dc3545';
            console.error('Error saving transfers:', error);
        });
    }

    /**
     * Bind change/blur events to all transfers inputs
     */
    function bindTransfersInputs() {
        document.querySelectorAll('.transfers-input').forEach(input => {
            if (input.dataset.bound) return;
            input.dataset.bound = '1';

            input.addEventListener('change', function () {
                saveTransfers(this);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.blur();
                }
            });
        });
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function () {
        updateTime();
        setInterval(updateTime, 1000);
        setInterval(refreshDashboard, refreshInterval * 1000);
        bindTransfersInputs();
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            refreshDashboard();
        }
    });
})();
