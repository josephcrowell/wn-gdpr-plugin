function initializeSorting () {
    if (typeof Sortable === 'undefined') return;

    var handle = document.querySelector('.drag-handle');
    var tbody = handle ? handle.closest('table.data tbody') : null;
    if (!tbody) return;

    Sortable.create(tbody, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: function (evt) {
            var inputs = evt.from.querySelectorAll('td>div.drag-handle>input');
            var form = document.createElement('form');
            form.style.display = 'none';

            inputs.forEach(function (input) {
                form.appendChild(input.cloneNode(true));
            });

            Snowboard.request(form, 'onReorderRelation', {
                complete: function () {
                    form.remove();
                }
            });
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSorting, { once: true });
} else {
    initializeSorting();
}
