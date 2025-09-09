<button type="button" class="nta-open-modal">Новая заявка (API)</button>
<div class="nta-wrap" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="nta-modal">
    <button type="button" class="nta-close-modal" aria-label="Закрыть">×</button>
    <h3>Новая заявка (через API)</h3>
    <form class="nta-form" novalidate>
      <label>Тема
        <input type="text" name="title" required minlength="3" maxlength="255" />
      </label>
      <label>Описание
        <textarea name="content" required minlength="3" maxlength="65535"></textarea>
      </label>
      <div class="nta-inline">
        <label data-nta-field="category">Категория
          <select name="category_id"></select>
          <div class="nta-error" data-nta-error="category" hidden></div>
        </label>
        <label data-nta-field="location">Местоположение
          <select name="location_id"></select>
          <div class="nta-error" data-nta-error="location" hidden></div>
        </label>
      </div>
      <label><input type="checkbox" name="self_assign" class="nta-self" /> Назначить меня</label>
      <label data-nta-field="assignee">Исполнитель
        <select name="assignee_id"></select>
        <div class="nta-error" data-nta-error="assignee" hidden></div>
      </label>
      <button type="submit" class="nta-submit">Создать (API)</button>
      <div class="nta-submit-error"></div>
      <div class="nta-success nta-submit-success"></div>
    </form>
  </div>
</div>
