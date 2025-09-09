<button type="button" class="nt-open-modal">Новая заявка</button>
<div class="nt-wrap" aria-hidden="true" role="dialog" aria-modal="true">
  <div class="nt-modal">
    <button type="button" class="nt-close-modal" aria-label="Закрыть">×</button>
    <h3>Новая заявка</h3>
    <form class="nt-form" novalidate>
      <label>Тема
        <input type="text" name="title" required minlength="3" maxlength="255" />
      </label>
      <label>Описание
        <textarea name="content" required minlength="3" maxlength="65535"></textarea>
      </label>
      <div class="nt-inline">
        <label data-nt-field="category">Категория
          <select name="category_id"></select>
          <div class="nt-error" data-nt-error="category" hidden></div>
        </label>
        <label data-nt-field="location">Местоположение
          <select name="location_id"></select>
          <div class="nt-error" data-nt-error="location" hidden></div>
        </label>
      </div>
      <label><input type="checkbox" name="self_assign" class="nt-self" /> Назначить меня</label>
      <label data-nt-field="assignee">Исполнитель
        <select name="assignee_id"></select>
        <div class="nt-error" data-nt-error="assignee" hidden></div>
      </label>
      <button type="submit" class="nt-submit">Создать</button>
      <div class="nt-submit-error"></div>
      <div class="nt-success nt-submit-success"></div>
    </form>
  </div>
</div>
