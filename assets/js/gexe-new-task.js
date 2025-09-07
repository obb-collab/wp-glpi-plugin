(function () {
  if (typeof window === 'undefined') return;

  const ajax = window.glpiAjax || {};

  document.addEventListener('DOMContentLoaded', () => {
    const modal = document.querySelector('.glpi-create-modal');
    if (!modal) return;
    const submitBtn = modal.querySelector('.gnt-submit');
    if (!submitBtn) return;

    let busy = false;

    submitBtn.addEventListener('click', () => {
      if (busy) return;

      const subjectEl = modal.querySelector('#gnt-name');
      const contentEl = modal.querySelector('#gnt-content');
      const categoryEl = modal.querySelector('#gnt-category');
      const locationEl = modal.querySelector('#gnt-location');
      const assignMeEl = modal.querySelector('#gnt-assign-me');
      const assigneeEl = modal.querySelector('#gnt-assignee');

      const subject = subjectEl.value.trim();
      const description = contentEl.value.trim();
      const category = categoryEl ? parseInt(categoryEl.value, 10) || '' : '';
      const location = locationEl ? parseInt(locationEl.value, 10) || '' : '';
      const assignMe = assignMeEl ? assignMeEl.checked : false;
      const executor = assigneeEl ? parseInt(assigneeEl.value, 10) || 0 : 0;

      if (subject.length < 3 || subject.length > 255) {
        alert('Введите тему (3-255 символов)');
        return;
      }
      if (description.length > 5000) {
        alert('Описание слишком длинное');
        return;
      }

      const body = new URLSearchParams();
      body.append('action', 'gexe_create_ticket_api');
      body.append('nonce', ajax.nonce || '');
      body.append('subject', subject);
      body.append('description', description);
      if (category) body.append('category_id', category);
      if (location) body.append('location_id', location);
      if (assignMe) body.append('assign_me', '1');
      if (!assignMe && executor) body.append('executor_id', executor);

      busy = true;
      submitBtn.disabled = true;
      submitBtn.classList.add('is-loading');
      submitBtn.textContent = 'Создаю…';

      fetch(ajax.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      }).then((r) => r.json())
        .then((data) => {
          submitBtn.disabled = false;
          submitBtn.classList.remove('is-loading');
          submitBtn.textContent = 'Создать заявку';
          busy = false;

          if (data && data.success) {
            alert(`Заявка №${data.id} создана`);
            subjectEl.value = '';
            contentEl.value = '';
            if (assigneeEl) assigneeEl.value = '';
          } else {
            const msg = data && data.error && data.error.message ? data.error.message : 'Неизвестная ошибка';
            alert(`Ошибка API: ${msg}`);
          }
        })
        .catch((err) => {
          submitBtn.disabled = false;
          submitBtn.classList.remove('is-loading');
          submitBtn.textContent = 'Создать заявку';
          busy = false;
          alert(`Ошибка API: ${err.message}`);
        });

      setTimeout(() => { busy = false; }, 10000);
    });
  });
}());
