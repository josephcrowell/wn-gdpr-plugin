// Snowboard emits a native ajaxDone event after a successful request and partial updates.
// Capture it because the event does not bubble. No jQuery/framework.js is required.
document.addEventListener('ajaxDone', function (event) {
    var trigger = event.target;
    if (!(trigger instanceof Element) || !trigger.hasAttribute('data-gdpr-banner-dismiss')) return;

    var banner = trigger.closest('.gdpr-banner');
    if (banner) banner.remove();
}, true);
