(function () {
    const table = document.getElementById('sources');
    if (!table) return;

    const body = table.tBodies[0];
    const rows = Array.prototype.filter.call(body.rows, function (row) { return row.getAttribute('data-key'); });
    const filter = document.getElementById('filter');
    const nomatch = document.getElementById('nomatch');

    if (filter) {
        filter.addEventListener('input', function () {
            const needle = filter.value.trim().toLowerCase();
            let shown = 0;
            rows.forEach(function (row) {
                const hit = !needle || row.getAttribute('data-key').toLowerCase().indexOf(needle) !== -1;
                row.hidden = !hit;
                if (hit) shown++;
            });
            if (nomatch) nomatch.hidden = shown !== 0;
        });
    }

    // A cell exposes its raw value in data-v; without one the visible text is compared.
    function key(row, col) {
        const cell = row.cells[col];
        if (!cell) return '';
        const raw = cell.getAttribute('data-v');
        if (raw !== null) return parseFloat(raw) || 0;
        return cell.textContent.trim().toLowerCase();
    }

    const head = table.tHead.rows[0];
    Array.prototype.forEach.call(head.cells, function (th, col) {
        function sort() {
            const dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
            Array.prototype.forEach.call(head.cells, function (other) { other.removeAttribute('data-dir'); });
            th.setAttribute('data-dir', dir);

            const sign = dir === 'asc' ? 1 : -1;
            rows.slice().sort(function (a, b) {
                const x = key(a, col), y = key(b, col);
                if (x === y) return 0;
                return (x > y ? 1 : -1) * sign;
            }).forEach(function (row) { body.appendChild(row); });
        }

        th.addEventListener('click', sort);
        th.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sort(); }
        });
    });
})();
