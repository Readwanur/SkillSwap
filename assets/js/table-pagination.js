document.addEventListener('DOMContentLoaded', () => {
    const tables = document.querySelectorAll('table');
    const rowsPerPage = 5;

    tables.forEach((table, index) => {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        // Skip tables with 5 or fewer rows
        if (rows.length <= rowsPerPage) return;

        let currentPage = 1;
        const totalPages = Math.ceil(rows.length / rowsPerPage);

        // Function to show/hide rows based on current page
        const renderTable = () => {
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;

            rows.forEach((row, i) => {
                row.style.display = (i >= start && i < end) ? '' : 'none';
            });
        };

        // Function to create pagination controls
        const renderPagination = () => {
            const wrapper = table.closest('.table-wrapper');
            const targetElement = wrapper ? wrapper : table;
            
            // Remove existing pagination if any
            const existingPagination = targetElement.nextElementSibling;
            if (existingPagination && existingPagination.classList.contains('pagination-container')) {
                existingPagination.remove();
            }

            const paginationDiv = document.createElement('div');
            paginationDiv.className = 'pagination-container';

            // Previous Button
            const prevBtn = document.createElement('button');
            prevBtn.className = 'btn-pagination';
            prevBtn.innerHTML = '&laquo; Prev';
            prevBtn.disabled = currentPage === 1;
            prevBtn.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    updateView();
                }
            });
            paginationDiv.appendChild(prevBtn);

            // Page Numbers
            const pageNumbersDiv = document.createElement('div');
            pageNumbersDiv.className = 'pagination-numbers';
            
            // Limit page numbers shown to avoid overcrowding
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }

            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.className = `btn-pagination-num ${i === currentPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.addEventListener('click', () => {
                    currentPage = i;
                    updateView();
                });
                pageNumbersDiv.appendChild(pageBtn);
            }
            paginationDiv.appendChild(pageNumbersDiv);

            // Next Button
            const nextBtn = document.createElement('button');
            nextBtn.className = 'btn-pagination';
            nextBtn.innerHTML = 'Next &raquo;';
            nextBtn.disabled = currentPage === totalPages;
            nextBtn.addEventListener('click', () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    updateView();
                }
            });
            paginationDiv.appendChild(nextBtn);

            // Insert pagination
            targetElement.parentNode.insertBefore(paginationDiv, targetElement.nextSibling);
        };

        const updateView = () => {
            renderTable();
            renderPagination();
        };

        // Initial render
        updateView();
    });
});
