(function () {
    function initialize(base) {
        var dialogs = Array.from(base.querySelectorAll('dialog.gdpr-bannerwide[open]'));
        if (base instanceof Element && base.matches('dialog.gdpr-bannerwide[open]')) dialogs.unshift(base);

        dialogs.forEach(function (dialog) {
            if (typeof dialog.showModal !== 'function' || dialog.matches(':modal')) return;
            dialog.removeAttribute('open');
            dialog.showModal();
            dialog.addEventListener('close', function () {
                dialog.remove();
            }, { once: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initialize(document); }, { once: true });
    } else {
        initialize(document);
    }

    // Native Snowboard partial updates may insert another banner after page load.
    document.addEventListener('ajaxUpdate', function (event) { initialize(event.target); }, true);
})();
