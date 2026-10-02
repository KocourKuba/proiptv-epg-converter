(function () {
    var table = document.getElementById('sources');
    if (!table) return;

    var body = table.tBodies[0];
    var rows = Array.prototype.filter.call(body.rows, function (row) { return row.getAttribute('data-key'); });
    var filter = document.getElementById('filter');
    var nomatch = document.getElementById('nomatch');

    if (filter) {
        filter.addEventListener('input', function () {
            var needle = filter.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (row) {
                var hit = !needle || row.getAttribute('data-key').toLowerCase().indexOf(needle) !== -1;
                row.hidden = !hit;
                if (hit) shown++;
            });
            if (nomatch) nomatch.hidden = shown !== 0;
        });
    }

    // A cell exposes its raw value in data-v; without one the visible text is compared.
    function key(row, col) {
        var cell = row.cells[col];
        if (!cell) return '';
        var raw = cell.getAttribute('data-v');
        if (raw !== null) return parseFloat(raw) || 0;
        return cell.textContent.trim().toLowerCase();
    }

    var head = table.tHead.rows[0];
    Array.prototype.forEach.call(head.cells, function (th, col) {
        function sort() {
            var dir = th.getAttribute('data-dir') === 'asc' ? 'desc' : 'asc';
            Array.prototype.forEach.call(head.cells, function (other) { other.removeAttribute('data-dir'); });
            th.setAttribute('data-dir', dir);

            var sign = dir === 'asc' ? 1 : -1;
            rows.slice().sort(function (a, b) {
                var x = key(a, col), y = key(b, col);
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
