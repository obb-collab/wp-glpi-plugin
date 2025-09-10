/* Minimal shim: ensure clicks on cards have data-ticket-id attribute for the new modal to pick up.
   We do not open modal here (handled in newmodal/newmodal.js); we only normalize attributes. */
(function bageShim() {
  // Find anchors that still hold ticket id in href and mirror to data-ticket-id
  const root = document.querySelector('.gexe-bage-scope');
  if (!root) return;
  const links = root.querySelectorAll('a[href*="#ticket-"], a[href*="ticket_id="]');
  links.forEach((a) => {
    if (a.hasAttribute('data-ticket-id')) return;
    const href = a.getAttribute('href') || '';
    let id = null;
    const m1 = href.match(/#ticket-(\d+)/i);
    if (m1) [, id] = m1;
    const m2 = href.match(/[?&]ticket_id=(\d+)/i);
    if (!id && m2) [, id] = m2;
    if (id) {
      a.setAttribute('data-ticket-id', id);
    }
  });
}());
