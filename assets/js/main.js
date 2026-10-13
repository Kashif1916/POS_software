document.addEventListener('DOMContentLoaded', () => {
    // Confirmation dialogs
    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('click', (event) => {
            if (!window.confirm(el.dataset.confirm || 'Are you sure you want to perform this action?')) {
                event.preventDefault();
            }
        });
    });

    // Instant table live filtering
    const searchInputs = document.querySelectorAll('[data-table-search]');
    searchInputs.forEach((input) => {
        input.addEventListener('input', () => {
            const query = input.value.toLowerCase().trim();
            const targetSelector = input.dataset.tableSearch || '[data-search-row]';
            document.querySelectorAll(targetSelector).forEach((row) => {
                const text = row.textContent.toLowerCase();
                row.hidden = query.length > 0 && !text.includes(query);
            });
        });
    });

    // Global modal openers
    document.querySelectorAll('[data-modal-open]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetModalId = btn.dataset.modalOpen;
            const modal = document.getElementById(targetModalId);
            if (modal) {
                modal.classList.add('active');
            }
        });
    });

    // Global modal closers
    document.querySelectorAll('[data-modal-close]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal-overlay');
            if (modal) {
                modal.classList.remove('active');
            }
        });
    });

    // Close modal on click outside
    document.querySelectorAll('.modal-overlay').forEach((modal) => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });
});
